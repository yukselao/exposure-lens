<?php
declare(strict_types=1);

/**
 * Ollama client (native /api/chat). The address and model come from the
 * `ai` settings section; nothing here is a constant that would need a deploy
 * to change.
 */
final class Llm
{
    public const DEFAULTS = [
        'url' => 'http://192.168.1.105:11434',
        'model' => 'gemma4:31b',
        'num_ctx' => 32768,
        'temperature' => 0.4,
        'num_predict' => 3000,
        'think' => false,
        'timeout_seconds' => 300,
        'max_tool_rounds' => 6,
    ];

    public static function settings(): array
    {
        $data = Db::setting('ai');
        $s = self::DEFAULTS;
        foreach ($s as $key => $default) {
            if (array_key_exists($key, $data)) {
                $s[$key] = is_bool($default) ? (bool) $data[$key] : (is_int($default) ? (int) $data[$key] : (is_float($default) ? (float) $data[$key] : (string) $data[$key]));
            }
        }
        $s['url'] = rtrim($s['url'], '/');
        return $s;
    }

    /** GET /api/tags and /api/version: the models the server offers. */
    public static function probe(string $url, int $timeout = 8): array
    {
        $url = rtrim($url, '/');
        $version = self::request('GET', "$url/api/version", null, $timeout);
        $tags = self::request('GET', "$url/api/tags", null, $timeout);
        $models = [];
        foreach ($tags['models'] ?? [] as $m) {
            $models[] = ['name' => (string) ($m['name'] ?? ''), 'size_gb' => round(((int) ($m['size'] ?? 0)) / 1e9, 1),
                         'capabilities' => array_values((array) ($m['capabilities'] ?? [])),
                         'context_length' => $m['details']['context_length'] ?? null,
                         'parameter_size' => $m['details']['parameter_size'] ?? null];
        }
        return ['version' => (string) ($version['version'] ?? ''), 'models' => $models];
    }

    /**
     * One chat turn. With $onDelta the answer streams: the callback receives
     * each content delta (and thinking deltas as a second argument). Returns the
     * final message: ['content' => ..., 'tool_calls' => [...], 'stats' => [...]].
     */
    public static function chat(array $settings, array $messages, array $tools, ?callable $onDelta = null): array
    {
        $body = [
            'model' => $settings['model'],
            'messages' => $messages,
            'stream' => $onDelta !== null,
            'think' => (bool) $settings['think'],
            'options' => ['num_ctx' => (int) $settings['num_ctx'], 'temperature' => (float) $settings['temperature'],
                          'num_predict' => (int) $settings['num_predict']],
            'keep_alive' => '30m',
        ];
        if ($tools) {
            $body['tools'] = $tools;
        }
        $url = $settings['url'] . '/api/chat';
        $timeout = (int) $settings['timeout_seconds'];

        if ($onDelta === null) {
            $r = self::request('POST', $url, $body, $timeout);
            return self::finalize($r['message'] ?? [], $r);
        }

        $ch = curl_init($url);
        $buffer = '';
        $content = '';
        $thinking = '';
        $toolCalls = [];
        $last = [];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/x-ndjson'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buffer, &$content, &$thinking, &$toolCalls, &$last, $onDelta): int {
                $buffer .= $chunk;
                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $nl));
                    $buffer = substr($buffer, $nl + 1);
                    if ($line === '') {
                        continue;
                    }
                    $event = json_decode($line, true);
                    if (!is_array($event)) {
                        continue;
                    }
                    if (isset($event['error'])) {
                        throw new RuntimeException('The model server answered: ' . $event['error']);
                    }
                    $msg = $event['message'] ?? [];
                    if (($msg['content'] ?? '') !== '') {
                        $content .= $msg['content'];
                        $onDelta((string) $msg['content'], null);
                    }
                    if (($msg['thinking'] ?? '') !== '') {
                        $thinking .= $msg['thinking'];
                        $onDelta(null, (string) $msg['thinking']);
                    }
                    foreach ($msg['tool_calls'] ?? [] as $call) {
                        $toolCalls[] = $call;
                    }
                    if (!empty($event['done'])) {
                        $last = $event;
                    }
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok === false) {
            throw new RuntimeException(self::describeCurlError($err, $settings['url']));
        }
        if ($status >= 400) {
            $detail = json_decode($buffer, true)['error'] ?? trim($buffer);
            throw new RuntimeException("The model server answered HTTP $status" . ($detail ? ": $detail" : '.'));
        }
        return self::finalize(['content' => $content, 'thinking' => $thinking, 'tool_calls' => $toolCalls], $last);
    }

    private static function finalize(array $message, array $event): array
    {
        return [
            'content' => (string) ($message['content'] ?? ''),
            'thinking' => (string) ($message['thinking'] ?? ''),
            'tool_calls' => array_values((array) ($message['tool_calls'] ?? [])),
            'stats' => ['prompt_tokens' => $event['prompt_eval_count'] ?? null, 'completion_tokens' => $event['eval_count'] ?? null,
                        'total_ms' => isset($event['total_duration']) ? (int) round($event['total_duration'] / 1e6) : null,
                        'load_ms' => isset($event['load_duration']) ? (int) round($event['load_duration'] / 1e6) : null],
        ];
    }

    private static function request(string $method, string $url, ?array $body, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException(self::describeCurlError($err, $url));
        }
        $data = json_decode((string) $raw, true);
        if ($status >= 400) {
            throw new RuntimeException("The model server answered HTTP $status" . (isset($data['error']) ? ': ' . $data['error'] : '.'));
        }
        if (!is_array($data)) {
            throw new RuntimeException('The model server did not answer with JSON.');
        }
        return $data;
    }

    private static function describeCurlError(string $err, string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        if (str_contains($err, 'timed out')) {
            return "No answer from $host in time ($err).";
        }
        return "Cannot reach $host: $err";
    }
}
