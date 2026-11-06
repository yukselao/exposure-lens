<?php
declare(strict_types=1);

/** Read-only access to the connector log files (JSON lines) mounted under LOG_DIR. */
final class Logs
{
    public const CONNECTORS = [
        'sc' => 'Security Center connector',
        'tie_postgres' => 'TIE to PostgreSQL connector',
        'tie' => 'TIE API connector',
    ];
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];
    private const TAIL_BYTES = 4 * 1024 * 1024;

    private static function dir(string $connector): string
    {
        if (!isset(self::CONNECTORS[$connector])) {
            throw new InvalidArgumentException('Unknown connector.');
        }
        return rtrim(getenv('LOG_DIR') ?: '/var/log/exposure-lens', '/') . '/' . $connector;
    }

    /** Current file first, then rotated files (connector.log.1 is the newest of those). */
    public static function files(string $connector): array
    {
        $base = self::dir($connector) . '/connector.log';
        $files = is_file($base) ? [$base] : [];
        for ($i = 1; $i <= 20; $i++) {
            if (is_file("$base.$i")) {
                $files[] = "$base.$i";
            }
        }
        return $files;
    }

    public static function info(string $connector): array
    {
        $files = self::files($connector);
        $size = 0;
        $mtime = null;
        foreach ($files as $file) {
            $size += (int) @filesize($file);
            $mtime = max($mtime ?? 0, (int) @filemtime($file));
        }
        return ['files' => count($files), 'bytes' => $size, 'modified_at' => $mtime ? date('c', $mtime) : null];
    }

    /**
     * The newest matching entries, newest first. `level` is a minimum level;
     * `q` is a case-insensitive substring of the raw line.
     */
    public static function tail(string $connector, int $limit, string $level, string $q, ?int $jobId): array
    {
        $limit = max(1, min(2000, $limit));
        $minLevel = self::LEVELS[strtolower($level)] ?? 0;
        $q = strtolower(trim($q));
        $entries = [];
        $scanned = 0;
        foreach (self::files($connector) as $file) {
            foreach (array_reverse(self::lastLines($file)) as $line) {
                $scanned++;
                if ($q !== '' && !str_contains(strtolower($line), $q)) {
                    continue;
                }
                $entry = json_decode($line, true);
                if (!is_array($entry)) {
                    $entry = ['timestamp' => null, 'level' => 'info', 'event' => $line];
                }
                $entryLevel = strtolower((string) ($entry['level'] ?? 'info'));
                if ((self::LEVELS[$entryLevel] ?? 1) < $minLevel) {
                    continue;
                }
                if ($jobId !== null && (int) ($entry['job_id'] ?? -1) !== $jobId) {
                    continue;
                }
                $entries[] = $entry;
                if (count($entries) >= $limit) {
                    break 2;
                }
            }
        }
        return ['entries' => $entries, 'scanned' => $scanned];
    }

    /** Lines from the last TAIL_BYTES of a file, oldest first. */
    private static function lastLines(string $file): array
    {
        $size = (int) @filesize($file);
        $handle = @fopen($file, 'rb');
        if (!$handle || $size === 0) {
            return [];
        }
        $offset = max(0, $size - self::TAIL_BYTES);
        fseek($handle, $offset);
        $data = (string) stream_get_contents($handle);
        fclose($handle);
        $lines = explode("\n", $data);
        if ($offset > 0) {
            array_shift($lines); // the first line is probably cut in half
        }
        return array_values(array_filter($lines, fn ($l) => $l !== ''));
    }

    /** Stream every log file of a connector as one download, oldest entries first. */
    public static function download(string $connector): void
    {
        $files = array_reverse(self::files($connector));
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $connector . '-' . date('Ymd-His') . '.log"');
        header('Cache-Control: no-store');
        foreach ($files as $file) {
            @readfile($file);
        }
    }
}
