<?php
declare(strict_types=1);

/**
 * One chat turn of Agent Tenable, streamed to the browser as server-sent events.
 *
 * Events: status {text}, tool_call {id, name, arguments}, tool_result {id, name,
 * ok, ms, result}, delta {text}, thinking {text}, done {content, tools_used,
 * stats}, error {message}.
 *
 * The browser keeps the conversation and sends it back each turn (user and
 * assistant text only); the server adds the system prompt, runs the tool loop
 * and streams the final answer.
 */
final class Chat
{
    private const MAX_HISTORY = 20;        // messages kept from the browser
    private const MAX_MESSAGE_CHARS = 12000;
    private const MAX_CONTEXT_CHARS = 16000;
    private const MAX_TOOL_RESULT_CHARS = 24000;   // what the model sees per tool call

    public static function stream(array $in): void
    {
        @set_time_limit(0);
        ignore_user_abort(false);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        try {
            self::run($in);
        } catch (ApiError $e) {
            self::send('error', ['message' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('ai_chat: ' . get_class($e) . ': ' . $e->getMessage());
            self::send('error', ['message' => $e->getMessage()]);
        }
    }

    private static function run(array $in): void
    {
        $settings = Llm::settings();
        $history = self::history($in);
        if (!$history) {
            throw new ApiError('Write a question first.');
        }
        $skill = Knowledge::item('skills', preg_replace('/[^a-z0-9_-]/', '', (string) ($in['skill'] ?? '')));
        $tools = Knowledge::toolDefinitions();
        $messages = array_merge([['role' => 'system', 'content' => self::systemPrompt($skill, (string) ($in['context'] ?? ''))]], $history);

        $used = [];
        $rounds = (int) $settings['max_tool_rounds'];
        $final = null;
        for ($round = 0; $round <= $rounds; $round++) {
            $lastRound = $round === $rounds;
            self::send('status', ['text' => $round === 0 ? 'Thinking…' : 'Reading the results…']);
            $streamed = false;
            $reply = Llm::chat($settings, $messages, $lastRound ? [] : $tools, function (?string $text, ?string $thinking) use (&$streamed) {
                if ($text !== null) {
                    $streamed = true;
                    self::send('delta', ['text' => $text]);
                } elseif ($thinking !== null) {
                    self::send('thinking', ['text' => $thinking]);
                }
            });
            if (!$reply['tool_calls']) {
                $final = $reply;
                break;
            }
            // Text that came with the tool calls was streamed already; keep it in the history.
            $messages[] = ['role' => 'assistant', 'content' => $reply['content'], 'tool_calls' => $reply['tool_calls']];
            if ($streamed && $reply['content'] !== '') {
                self::send('delta', ['text' => "\n\n"]);
            }
            foreach ($reply['tool_calls'] as $i => $call) {
                $fn = $call['function'] ?? [];
                $name = (string) ($fn['name'] ?? '');
                $args = $fn['arguments'] ?? [];
                if (is_string($args)) {
                    $args = json_decode($args, true) ?: [];
                }
                $id = (string) ($call['id'] ?? ('call_' . $round . '_' . $i));
                self::send('tool_call', ['id' => $id, 'name' => $name, 'arguments' => $args]);
                $t0 = microtime(true);
                try {
                    $result = Tools::run($name, is_array($args) ? $args : []);
                    $ok = true;
                } catch (ApiError $e) {
                    $result = ['error' => $e->getMessage()];
                    $ok = false;
                } catch (Throwable $e) {
                    error_log("ai tool $name: " . get_class($e) . ': ' . $e->getMessage());
                    $result = ['error' => 'The tool failed: ' . $e->getMessage()];
                    $ok = false;
                }
                $ms = (int) round((microtime(true) - $t0) * 1000);
                $used[] = ['name' => $name, 'arguments' => $args, 'ok' => $ok, 'ms' => $ms];
                self::send('tool_result', ['id' => $id, 'name' => $name, 'ok' => $ok, 'ms' => $ms, 'result' => $result]);
                $messages[] = ['role' => 'tool', 'tool_name' => $name, 'content' => self::forModel($result)];
            }
        }
        if ($final === null) {
            // The model kept asking for tools; answer with what it has.
            self::send('status', ['text' => 'Writing the answer…']);
            $messages[] = ['role' => 'user', 'content' => 'Answer now from the tool results above; do not call more tools.'];
            $final = Llm::chat($settings, $messages, [], function (?string $text) {
                if ($text !== null) {
                    self::send('delta', ['text' => $text]);
                }
            });
        }
        self::send('done', ['content' => $final['content'], 'tools_used' => $used, 'stats' => $final['stats'],
                            'model' => $settings['model'], 'skill' => $skill['name'] ?? null]);
    }

    private static function systemPrompt(?array $skill, string $context): string
    {
        $agent = Knowledge::agent();
        $parts = [$agent['prompt'] ?? 'You are Agent Tenable, the Exposure Lens assistant. Use the tools to look data up before answering.'];
        if ($skill) {
            $parts[] = "## Active skill: {$skill['title']}\n\n{$skill['prompt']}";
        }
        $parts[] = 'Today is ' . date('Y-m-d') . '. The interface renders Markdown (headings, lists, bold, tables, code).';
        $context = trim($context);
        if ($context !== '') {
            $parts[] = "## Data the user is looking at\n\nThe user opened this record in Exposure Lens and asked for an analysis. Use it as given; call tools only for what it does not contain.\n\n"
                . mb_substr($context, 0, self::MAX_CONTEXT_CHARS);
        }
        return implode("\n\n", $parts);
    }

    /** The browser's messages, trimmed: only user and assistant text, the last MAX_HISTORY. */
    private static function history(array $in): array
    {
        $raw = $in['messages'] ?? '[]';
        $list = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $out = [];
        foreach (is_array($list) ? $list : [] as $m) {
            $role = $m['role'] ?? '';
            $content = trim((string) ($m['content'] ?? ''));
            if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => mb_substr($content, 0, self::MAX_MESSAGE_CHARS)];
        }
        $out = array_slice($out, -self::MAX_HISTORY);
        while ($out && $out[0]['role'] !== 'user') {
            array_shift($out);
        }
        return $out;
    }

    /** Tool result as the model sees it: JSON, cut at a row boundary when too long. */
    private static function forModel(array $result): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $json = json_encode($result, $flags);
        if (strlen($json) <= self::MAX_TOOL_RESULT_CHARS) {
            return $json;
        }
        foreach (['rows', 'members', 'access', 'local_accounts', 'services', 'ad_users'] as $key) {
            while (isset($result[$key]) && is_array($result[$key]) && count($result[$key]) > 5 && strlen(json_encode($result, $flags)) > self::MAX_TOOL_RESULT_CHARS) {
                array_splice($result[$key], (int) floor(count($result[$key]) / 2));
                $result['truncated_for_model'] = true;
            }
        }
        $json = json_encode($result, $flags);
        return strlen($json) > self::MAX_TOOL_RESULT_CHARS ? mb_strcut($json, 0, self::MAX_TOOL_RESULT_CHARS) . '…(cut)' : $json;
    }

    private static function send(string $event, array $data): void
    {
        echo 'event: ' . $event . "\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
        flush();
        if (connection_aborted()) {
            exit;
        }
    }
}
