<?php
declare(strict_types=1);

/** PostgreSQL access. Every query goes through prepared statements. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                getenv('PG_HOST') ?: 'postgres',
                getenv('PG_PORT') ?: '5432',
                getenv('PG_DB') ?: 'exposure_lens'
            );
            self::$pdo = new PDO($dsn, getenv('PG_USER') ?: 'exposure_lens', getenv('PG_PASSWORD') ?: '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$pdo;
    }

    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::all($sql, $params)[0] ?? null;
        return $row ?: null;
    }

    public static function value(string $sql, array $params = [])
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** Runs a statement and returns the number of affected rows. */
    public static function run(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function setting(string $section): array
    {
        $json = self::value('SELECT data FROM settings WHERE section = ?', [$section]);
        $data = $json ? json_decode((string) $json, true) : null;
        return is_array($data) ? $data : [];
    }

    public static function saveSetting(string $section, array $data): void
    {
        self::run(
            'INSERT INTO settings (section, data) VALUES (?, CAST(? AS jsonb))
             ON CONFLICT (section) DO UPDATE SET data = EXCLUDED.data, updated_at = now()',
            [$section, json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }
}
