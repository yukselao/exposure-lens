<?php
declare(strict_types=1);

final class ApiError extends RuntimeException
{
    public function __construct(string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}

/** Every AJAX action of the UI. Each method takes the request parameters and returns the JSON payload. */
final class Api
{
    /** action => [HTTP method, handler] */
    public const ACTIONS = [
        'sc_settings_save' => ['POST', 'scSettingsSave'],
        'sc_test' => ['POST', 'scTest'],
        'sc_meta' => ['GET', 'scMeta'],
        'pools' => ['GET', 'pools'],
        'pool_save' => ['POST', 'poolSave'],
        'pool_delete' => ['POST', 'poolDelete'],
        'pool_sync' => ['POST', 'poolSync'],
        'query_save' => ['POST', 'querySave'],
        'query_delete' => ['POST', 'queryDelete'],
        'tie_settings_save' => ['POST', 'tieSettingsSave'],
        'tie_test' => ['POST', 'tieTest'],
        'tie_sync' => ['POST', 'tieSync'],
        'tie_overview' => ['GET', 'tieOverview'],
        'job' => ['GET', 'job'],
        'jobs' => ['GET', 'jobs'],
        'ad_objects' => ['GET', 'adObjects'],
        'ad_object' => ['GET', 'adObject'],
        'ad_tier_set' => ['POST', 'adTierSet'],
        'devices' => ['GET', 'devices'],
        'device' => ['GET', 'device'],
        'dashboard' => ['GET', 'dashboard'],
        'logs' => ['GET', 'logs'],
    ];

    private const SEVERITY = [4 => 'Critical', 3 => 'High', 2 => 'Medium', 1 => 'Low', 0 => 'Info'];

    // ---- helpers --------------------------------------------------------------
    private static function str(array $in, string $key, int $max = 255): string
    {
        $value = $in[$key] ?? '';
        return mb_substr(trim(is_string($value) ? $value : ''), 0, $max);
    }

    private static function int(array $in, string $key, int $default = 0, int $min = 0, int $max = PHP_INT_MAX): int
    {
        $value = $in[$key] ?? null;
        if (!is_string($value) || !preg_match('/^-?\d{1,12}$/', trim($value))) {
            return $default;
        }
        return max($min, min($max, (int) $value));
    }

    private static function bool(array $in, string $key): bool
    {
        return in_array($in[$key] ?? '', ['1', 'true', 'on'], true);
    }

    private static function page(array $in): array
    {
        $size = self::int($in, 'size', 50, 10, 200);
        $page = self::int($in, 'page', 1, 1, 1000000);
        return [$page, $size, ($page - 1) * $size];
    }

    private static function like(string $q): string
    {
        return '%' . addcslashes($q, '%_\\') . '%';
    }

    // ---- Security Center settings ----------------------------------------------
    public static function scSettingsSave(array $in): array
    {
        $url = self::str($in, 'url', 500);
        if ($url !== '' && !preg_match('#^https?://[^\s/?\#]+(/[^\s]*)?$#i', $url)) {
            throw new ApiError('Enter the Security Center URL as https://host or https://host:port.');
        }
        $data = Db::setting('seccenter');
        $data['url'] = $url;
        $data['verify_ssl'] = self::bool($in, 'verify_ssl');
        foreach (['access_key', 'secret_key'] as $name) {
            $value = self::str($in, $name, 500);
            if ($value !== '') {            // an empty field keeps the stored key
                $data[$name . '_enc'] = Crypto::encrypt($value);
            }
        }
        Db::saveSetting('seccenter', $data);
        return ['ok' => true] + self::scPublicSettings();
    }

    /** Settings without the keys themselves: only whether each one is set. */
    public static function scPublicSettings(): array
    {
        $data = Db::setting('seccenter');
        return [
            'url' => (string) ($data['url'] ?? ''),
            'verify_ssl' => (bool) ($data['verify_ssl'] ?? true),
            'access_key_set' => !empty($data['access_key_enc']),
            'secret_key_set' => !empty($data['secret_key_enc']),
        ];
    }

    public static function scTest(array $in): array
    {
        return Jobs::enqueue('sc', 'test_connection', null);
    }

    public static function scMeta(array $in): array
    {
        $meta = Db::setting('seccenter_meta');
        return [
            'ok' => $meta['ok'] ?? null,
            'tested_at' => $meta['tested_at'] ?? null,
            'username' => $meta['username'] ?? null,
            'role' => $meta['role'] ?? null,
            'error' => $meta['error'] ?? null,
            'repositories' => $meta['repositories'] ?? [],
            'families' => $meta['families'] ?? [],
        ];
    }

    // ---- pools and queries ------------------------------------------------------
    public static function pools(array $in): array
    {
        $pools = Db::all('SELECT id, name, description, enabled, sync_interval_minutes, last_sync_at
                          FROM sc_pools ORDER BY lower(name)');
        $queries = Db::all(
            'SELECT q.id, q.pool_id, q.name, q.filters, q.max_records, q.enabled, q.last_sync_at,
                    q.last_total, q.last_fetched,
                    (SELECT count(*) FROM sc_query_findings f WHERE f.query_id = q.id) AS findings
             FROM sc_queries q ORDER BY q.pool_id, lower(q.name)'
        );
        $jobs = Db::all(
            "SELECT DISTINCT ON (target_id) target_id, id, status, trigger, requested_at, started_at,
                    finished_at, progress, stats, error
             FROM sync_jobs WHERE connector = 'sc' AND kind = 'sync' AND target_id IS NOT NULL
             ORDER BY target_id, id DESC"
        );
        $byPool = [];
        foreach ($queries as $query) {
            $query['filters'] = json_decode((string) $query['filters'], true) ?: new stdClass();
            $byPool[$query['pool_id']][] = $query;
        }
        $lastJob = [];
        foreach ($jobs as $job) {
            $job['progress'] = json_decode((string) $job['progress'], true) ?: new stdClass();
            $job['stats'] = json_decode((string) $job['stats'], true) ?: new stdClass();
            $lastJob[$job['target_id']] = $job;
        }
        foreach ($pools as &$pool) {
            $pool['queries'] = $byPool[$pool['id']] ?? [];
            $pool['last_job'] = $lastJob[$pool['id']] ?? null;
        }
        return ['pools' => $pools];
    }

    public static function poolSave(array $in): array
    {
        $id = self::int($in, 'id');
        $name = self::str($in, 'name', 200);
        if ($name === '') {
            throw new ApiError('Give the pool a name.');
        }
        $params = [$name, self::str($in, 'description', 2000), self::bool($in, 'enabled') ? 't' : 'f',
                   self::int($in, 'sync_interval_minutes', 0, 0, 525600)];
        try {
            if ($id > 0) {
                $params[] = $id;
                if (!Db::run('UPDATE sc_pools SET name = ?, description = ?, enabled = ?,
                              sync_interval_minutes = ?, updated_at = now() WHERE id = ?', $params)) {
                    throw new ApiError('This pool no longer exists.', 404);
                }
            } else {
                $id = (int) Db::value('INSERT INTO sc_pools (name, description, enabled, sync_interval_minutes)
                                       VALUES (?, ?, ?, ?) RETURNING id', $params);
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new ApiError("A pool named \"$name\" already exists.");
            }
            throw $e;
        }
        return ['ok' => true, 'id' => $id];
    }

    public static function poolDelete(array $in): array
    {
        $id = self::int($in, 'id');
        if (Jobs::isBusy('sc', $id)) {
            throw new ApiError('A sync is running for this pool. Delete it after the sync ends.', 409);
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        Db::run("DELETE FROM sync_jobs WHERE connector = 'sc' AND target_id = ? AND status = 'queued'", [$id]);
        $deleted = Db::run('DELETE FROM sc_pools WHERE id = ?', [$id]);
        $removed = self::deleteUnreferencedFindings();
        $pdo->commit();
        return ['ok' => $deleted > 0] + $removed;
    }

    public static function poolSync(array $in): array
    {
        $id = self::int($in, 'id');
        $pool = Db::one('SELECT id, (SELECT count(*) FROM sc_queries q WHERE q.pool_id = p.id AND q.enabled) AS queries
                         FROM sc_pools p WHERE id = ?', [$id]);
        if (!$pool) {
            throw new ApiError('This pool no longer exists.', 404);
        }
        if ((int) $pool['queries'] === 0) {
            throw new ApiError('Add at least one enabled query to the pool before syncing.');
        }
        return Jobs::enqueue('sc', 'sync', $id);
    }

    public static function querySave(array $in): array
    {
        $id = self::int($in, 'id');
        $poolId = self::int($in, 'pool_id');
        $name = self::str($in, 'name', 200);
        if ($name === '') {
            throw new ApiError('Give the query a name.');
        }
        $filters = json_decode((string) ($in['filters'] ?? ''), true);
        $filters = json_encode((object) self::cleanFilters(is_array($filters) ? $filters : []));
        $params = [$name, $filters, self::int($in, 'max_records', 0, 0, 10000000), self::bool($in, 'enabled') ? 't' : 'f'];
        try {
            if ($id > 0) {
                $params[] = $id;
                if (!Db::run('UPDATE sc_queries SET name = ?, filters = CAST(? AS jsonb), max_records = ?,
                              enabled = ?, updated_at = now() WHERE id = ?', $params)) {
                    throw new ApiError('This query no longer exists.', 404);
                }
            } else {
                if (!Db::value('SELECT 1 FROM sc_pools WHERE id = ?', [$poolId])) {
                    throw new ApiError('This pool no longer exists.', 404);
                }
                array_unshift($params, $poolId);
                $id = (int) Db::value('INSERT INTO sc_queries (pool_id, name, filters, max_records, enabled)
                                       VALUES (?, ?, CAST(? AS jsonb), ?, ?) RETURNING id', $params);
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new ApiError("This pool already has a query named \"$name\".");
            }
            throw $e;
        }
        return ['ok' => true, 'id' => $id];
    }

    public static function queryDelete(array $in): array
    {
        $id = self::int($in, 'id');
        $poolId = Db::value('SELECT pool_id FROM sc_queries WHERE id = ?', [$id]);
        if ($poolId && Jobs::isBusy('sc', (int) $poolId)) {
            throw new ApiError('A sync is running for this pool. Delete the query after the sync ends.', 409);
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        $deleted = Db::run('DELETE FROM sc_queries WHERE id = ?', [$id]);
        $removed = self::deleteUnreferencedFindings();
        $pdo->commit();
        return ['ok' => $deleted > 0] + $removed;
    }

    /** Same rule as the connector: a finding no query returns is deleted, then hosts without findings. */
    private static function deleteUnreferencedFindings(): array
    {
        $findings = Db::run('DELETE FROM vulnerabilities v WHERE NOT EXISTS
                             (SELECT 1 FROM sc_query_findings f WHERE f.vulnerability_id = v.id)');
        $hosts = Db::run('DELETE FROM host_assets h WHERE NOT EXISTS
                          (SELECT 1 FROM vulnerabilities v WHERE v.host_asset_id = h.id)');
        return ['findings_deleted' => $findings, 'hosts_deleted' => $hosts];
    }

    /** Keep only known filter parameters, each in the shape the connector expects. */
    private static function cleanFilters(array $in): array
    {
        $out = [];
        $severity = array_values(array_unique(array_filter(
            array_map('strval', (array) ($in['severity'] ?? [])),
            fn ($s) => in_array($s, ['0', '1', '2', '3', '4'], true)
        )));
        sort($severity);
        if ($severity) {
            $out['severity'] = $severity;
        }
        $parts = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', (string) ($in['ip_range'] ?? '')))));
        foreach ($parts as $part) {
            // an address, a CIDR block, or a first-last range
            if (!preg_match('/^[0-9a-fA-F:.]{2,45}(\/\d{1,3})?(\s*-\s*[0-9a-fA-F:.]{2,45})?$/', $part)
                || !preg_match('/\d/', $part) || !preg_match('/[.:]/', $part)) {
                throw new ApiError("\"$part\" is not an IP address, a CIDR block or a range such as 10.0.2.1-10.0.2.50.");
            }
        }
        if ($parts) {
            $out['ip_range'] = mb_substr(implode(',', $parts), 0, 2000);
        }
        $name = mb_substr(trim((string) ($in['name_contains'] ?? '')), 0, 200);
        if ($name !== '') {
            $out['name_contains'] = $name;
        }
        $plugins = implode(',', array_filter(
            preg_split('/[\s,;]+/', (string) ($in['plugin_ids'] ?? '')),
            fn ($p) => $p !== '' && ctype_digit($p)
        ));
        if ($plugins !== '') {
            $out['plugin_ids'] = mb_substr($plugins, 0, 4000);
        }
        foreach (['cvss_v3_min', 'cvss_v3_max', 'vpr_min', 'vpr_max', 'acr_min', 'acr_max'] as $key) {
            $value = $in[$key] ?? null;
            if (is_numeric($value)) {
                $out[$key] = max(0.0, min(10.0, (float) $value));
            }
        }
        foreach (['families', 'repositories'] as $key) {
            $items = [];
            foreach ((array) ($in[$key] ?? []) as $item) {
                $itemId = is_array($item) ? (string) ($item['id'] ?? '') : (string) $item;
                if (ctype_digit($itemId)) {
                    $items[$itemId] = ['id' => $itemId,
                                       'name' => mb_substr((string) (is_array($item) ? ($item['name'] ?? '') : ''), 0, 200)];
                }
            }
            if ($items) {
                $out[$key] = array_values($items);
            }
        }
        if (!empty($in['exploit_only'])) {
            $out['exploit_only'] = true;
        }
        return $out;
    }

    // ---- Identity Exposure settings ------------------------------------------------
    public static function tiePublicSettings(): array
    {
        $data = Db::setting('tie');
        return [
            'connector_url' => (string) ($data['connector_url'] ?? ''),
            'token_set' => !empty($data['token_enc']),
            'sync_interval_minutes' => (int) ($data['sync_interval_minutes'] ?? 0),
        ];
    }

    public static function tieSettingsSave(array $in): array
    {
        $url = self::str($in, 'connector_url', 500);
        if ($url !== '' && !preg_match('#^https?://[^\s/?\#]+(/[^\s]*)?$#i', $url)) {
            throw new ApiError('Enter the connector URL as http://host:port.');
        }
        $data = Db::setting('tie');
        $data['connector_url'] = $url;
        $data['sync_interval_minutes'] = self::int($in, 'sync_interval_minutes', 0, 0, 525600);
        $token = self::str($in, 'token', 1000);
        if ($token !== '') {
            $data['token_enc'] = Crypto::encrypt($token);
        } elseif (self::bool($in, 'clear_token')) {
            unset($data['token_enc']);
        }
        Db::saveSetting('tie', $data);
        return ['ok' => true] + self::tiePublicSettings();
    }

    public static function tieTest(array $in): array
    {
        return Jobs::enqueue('tie_postgres', 'test_connection', null);
    }

    public static function tieSync(array $in): array
    {
        return Jobs::enqueue('tie_postgres', 'sync', null);
    }

    public static function tieOverview(array $in): array
    {
        $meta = Db::setting('tie_meta');
        return [
            'meta' => ['ok' => $meta['ok'] ?? null, 'tested_at' => $meta['tested_at'] ?? null,
                       'state' => $meta['state'] ?? null, 'object_count' => $meta['object_count'] ?? null,
                       'error' => $meta['error'] ?? null],
            'counts' => Db::one("SELECT (SELECT count(*) FROM ad_users WHERE account_type = 'user') AS users,
                                        (SELECT count(*) FROM ad_users WHERE account_type <> 'user') AS service_accounts,
                                        (SELECT count(*) FROM ad_groups) AS groups,
                                        (SELECT count(*) FROM ad_computers) AS computers,
                                        (SELECT count(*) FROM ad_group_policies) AS group_policies,
                                        (SELECT count(*) FROM ad_tier_assignments WHERE source = 'tie') AS tier0"),
            'jobs' => Jobs::recent('tie_postgres', null, null, 8),
        ];
    }

    // ---- jobs ---------------------------------------------------------------------
    public static function job(array $in): array
    {
        $job = Jobs::get(self::int($in, 'id'));
        if (!$job) {
            throw new ApiError('This job no longer exists.', 404);
        }
        return $job;
    }

    public static function jobs(array $in): array
    {
        $connector = self::str($in, 'connector', 32);
        if (!in_array($connector, ['sc', 'tie_postgres'], true)) {
            throw new ApiError('Unknown connector.');
        }
        $target = isset($in['target_id']) && $in['target_id'] !== '' ? self::int($in, 'target_id') : null;
        $kind = self::str($in, 'kind', 32) ?: null;
        return ['jobs' => Jobs::recent($connector, $kind, $target, self::int($in, 'limit', 10, 1, 100))];
    }

    // ---- AD objects -------------------------------------------------------------------
    private const AD_TYPES = [
        'users' => ['ad_users', "o.account_type = 'user'",
            'o.enabled, o.account_type, o.user_principal_name, o.admin_count, o.last_logon, o.pwd_last_set'],
        'service_accounts' => ['ad_users', "o.account_type <> 'user'",
            'o.enabled, o.account_type, o.user_principal_name, o.admin_count, o.last_logon, o.pwd_last_set'],
        'all_users' => ['ad_users', 'true',
            'o.enabled, o.account_type, o.user_principal_name, o.admin_count, o.last_logon, o.pwd_last_set'],
        'groups' => ['ad_groups', 'true', 'o.group_type, o.member_count, o.description'],
        'computers' => ['ad_computers', 'true', 'o.enabled, o.dns_host_name, o.os, o.os_version, o.last_logon'],
        'group_policies' => ['ad_group_policies', 'true', 'o.display_name, o.gpc_file_sys_path'],
    ];
    private const AD_SORTS = ['name' => 'lower(o.name)', 'sam_account_name' => 'lower(o.sam_account_name)',
        'tier' => 't.tier', 'when_changed' => 'o.when_changed', 'when_created' => 'o.when_created'];

    public static function adObjects(array $in): array
    {
        $type = self::str($in, 'type', 32);
        if (!isset(self::AD_TYPES[$type])) {
            throw new ApiError('Unknown object type.');
        }
        [$table, $condition, $columns] = self::AD_TYPES[$type];
        [$page, $size, $offset] = self::page($in);
        $where = [$condition];
        $params = [];
        $q = self::str($in, 'q', 200);
        if ($q !== '') {
            $where[] = '(o.name ILIKE ? OR o.sam_account_name ILIKE ? OR o.dn ILIKE ? OR o.sid ILIKE ?)';
            array_push($params, self::like($q), self::like($q), self::like($q), self::like($q));
        }
        $tier = self::str($in, 'tier', 8);
        if (in_array($tier, ['0', '1', '2'], true)) {
            $where[] = 't.tier = ?';
            $params[] = (int) $tier;
        } elseif ($tier === 'none') {
            $where[] = 't.tier IS NULL';
        }
        if (isset($in['enabled']) && in_array($in['enabled'], ['0', '1'], true)
            && in_array($table, ['ad_users', 'ad_computers'], true)) {
            $where[] = 'o.enabled = ?';
            $params[] = $in['enabled'] === '1' ? 't' : 'f';
        }
        $sort = self::AD_SORTS[self::str($in, 'sort', 32)] ?? self::AD_SORTS['name'];
        $dir = self::str($in, 'dir', 4) === 'desc' ? 'DESC' : 'ASC';
        $from = "FROM $table o LEFT JOIN v_ad_object_tier t ON t.object_key = o.object_key WHERE " . implode(' AND ', $where);
        $total = (int) Db::value("SELECT count(*) $from", $params);
        $rows = Db::all(
            "SELECT o.id, o.object_key, o.name, o.sam_account_name, o.dn, o.sid, o.when_created, o.when_changed,
                    t.tier, t.source AS tier_source, $columns
             $from ORDER BY $sort $dir NULLS LAST, o.id LIMIT ? OFFSET ?",
            array_merge($params, [$size, $offset])
        );
        return ['total' => $total, 'page' => $page, 'size' => $size, 'rows' => $rows];
    }

    public static function adObject(array $in): array
    {
        $type = self::str($in, 'type', 32);
        if (!isset(self::AD_TYPES[$type])) {
            throw new ApiError('Unknown object type.');
        }
        $table = self::AD_TYPES[$type][0];
        $row = Db::one(
            "SELECT o.*, t.tier, t.source AS tier_source, t.detail AS tier_detail
             FROM $table o LEFT JOIN v_ad_object_tier t ON t.object_key = o.object_key WHERE o.id = ?",
            [self::int($in, 'id')]
        );
        if (!$row) {
            throw new ApiError('This object no longer exists.', 404);
        }
        $row['attributes'] = json_decode((string) $row['attributes'], true) ?: new stdClass();
        $row['tier_detail'] = $row['tier_detail'] ? json_decode((string) $row['tier_detail'], true) : null;
        return $row;
    }

    /**
     * tier: 0, 1, 2 assigns a tier; "none" removes the objects from every tier;
     * "auto" drops the manual assignment so the TIE result applies again.
     */
    public static function adTierSet(array $in): array
    {
        $keys = array_values(array_unique(array_filter(
            array_map(fn ($k) => is_string($k) ? mb_substr(strtolower(trim($k)), 0, 255) : '', (array) ($in['keys'] ?? [])),
            fn ($k) => $k !== ''
        )));
        if (!$keys || count($keys) > 5000) {
            throw new ApiError('Select between 1 and 5000 objects.');
        }
        $tier = self::str($in, 'tier', 8);
        if (!in_array($tier, ['0', '1', '2', 'none', 'auto'], true)) {
            throw new ApiError('Unknown tier.');
        }
        $list = '{' . implode(',', array_map(fn ($k) => '"' . addcslashes($k, '"\\') . '"', $keys)) . '}';
        if ($tier === 'auto') {
            $changed = Db::run("DELETE FROM ad_tier_assignments WHERE source = 'manual' AND object_key = ANY (CAST(? AS text[]))", [$list]);
            return ['ok' => true, 'changed' => $changed];
        }
        $changed = Db::run(
            "INSERT INTO ad_tier_assignments (object_key, source, tier, name, object_type)
             SELECT o.object_key, 'manual', CAST(? AS smallint), o.name, o.object_type
             FROM (SELECT DISTINCT ON (object_key) object_key, name, object_type FROM v_ad_objects
                   WHERE object_key = ANY (CAST(? AS text[])) ORDER BY object_key) o
             ON CONFLICT (object_key, source)
             DO UPDATE SET tier = EXCLUDED.tier, name = EXCLUDED.name, object_type = EXCLUDED.object_type, updated_at = now()",
            [$tier === 'none' ? null : (int) $tier, $list]
        );
        return ['ok' => true, 'changed' => $changed];
    }

    // ---- devices (Security Center hosts + AD computers) ---------------------------------
    private const DEVICE_SORTS = ['name' => 'lower(name)', 'critical' => 'critical', 'high' => 'high',
        'last_seen' => 'last_seen', 'os' => 'lower(os)', 'ip' => 'ip', 'aes' => 'aes_score'];

    private static function devicesSql(): string
    {
        // SC hosts, plus AD computers that no SC host maps to.
        return "
            SELECT 'h' || h.id AS key, h.id AS host_id, h.ad_computer_id,
                   CASE WHEN h.domain_joined THEN 'sc+ad' ELSE 'sc' END AS source,
                   COALESCE(h.dns_name, regexp_replace(h.netbios_name, '^.*\\\\', ''), host(h.ip)) AS name,
                   h.ip, h.os, h.asset_class, h.device_type, h.domain_joined, h.repository_name,
                   h.acr_score, h.aes_score, h.last_seen, h.ad_object_key,
                   COALESCE(s.critical, 0) AS critical, COALESCE(s.high, 0) AS high,
                   COALESCE(s.medium, 0) AS medium, COALESCE(s.low, 0) AS low
            FROM v_host_assets h
            LEFT JOIN LATERAL (
                SELECT count(*) FILTER (WHERE severity = 4) AS critical, count(*) FILTER (WHERE severity = 3) AS high,
                       count(*) FILTER (WHERE severity = 2) AS medium, count(*) FILTER (WHERE severity = 1) AS low
                FROM vulnerabilities v WHERE v.host_asset_id = h.id
            ) s ON true
            UNION ALL
            SELECT 'c' || c.id, NULL, c.id, 'ad',
                   COALESCE(c.dns_host_name, rtrim(c.sam_account_name, '$'), c.name),
                   NULL, c.os, 'device', NULL, true, NULL, NULL, NULL, c.last_logon, c.object_key, 0, 0, 0, 0
            FROM ad_computers c
            WHERE NOT EXISTS (SELECT 1 FROM v_asset_correlation ac WHERE ac.ad_computer_id = c.id)";
    }

    public static function devices(array $in): array
    {
        [$page, $size, $offset] = self::page($in);
        $where = ['asset_class = ?'];
        $params = [self::str($in, 'class', 8) === 'iot' ? 'iot' : 'device'];
        $q = self::str($in, 'q', 200);
        if ($q !== '') {
            $where[] = '(name ILIKE ? OR host(ip) ILIKE ? OR os ILIKE ?)';
            array_push($params, self::like($q), self::like($q), self::like($q));
        }
        $source = self::str($in, 'source', 8);
        if (in_array($source, ['sc', 'ad', 'sc+ad'], true)) {
            $where[] = 'source = ?';
            $params[] = $source;
        }
        $sortKey = self::str($in, 'sort', 16);
        $sort = self::DEVICE_SORTS[$sortKey] ?? 'critical';
        $dir = isset(self::DEVICE_SORTS[$sortKey]) ? (self::str($in, 'dir', 4) === 'desc' ? 'DESC' : 'ASC') : 'DESC';
        $from = 'FROM (' . self::devicesSql() . ') d WHERE ' . implode(' AND ', $where);
        $total = (int) Db::value("SELECT count(*) $from", $params);
        $rows = Db::all(
            "SELECT d.key, d.host_id, d.ad_computer_id, d.source, d.name, host(d.ip) AS ip, d.os, d.asset_class,
                    d.device_type, d.domain_joined, d.repository_name, d.acr_score, d.aes_score, d.last_seen,
                    d.critical, d.high, d.medium, d.low,
                    (SELECT t.tier FROM v_ad_object_tier t WHERE t.object_key = d.ad_object_key) AS tier
             $from ORDER BY $sort $dir NULLS LAST, key LIMIT ? OFFSET ?",
            array_merge($params, [$size, $offset])
        );
        return ['total' => $total, 'page' => $page, 'size' => $size, 'rows' => $rows];
    }

    public static function device(array $in): array
    {
        $host = Db::one(
            'SELECT id, repository_name, host(ip) AS ip, dns_name, netbios_name, mac_address, os, device_type,
                    asset_class, domain_joined, ad_sam_account_name, acr_score, aes_score, first_seen, last_seen,
                    host_uuid, agent_uuid
             FROM v_host_assets WHERE id = ?',
            [self::int($in, 'id')]
        );
        if (!$host) {
            throw new ApiError('This host no longer exists.', 404);
        }
        $host['vulnerabilities'] = Db::all(
            'SELECT id, plugin_id, plugin_name, severity, vpr_score, cvss_v3, port, protocol, cve,
                    exploit_available, first_seen, last_seen
             FROM vulnerabilities WHERE host_asset_id = ?
             ORDER BY severity DESC, vpr_score DESC NULLS LAST, plugin_id LIMIT 200',
            [$host['id']]
        );
        $host['vulnerability_count'] = (int) Db::value('SELECT count(*) FROM vulnerabilities WHERE host_asset_id = ?', [$host['id']]);
        foreach ($host['vulnerabilities'] as &$v) {
            $v['cve'] = self::pgArray((string) $v['cve']);
        }
        return $host;
    }

    private static function pgArray(string $literal): array
    {
        $inner = trim($literal, '{}');
        return $inner === '' ? [] : array_map(fn ($s) => trim($s, '"'), explode(',', $inner));
    }

    // ---- dashboard ------------------------------------------------------------------------
    public static function dashboard(array $in): array
    {
        $ad = Db::one(
            "SELECT (SELECT count(*) FROM ad_users WHERE account_type = 'user') AS users,
                    (SELECT count(*) FROM ad_users WHERE account_type = 'user' AND enabled IS FALSE) AS users_disabled,
                    (SELECT count(*) FROM ad_users WHERE account_type <> 'user') AS service_accounts,
                    (SELECT count(*) FROM ad_groups) AS groups,
                    (SELECT count(*) FROM ad_computers) AS computers,
                    (SELECT count(*) FROM ad_group_policies) AS group_policies"
        );
        $tiers = Db::all(
            "SELECT t.tier, o.object_type, count(*) AS n
             FROM v_ad_object_tier t JOIN v_ad_objects o ON o.object_key = t.object_key
             WHERE t.tier IS NOT NULL GROUP BY t.tier, o.object_type ORDER BY t.tier"
        );
        $severity = Db::all(
            "SELECT severity, count(*) AS n,
                    round(avg(EXTRACT(EPOCH FROM now() - first_seen) / 86400))::int AS avg_age_days
             FROM vulnerabilities GROUP BY severity ORDER BY severity DESC"
        );
        $topVpr = Db::all(
            "SELECT plugin_id, min(plugin_name) AS plugin_name, max(vpr_score) AS vpr_score, max(severity) AS severity,
                    count(DISTINCT host_asset_id) AS hosts, bool_or(exploit_available) AS exploit_available
             FROM vulnerabilities WHERE vpr_score >= 9
             GROUP BY plugin_id ORDER BY max(vpr_score) DESC, count(DISTINCT host_asset_id) DESC, plugin_id LIMIT 10"
        );
        // Security Center has no asset tags; pools and their queries play that role.
        $byQuery = Db::all(
            "SELECT p.name AS pool, q.name AS query,
                    count(*) FILTER (WHERE v.severity = 4) AS critical, count(*) FILTER (WHERE v.severity = 3) AS high,
                    count(*) FILTER (WHERE v.severity = 2) AS medium, count(*) FILTER (WHERE v.severity = 1) AS low,
                    count(*) FILTER (WHERE v.severity = 0) AS info
             FROM sc_queries q JOIN sc_pools p ON p.id = q.pool_id
             LEFT JOIN sc_query_findings f ON f.query_id = q.id
             LEFT JOIN vulnerabilities v ON v.id = f.vulnerability_id
             GROUP BY p.name, q.name ORDER BY count(v.id) DESC LIMIT 12"
        );
        $assets = Db::one(
            "SELECT (SELECT count(*) FROM ad_computers) AS ad_computers,
                    (SELECT count(*) FROM v_host_assets) AS sc_hosts,
                    (SELECT count(*) FROM v_host_assets WHERE domain_joined) AS matched,
                    (SELECT count(*) FROM v_host_assets WHERE NOT domain_joined AND asset_class = 'device') AS not_joined,
                    (SELECT count(*) FROM v_host_assets WHERE asset_class = 'iot') AS iot"
        );
        $sync = Db::all(
            "SELECT DISTINCT ON (connector) connector, status, finished_at, requested_at
             FROM sync_jobs WHERE kind = 'sync' ORDER BY connector, id DESC"
        );
        return ['ad' => $ad, 'tiers' => $tiers, 'severity' => $severity, 'top_vpr' => $topVpr,
                'by_query' => $byQuery, 'assets' => $assets, 'local_users' => null, 'last_sync' => $sync];
    }

    // ---- logs ---------------------------------------------------------------------------------
    public static function logs(array $in): array
    {
        $connector = self::str($in, 'connector', 32);
        if (!isset(Logs::CONNECTORS[$connector])) {
            throw new ApiError('Unknown connector.');
        }
        $jobId = isset($in['job_id']) && $in['job_id'] !== '' ? self::int($in, 'job_id') : null;
        return Logs::tail($connector, self::int($in, 'lines', 200, 1, 2000), self::str($in, 'level', 16),
                          self::str($in, 'q', 200), $jobId) + ['file' => Logs::info($connector)];
    }
}
