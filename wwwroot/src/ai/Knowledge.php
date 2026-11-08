<?php
declare(strict_types=1);

/**
 * Agent Tenable's knowledge: Markdown files with a small front matter block,
 * read from the built-in directory (ai/, mounted read-only) and from the
 * active knowledge packs (data/ai-packs/<id>/). Packs win over built-ins
 * when a file has the same name, so a pack can refine a skill or a prompt set.
 *
 * Front matter is `key: value` lines between two `---` lines. A tool file also
 * carries its JSON-schema parameters in a fenced block marked `json parameters`.
 */
final class Knowledge
{
    public const KINDS = ['agents', 'skills', 'tools', 'prompts'];
    public const BUILTIN_DIR = '/var/lib/exposure-lens/ai';
    public const PACKS_DIR = '/var/lib/exposure-lens/ai-packs';
    private const MAX_FILE = 262144;          // 256 KB per knowledge file
    private const MAX_PACK_FILES = 400;
    private const MAX_PACK_UNPACKED = 64 * 1024 * 1024;

    private static ?array $cache = null;

    /** Every file of a kind: name => item. Pack files override built-ins. */
    public static function items(string $kind): array
    {
        return self::load()[$kind] ?? [];
    }

    public static function item(string $kind, string $name): ?array
    {
        return self::load()[$kind][$name] ?? null;
    }

    /** Skills ordered: default first, then by title. */
    public static function skills(): array
    {
        $skills = array_values(self::items('skills'));
        usort($skills, fn ($a, $b) => [($b['meta']['default'] ?? '') === 'true', $a['title']] <=> [($a['meta']['default'] ?? '') === 'true', $b['title']]);
        return $skills;
    }

    public static function agent(): ?array
    {
        $agents = self::items('agents');
        return $agents['agent-tenable'] ?? (reset($agents) ?: null);
    }

    /** Prompt sets: each file is a list of "- text" lines. */
    public static function prompts(): array
    {
        $sets = [];
        foreach (self::items('prompts') as $item) {
            preg_match_all('/^\s*[-*]\s+(.+)$/m', $item['body'], $m);
            $sets[] = ['name' => $item['name'], 'title' => $item['title'], 'description' => $item['meta']['description'] ?? '',
                       'icon' => $item['meta']['icon'] ?? '', 'source' => $item['source'],
                       'prompts' => array_map(fn ($p) => trim(preg_replace('/\*\*/', '', $p)), $m[1])];
        }
        usort($sets, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $sets;
    }

    /** Tool definitions in the shape the LLM API expects (only tools with parameters). */
    public static function toolDefinitions(): array
    {
        $defs = [];
        foreach (self::items('tools') as $item) {
            if (!isset($item['parameters'])) {
                continue;
            }
            $defs[] = ['type' => 'function', 'function' => [
                'name' => $item['name'],
                'description' => (string) ($item['meta']['description'] ?? ''),
                'parameters' => $item['parameters'],
            ]];
        }
        return $defs;
    }

    /** The tree the Settings page shows: kind => [files with source and size]. */
    public static function tree(): array
    {
        $tree = [];
        foreach (self::KINDS as $kind) {
            $tree[$kind] = array_values(array_map(fn ($i) => [
                'name' => $i['name'], 'title' => $i['title'], 'description' => $i['meta']['description'] ?? '',
                'source' => $i['source'], 'size' => $i['size'], 'has_parameters' => isset($i['parameters']),
            ], self::items($kind)));
        }
        return $tree;
    }

    /** Raw Markdown of one file, for the read-only viewer. */
    public static function raw(string $kind, string $name): ?array
    {
        $item = self::item($kind, $name);
        return $item ? ['kind' => $kind, 'name' => $name, 'title' => $item['title'], 'source' => $item['source'], 'markdown' => $item['raw']] : null;
    }

    /** Write a zip of the effective knowledge (built-ins plus active packs) to a temp file. */
    public static function zip(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'el-knowledge-');
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the zip file.');
        }
        $manifest = ['name' => 'Exposure Lens knowledge', 'exported_at' => date('c'), 'files' => []];
        foreach (self::KINDS as $kind) {
            $zip->addEmptyDir($kind);
            foreach (self::items($kind) as $item) {
                $zip->addFromString("$kind/{$item['name']}.md", $item['raw']);
                $manifest['files'][] = "$kind/{$item['name']}.md";
            }
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();
        return $file;
    }

    // ---- knowledge packs ------------------------------------------------------------------------

    public static function packs(): array
    {
        return Db::all('SELECT id, name, version, description, file_name, sha256, size_bytes, manifest, active, uploaded_at FROM ai_packs ORDER BY id');
    }

    /**
     * Import an uploaded zip: validate, unpack the Markdown files of the four
     * kinds into PACKS_DIR/<id>, record the pack (inactive). Anything that is
     * not a .md file under agents/, skills/, tools/ or prompts/ (or manifest.json)
     * is ignored.
     */
    public static function importPack(string $tmpFile, string $originalName): array
    {
        $size = (int) filesize($tmpFile);
        $sha = hash_file('sha256', $tmpFile);
        if (Db::value('SELECT id FROM ai_packs WHERE sha256 = ?', [$sha])) {
            throw new ApiError('This pack was already uploaded.');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmpFile) !== true) {
            throw new ApiError('The file is not a zip archive.');
        }
        $files = [];
        $unpacked = 0;
        $manifest = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $path = self::normalizePath((string) $stat['name']);
            if ($path === null || str_ends_with($path, '/')) {
                continue;
            }
            if ($path === 'manifest.json') {
                $manifest = json_decode((string) $zip->getFromIndex($i), true);
                continue;
            }
            if (!preg_match('#^(agents|skills|tools|prompts)/([a-z0-9][a-z0-9_-]{0,80})\.md$#', $path)) {
                continue;
            }
            if ((int) $stat['size'] > self::MAX_FILE) {
                throw new ApiError("$path is larger than 256 KB.");
            }
            $unpacked += (int) $stat['size'];
            if (count($files) >= self::MAX_PACK_FILES || $unpacked > self::MAX_PACK_UNPACKED) {
                throw new ApiError('The pack has too many files.');
            }
            $files[$path] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        if (!$files) {
            throw new ApiError('The zip holds no Markdown files under agents/, skills/, tools/ or prompts/.');
        }
        foreach ($files as $path => $content) {
            if (!mb_check_encoding($content, 'UTF-8')) {
                throw new ApiError("$path is not UTF-8 text.");
            }
        }
        $manifest = is_array($manifest) ? $manifest : [];
        $name = mb_substr(trim((string) ($manifest['name'] ?? pathinfo($originalName, PATHINFO_FILENAME))), 0, 200) ?: 'Knowledge pack';
        $counts = [];
        foreach (array_keys($files) as $path) {
            $kind = strtok($path, '/');
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }
        $id = (int) Db::value(
            'INSERT INTO ai_packs (name, version, description, file_name, sha256, size_bytes, manifest)
             VALUES (?, ?, ?, ?, ?, ?, CAST(? AS jsonb)) RETURNING id',
            [$name, mb_substr((string) ($manifest['version'] ?? ''), 0, 64) ?: null,
             mb_substr((string) ($manifest['description'] ?? ''), 0, 2000) ?: null,
             mb_substr(basename($originalName), 0, 255), $sha, $size,
             json_encode(['counts' => $counts, 'files' => array_keys($files)] + array_intersect_key($manifest, array_flip(['author', 'homepage'])), JSON_UNESCAPED_SLASHES)]
        );
        $dir = self::PACKS_DIR . '/' . $id;
        foreach ($files as $path => $content) {
            $target = "$dir/$path";
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
                throw new RuntimeException("Cannot create $target");
            }
            file_put_contents($target, $content);
        }
        self::$cache = null;
        return ['id' => $id, 'name' => $name, 'counts' => $counts];
    }

    public static function setPackActive(int $id, bool $active): void
    {
        if (Db::run('UPDATE ai_packs SET active = ? WHERE id = ?', [$active ? 't' : 'f', $id]) === 0) {
            throw new ApiError('This pack no longer exists.', 404);
        }
        self::$cache = null;
    }

    public static function deletePack(int $id): void
    {
        if (Db::run('DELETE FROM ai_packs WHERE id = ?', [$id]) === 0) {
            throw new ApiError('This pack no longer exists.', 404);
        }
        $dir = self::PACKS_DIR . '/' . $id;
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($dir);
        }
        self::$cache = null;
    }

    // ---- loading ---------------------------------------------------------------------------------

    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $all = array_fill_keys(self::KINDS, []);
        self::loadDir(self::BUILTIN_DIR, 'built-in', $all);
        try {
            $packs = Db::all('SELECT id, name FROM ai_packs WHERE active ORDER BY id');
        } catch (Throwable $e) {
            $packs = [];   // the table may not exist yet on a fresh database
        }
        foreach ($packs as $pack) {
            self::loadDir(self::PACKS_DIR . '/' . $pack['id'], 'pack: ' . $pack['name'], $all);
        }
        foreach ($all as &$items) {
            ksort($items);
        }
        return self::$cache = $all;
    }

    private static function loadDir(string $base, string $source, array &$all): void
    {
        foreach (self::KINDS as $kind) {
            foreach (glob("$base/$kind/*.md") ?: [] as $file) {
                $name = basename($file, '.md');
                if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,80}$/', $name) || filesize($file) > self::MAX_FILE) {
                    continue;
                }
                $all[$kind][$name] = self::parse($name, (string) file_get_contents($file), $source);
            }
        }
    }

    private static function parse(string $name, string $raw, string $source): array
    {
        $meta = [];
        $body = $raw;
        if (preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\z/s', $raw, $m)) {
            foreach (preg_split('/\r?\n/', $m[1]) as $line) {
                if (preg_match('/^([A-Za-z_][\w-]*):\s*(.*)$/', $line, $kv)) {
                    $meta[strtolower($kv[1])] = trim($kv[2], " \t\"'");
                }
            }
            $body = $m[2];
        }
        $item = ['name' => $name, 'title' => $meta['name'] ?? $name, 'meta' => $meta, 'body' => trim($body), 'raw' => $raw,
                 'source' => $source, 'size' => strlen($raw)];
        if (preg_match('/```json\s+parameters\s*\n(.*?)\n```/s', $body, $p)) {
            $schema = json_decode($p[1], true);
            if (is_array($schema) && ($schema['type'] ?? '') === 'object') {
                $item['parameters'] = $schema;
            }
            $item['body'] = trim(str_replace($p[0], '', $body));
        }
        // The text the model sees: everything above an "**Implementation**" note, which is for people.
        $item['prompt'] = trim(preg_replace('/\n\*\*Implementation[:\*].*\z/s', '', $item['body']));
        return $item;
    }

    private static function normalizePath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#^(\./)+#', '', $path);
        // Some zips carry a single top-level folder: strip it when the real tree starts below.
        if (preg_match('#^[^/]+/((?:agents|skills|tools|prompts)/.+|manifest\.json)$#', $path, $m)) {
            $path = $m[1];
        }
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            return null;
        }
        return $path;
    }
}
