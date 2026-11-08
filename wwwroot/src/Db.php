<?php
declare(strict_types=1);

/** PostgreSQL access. Every query goes through prepared statements. */
final class Db
{
    private static ?PDO $pdo = null;
    private static bool $inTransaction = false;   // PDO cannot tell on a dead connection

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect(true);
    }

    private static function connect(bool $persistent): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            getenv('PG_HOST') ?: 'postgres',
            getenv('PG_PORT') ?: '5432',
            getenv('PG_DB') ?: 'exposure_lens'
        );
        return new PDO($dsn, getenv('PG_USER') ?: 'exposure_lens', getenv('PG_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // One connection per fpm worker: connecting costs more than most queries here.
            PDO::ATTR_PERSISTENT => $persistent,
        ]);
    }

    /**
     * Prepare and run a statement. Connections are persistent, so one can be dead
     * after a database restart; the worker then reconnects and tries once more.
     */
    private static function execute(string $sql, array $params): PDOStatement
    {
        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            if (!self::isConnectionLost($e) || self::$inTransaction) {
                throw $e;
            }
            // The dead persistent handle would come back from new PDO in this
            // request, so finish on a plain connection. The next request reconnects.
            self::$pdo = self::connect(false);
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        }
    }

    public static function begin(): void
    {
        self::execute('SELECT 1', []);   // reconnect now if needed; a transaction cannot be retried
        self::$pdo->beginTransaction();
        self::$inTransaction = true;
    }

    public static function commit(): void
    {
        self::$pdo->commit();
        self::$inTransaction = false;
    }

    private static function isConnectionLost(PDOException $e): bool
    {
        $state = (string) $e->getCode();
        return str_starts_with($state, '08') || $state === '57P01' || $state === 'HY000'
            || str_contains($e->getMessage(), 'server closed the connection')
            || str_contains($e->getMessage(), 'no connection to the server');
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::execute($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::all($sql, $params)[0] ?? null;
        return $row ?: null;
    }

    public static function value(string $sql, array $params = [])
    {
        return self::execute($sql, $params)->fetchColumn();
    }

    /** Runs a statement and returns the number of affected rows. */
    public static function run(string $sql, array $params = []): int
    {
        return self::execute($sql, $params)->rowCount();
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
