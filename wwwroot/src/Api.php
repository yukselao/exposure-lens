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
        'risk_summary' => ['GET', 'riskSummary'],
        'risk_list' => ['GET', 'riskList'],
        'risk_evidence' => ['GET', 'riskEvidence'],
        'local_users' => ['GET', 'localUsers'],
        'software' => ['GET', 'software'],
        'software_hosts' => ['GET', 'softwareHosts'],
        'extensions' => ['GET', 'extensions'],
        'extension_hosts' => ['GET', 'extensionHosts'],
        'ctem_overview' => ['GET', 'ctemOverview'],
        'ctem_settings_save' => ['POST', 'ctemSettingsSave'],
        'ctem_run' => ['POST', 'ctemRun'],
        'catalog' => ['GET', 'catalog'],
        'catalog_set' => ['POST', 'catalogSet'],
        'findings' => ['GET', 'findings'],
        // Agent Tenable (ai_chat, ai_knowledge_download and ai_pack_upload are handled in api.php)
        'ai_settings_save' => ['POST', 'aiSettingsSave'],
        'ai_probe' => ['POST', 'aiProbe'],
        'ai_bootstrap' => ['GET', 'aiBootstrap'],
        'ai_knowledge' => ['GET', 'aiKnowledge'],
        'ai_knowledge_file' => ['GET', 'aiKnowledgeFile'],
        'ai_packs' => ['GET', 'aiPacks'],
        'ai_pack_set' => ['POST', 'aiPackSet'],
        'ai_pack_delete' => ['POST', 'aiPackDelete'],
        'ai_tool_run' => ['POST', 'aiToolRun'],
        'ai_conversations' => ['GET', 'aiConversations'],
        'ai_conversation' => ['GET', 'aiConversation'],
        'ai_conversation_save' => ['POST', 'aiConversationSave'],
        'ai_conversation_delete' => ['POST', 'aiConversationDelete'],
        'ai_conversations_clear' => ['POST', 'aiConversationsClear'],
        // Central asset table (asset_profiles), filled on demand only
        'asset_sync' => ['POST', 'assetSync'],
        'asset_profile' => ['GET', 'assetProfile'],
        'asset_profiles' => ['GET', 'assetProfiles'],
        'asset_lookup' => ['GET', 'assetLookup'],
        'asset_delete' => ['POST', 'assetDelete'],
        'plugin_output' => ['GET', 'pluginOutput'],
        'asset_sync_candidates' => ['GET', 'assetSyncCandidates'],
        'asset_sync_batch' => ['POST', 'assetSyncBatch'],
        'asset_sync_run_start' => ['POST', 'assetSyncRunStart'],
        'asset_sync_run_finish' => ['POST', 'assetSyncRunFinish'],
        'asset_sync_runs' => ['GET', 'assetSyncRuns'],
        'asset_sync_run' => ['GET', 'assetSyncRun'],
        'asset_sync_runs_clear' => ['POST', 'assetSyncRunsClear'],
        'ad_directories' => ['GET', 'adDirectories'],
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
        Db::begin();
        Db::run("DELETE FROM sync_jobs WHERE connector = 'sc' AND target_id = ? AND status = 'queued'", [$id]);
        $deleted = Db::run('DELETE FROM sc_pools WHERE id = ?', [$id]);
        $removed = self::deleteUnreferencedFindings();
        Db::commit();
        Jobs::enqueue('ctem', 'correlate', null);
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
        Db::begin();
        $deleted = Db::run('DELETE FROM sc_queries WHERE id = ?', [$id]);
        $removed = self::deleteUnreferencedFindings();
        Db::commit();
        Jobs::enqueue('ctem', 'correlate', null);
        return ['ok' => $deleted > 0] + $removed;
    }

    /** Same rule as the connector: a finding no query returns is deleted, then hosts without findings. */
    private static function deleteUnreferencedFindings(): array
    {
        $findings = Db::run('DELETE FROM vulnerabilities v WHERE NOT EXISTS
                             (SELECT 1 FROM sc_query_findings f WHERE f.vulnerability_id = v.id)');
        $hosts = Db::run('DELETE FROM host_assets h WHERE NOT EXISTS
                          (SELECT 1 FROM vulnerabilities v WHERE v.host_asset_id = h.id)
                          AND NOT EXISTS (SELECT 1 FROM plugin_outputs o WHERE o.host_asset_id = h.id)');
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
            Jobs::enqueue('ctem', 'correlate', null);
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
        Jobs::enqueue('ctem', 'correlate', null);
        return ['ok' => true, 'changed' => $changed];
    }

    // ---- devices (Security Center hosts + AD computers) ---------------------------------
    private const DEVICE_SORTS = ['name' => 'lower(name)', 'critical' => 'critical', 'high' => 'high',
        'last_seen' => 'last_seen', 'os' => 'lower(os)', 'ip' => 'CAST(ip AS inet)', 'aes' => 'aes_score'];

    private static function devicesSql(): string
    {
        // SC hosts, plus AD computers that no SC host maps to.
        return "
            SELECT 'h' || h.host_asset_id AS key, h.host_asset_id AS host_id, h.ad_computer_id,
                   CASE WHEN h.ad_matched THEN 'sc+ad' ELSE 'sc' END AS source,
                   h.host_name AS name, h.ip, h.os, h.asset_class, h.device_type, h.domain_joined, h.repository_name,
                   h.acr_score, h.aes_score, h.last_seen, h.ad_object_key, h.critical, h.high, h.medium, h.low
            FROM host_risk h
            UNION ALL
            SELECT 'c' || c.id, NULL, c.id, 'ad',
                   COALESCE(c.dns_host_name, rtrim(c.sam_account_name, '$'), c.name),
                   NULL, c.os, 'device', NULL, true, NULL, NULL, NULL, c.last_logon, c.object_key, 0, 0, 0, 0
            FROM ad_computers c
            WHERE NOT EXISTS (SELECT 1 FROM host_ad_match m WHERE m.ad_computer_id = c.id)";
    }

    public static function devices(array $in): array
    {
        [$page, $size, $offset] = self::page($in);
        $where = ['asset_class = ?'];
        $params = [self::str($in, 'class', 8) === 'iot' ? 'iot' : 'device'];
        $q = self::str($in, 'q', 200);
        if ($q !== '') {
            $where[] = '(name ILIKE ? OR ip ILIKE ? OR os ILIKE ?)';
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
            "SELECT d.key, d.host_id, d.ad_computer_id, d.source, d.name, d.ip, d.os, d.asset_class,
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
                    asset_class, domain_joined, domain_name, ad_sam_account_name, acr_score, aes_score, first_seen, last_seen,
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
        // Findings, identities and tiers come from the read model ctem_correlate builds.
        $summary = json_decode((string) Db::value('SELECT data FROM dashboard_summary'), true) ?: [];
        $assets = Db::one(
            "SELECT (SELECT count(*) FROM ad_computers) AS ad_computers, count(*) AS sc_hosts,
                    count(*) FILTER (WHERE ad_matched) AS matched,
                    count(*) FILTER (WHERE domain_joined AND NOT ad_matched) AS joined_unknown_domain,
                    count(*) FILTER (WHERE NOT domain_joined AND asset_class = 'device') AS not_joined,
                    count(*) FILTER (WHERE asset_class = 'iot') AS iot
             FROM host_risk"
        );
        $sync = Db::all(
            "SELECT DISTINCT ON (connector) connector, status, finished_at, requested_at, error
             FROM sync_jobs WHERE kind = 'sync' ORDER BY connector, id DESC"
        );
        return $summary + ['assets' => $assets,
                'local_users' => Db::one("SELECT count(*) FILTER (WHERE privileged) AS privileged,
                                                 count(*) FILTER (WHERE NOT privileged) AS normal
                                          FROM local_accounts WHERE account_type = 'user'"),
                'risk' => self::riskCounts(),
                'last_sync' => $sync];
    }

    // ---- risk ---------------------------------------------------------------------------------
    private const ACCESS_LABELS = ['session', 'last_logon', 'smb_session', 'service_account', 'local_admin', 'rdp_right',
                                   'psremote_right', 'scan_account'];

    private static function acrThreshold(): float
    {
        return (float) (Db::setting('ctem')['acr_threshold'] ?? 7);
    }

    private static function riskCounts(): array
    {
        $acr = self::acrThreshold();
        return Db::one(
            "SELECT count(DISTINCT identity_key) FILTER (WHERE critical > 0 AND identity_kind NOT IN ('group', 'computer') AND access_class <> 'scan') AS critical_users,
                    count(DISTINCT identity_key) FILTER (WHERE acr_score >= ? AND identity_kind NOT IN ('group', 'computer') AND access_class <> 'scan') AS acr_users,
                    count(DISTINCT host_asset_id) FILTER (WHERE tier0_like AND access_class = 'usage') AS tier0_hosts,
                    count(DISTINCT host_asset_id) FILTER (WHERE tier0_like AND access_class = 'usage' AND COALESCE(host_tier, 9) > 0) AS tier0_hosts_outside,
                    count(DISTINCT identity_key) FILTER (WHERE critical > 0 AND access_type = 'service_account') AS service_accounts
             FROM v_risk_access",
            [$acr]
        );
    }

    public static function riskSummary(array $in): array
    {
        $hosts = Db::all(
            "SELECT host_name, count(DISTINCT identity_key) AS identities
             FROM v_risk_access WHERE (tier0_like OR access_type = 'local_admin') AND access_class <> 'scan'
             GROUP BY host_asset_id, host_name ORDER BY 2 DESC, 1 LIMIT 10"
        );
        return [
            'counts' => self::riskCounts(),
            'acr_threshold' => self::acrThreshold(),
            'coverage' => Db::one(
                "SELECT count(*) AS hosts, count(*) FILTER (WHERE credentialed) AS credentialed,
                        count(*) FILTER (WHERE critical > 0) AS critical,
                        count(*) FILTER (WHERE critical > 0 AND NOT credentialed) AS critical_no_credentials,
                        count(*) FILTER (WHERE acr_score >= ?) AS high_acr,
                        count(*) FILTER (WHERE acr_score >= ? AND NOT credentialed) AS high_acr_no_credentials
                 FROM host_risk",
                [self::acrThreshold(), self::acrThreshold()]
            ),
            'matched' => Db::one("SELECT count(*) AS identities, count(ad_object_key) AS matched FROM principals WHERE scope IN ('domain', 'unknown')"),
            'top_hosts' => $hosts,
            'by_type' => Db::all('SELECT access_type, access_class, count(*) AS n FROM identity_host_access GROUP BY 1, 2 ORDER BY 3 DESC'),
            'correlated_at' => Db::value("SELECT finished_at FROM sync_jobs WHERE connector = 'ctem' AND kind = 'correlate' AND status = 'succeeded' ORDER BY id DESC LIMIT 1") ?: null,
        ];
    }

    private const RISK_VIEWS = [
        'critical_users' => ["critical > 0 AND identity_kind NOT IN ('group', 'computer') AND access_class <> 'scan'", 'identity'],
        'acr_users' => ["acr_score >= :acr AND identity_kind NOT IN ('group', 'computer') AND access_class <> 'scan'", 'identity'],
        'tier0_hosts' => ["tier0_like AND access_class = 'usage'", 'host'],
        'service_accounts' => ["critical > 0 AND access_type = 'service_account'", 'identity'],
    ];
    private const RISK_SORTS = [
        'identity' => ['name' => 'lower(min(identity_name))', 'hosts' => 'count(DISTINCT host_asset_id)', 'critical' => 'max(critical)',
                       'vpr' => 'max(max_vpr)', 'acr' => 'max(acr_score)', 'last_seen' => 'max(last_seen)'],
        'host' => ['name' => 'lower(host_name)', 'accounts' => 'count(DISTINCT identity_key)', 'critical' => 'max(critical)',
                   'acr' => 'max(acr_score)'],
    ];

    private static function riskRows(array $in, ?int $limit, int $offset): array
    {
        $view = self::str($in, 'view', 32);
        if (!isset(self::RISK_VIEWS[$view])) {
            throw new ApiError('Unknown view.');
        }
        [$where, $shape] = self::RISK_VIEWS[$view];
        $params = [];
        if (str_contains($where, ':acr')) {
            $where = str_replace(':acr', '?', $where);
            $params[] = self::acrThreshold();
        }
        $class = self::str($in, 'class', 8);
        if (in_array($class, ['usage', 'right'], true) && $view !== 'tier0_hosts' && $view !== 'service_accounts') {
            $where .= ' AND access_class = ?';
            $params[] = $class;
        }
        $q = self::str($in, 'q', 200);
        if ($q !== '') {
            $where .= ' AND (identity_name ILIKE ? OR host_name ILIKE ?)';
            array_push($params, self::like($q), self::like($q));
        }
        $sorts = self::RISK_SORTS[$shape];
        $sort = $sorts[self::str($in, 'sort', 16)] ?? ($shape === 'host' ? $sorts['accounts'] : $sorts['hosts']);
        $dir = self::str($in, 'dir', 4) === 'asc' ? 'ASC' : 'DESC';
        if ($shape === 'identity') {
            $select = "identity_key AS key, min(identity_name) AS name, min(identity_kind) AS kind, min(tier) AS tier,
                       bool_or(privileged_hint) AS privileged_hint, array_to_string(array_agg(DISTINCT access_type), ',') AS access,
                       count(DISTINCT host_asset_id) AS hosts, (array_agg(host_name ORDER BY critical DESC, max_vpr DESC NULLS LAST))[1] AS worst_host,
                       max(critical) AS critical, max(max_vpr) AS max_vpr, max(acr_score) AS acr_score, max(aes_score) AS aes_score,
                       max(last_seen) AS last_seen, min(confidence) AS confidence,
                       string_agg(DISTINCT CASE WHEN access_type = 'service_account' THEN evidence END, ', ') AS services,
                       (SELECT u.pwd_last_set FROM ad_users u WHERE u.object_key = min(ad_object_key) LIMIT 1) AS pwd_last_set";
            $group = 'identity_key';
        } else {
            $select = "host_asset_id AS key, host_name AS name, min(host_tier) AS host_tier, count(DISTINCT identity_key) AS accounts,
                       string_agg(DISTINCT identity_name, ', ') AS account_names, array_to_string(array_agg(DISTINCT access_type), ',') AS access,
                       max(critical) AS critical, max(acr_score) AS acr_score, max(last_seen) AS last_seen";
            $group = 'host_asset_id, host_name';
        }
        $from = "FROM v_risk_access WHERE $where GROUP BY $group";
        $total = (int) Db::value("SELECT count(*) FROM (SELECT 1 $from) x", $params);
        $sql = "SELECT $select $from ORDER BY $sort $dir NULLS LAST, 1";
        if ($limit !== null) {
            $sql .= ' LIMIT ? OFFSET ?';
            array_push($params, $limit, $offset);
        }
        return [$total, Db::all($sql, $params), $shape];
    }

    public static function riskList(array $in): array
    {
        [$page, $size, $offset] = self::page($in);
        [$total, $rows, $shape] = self::riskRows($in, $size, $offset);
        return ['total' => $total, 'page' => $page, 'size' => $size, 'rows' => $rows, 'shape' => $shape];
    }

    /** CSV of a risk view, with no paging. Written straight to the response. */
    public static function riskExport(array $in): void
    {
        [, $rows] = self::riskRows($in, null, 0);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="exposure-lens-' . preg_replace('/\W/', '', self::str($in, 'view', 32)) . '.csv"');
        $out = fopen('php://output', 'w');
        if ($rows) {
            fputcsv($out, array_keys($rows[0]));
        }
        foreach ($rows as $row) {
            // A leading =, +, - or @ would run as a formula in a spreadsheet.
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $row));
        }
        fclose($out);
    }

    public static function riskEvidence(array $in): array
    {
        $key = self::str($in, 'key', 600);
        $column = self::str($in, 'shape', 8) === 'host' ? 'host_asset_id' : 'identity_key';
        $rows = Db::all(
            "SELECT a.identity_name, a.host_name, a.host_asset_id, a.access_type, a.access_class, a.via_group_key, a.evidence, a.confidence,
                    a.last_seen, a.critical, a.max_vpr, a.acr_score, a.evidence_plugin_id, c.name AS plugin_name
             FROM v_risk_access a LEFT JOIN plugin_catalog c ON c.plugin_id = a.evidence_plugin_id
             WHERE a.$column" . ($column === 'host_asset_id' ? ' = CAST(? AS bigint)' : ' = ?') . "
             ORDER BY a.critical DESC, a.host_name, a.access_type LIMIT 300",
            [$key]
        );
        return ['rows' => $rows];
    }

    // ---- local users -----------------------------------------------------------------------------
    /** group_by => [key expression, label expression, extra FROM, sort columns] */
    private const LOCAL_GROUPS = [
        'account' => ['a.name_norm', 'min(a.name)', ''],
        'host' => ['CAST(a.host_asset_id AS text)', 'min(h.host_name)', ''],
        'group' => ['lower(x.item)', 'min(x.item)', 'CROSS JOIN LATERAL unnest(a.groups) AS x(item)'],
        'os' => ["COALESCE(split_part(h.os, E'\\n', 1), 'Unknown')", "min(COALESCE(split_part(h.os, E'\\n', 1), 'Unknown'))", ''],
        'flag' => ['x.item', 'min(x.item)', 'CROSS JOIN LATERAL unnest(a.flags) AS x(item)'],
    ];

    /**
     * Local accounts with their hosts. `group_by` returns one row per group;
     * without it, one row per account and host, narrowed to one group by `in_group`
     * and `in_key` when a group is opened.
     */
    public static function localUsers(array $in): array
    {
        [$page, $size, $offset] = self::page($in);
        $where = ['true'];
        $params = [];
        $type = self::str($in, 'type', 8);
        if (in_array($type, ['user', 'system'], true)) {
            $where[] = 'a.account_type = ?';
            $params[] = $type;
        }
        $privileged = self::str($in, 'privileged', 1);
        if ($privileged === '1' || $privileged === '0') {
            $where[] = $privileged === '1' ? 'a.privileged' : 'NOT a.privileged';
        }
        $status = self::str($in, 'status', 8);
        if ($status === 'disabled' || $status === 'enabled') {
            $where[] = $status === 'disabled' ? 'a.disabled IS TRUE' : 'a.disabled IS FALSE';
        }
        $q = self::str($in, 'q', 200);
        if ($q !== '') {
            $where[] = '(a.name ILIKE ? OR h.host_name ILIKE ? OR h.ip ILIKE ?)';
            array_push($params, self::like($q), self::like($q), self::like($q));
        }
        $base = 'FROM local_accounts a JOIN host_risk h ON h.host_asset_id = a.host_asset_id';

        $groupBy = self::str($in, 'group_by', 8);
        if (isset(self::LOCAL_GROUPS[$groupBy])) {
            [$key, $label, $join] = self::LOCAL_GROUPS[$groupBy];
            $sorts = ['name' => 'lower(' . $label . ')', 'accounts' => 'count(DISTINCT a.principal_id)', 'hosts' => 'count(DISTINCT a.host_asset_id)',
                      'privileged' => 'count(DISTINCT a.principal_id) FILTER (WHERE a.privileged)', 'critical' => 'max(h.critical)'];
            $sort = $sorts[self::str($in, 'sort', 16)] ?? ($groupBy === 'host' ? $sorts['accounts'] : $sorts['hosts']);
            $dir = self::str($in, 'dir', 4) === 'asc' ? 'ASC' : 'DESC';
            $from = "$base $join WHERE " . implode(' AND ', $where) . " GROUP BY $key";
            $total = (int) Db::value("SELECT count(*) FROM (SELECT 1 $from) x2", $params);
            $rows = Db::all(
                "SELECT $key AS key, $label AS name, count(DISTINCT a.principal_id) AS accounts, count(DISTINCT a.host_asset_id) AS hosts,
                        count(DISTINCT a.principal_id) FILTER (WHERE a.privileged) AS privileged,
                        count(DISTINCT a.principal_id) FILTER (WHERE a.disabled) AS disabled,
                        max(h.critical) AS critical, min(h.ip) AS ip, min(h.os) AS os
                 $from ORDER BY $sort $dir NULLS LAST, 1 LIMIT ? OFFSET ?",
                array_merge($params, [$size, $offset])
            );
            return ['total' => $total, 'page' => $page, 'size' => $size, 'rows' => $rows, 'group_by' => $groupBy];
        }

        $inGroup = self::str($in, 'in_group', 8);
        if (isset(self::LOCAL_GROUPS[$inGroup])) {
            [$key, , $join] = self::LOCAL_GROUPS[$inGroup];
            $where[] = $join === ''
                ? "$key = ?"
                : 'EXISTS (SELECT 1 FROM unnest(' . ($inGroup === 'group' ? 'a.groups' : 'a.flags') . ') AS x(item) WHERE ' . $key . ' = ?)';
            $params[] = self::str($in, 'in_key', 2000);
        }
        $sorts = ['name' => 'lower(a.name)', 'host' => 'lower(h.host_name)', 'critical' => 'h.critical', 'last_seen' => 'a.last_seen'];
        $sort = $sorts[self::str($in, 'sort', 16)] ?? 'a.privileged DESC, h.critical';
        $dir = isset($sorts[self::str($in, 'sort', 16)]) && self::str($in, 'dir', 4) === 'asc' ? 'ASC' : 'DESC';
        $from = "$base WHERE " . implode(' AND ', $where);
        $total = (int) Db::value("SELECT count(*) $from", $params);
        $rows = Db::all(
            "SELECT a.host_asset_id, a.name, a.rid, a.account_type, a.disabled, a.privileged, a.groups, a.flags, a.shell, a.last_seen,
                    h.host_name, h.ip, h.os, h.critical, h.acr_score
             $from ORDER BY $sort $dir NULLS LAST, a.name, a.host_asset_id LIMIT ? OFFSET ?",
            array_merge($params, [$size, $offset])
        );
        foreach ($rows as &$row) {
            $row['groups'] = self::pgArray((string) $row['groups']);
            $row['flags'] = self::pgArray((string) $row['flags']);
        }
        return ['total' => $total, 'page' => $page, 'size' => $size, 'rows' => $rows, 'group_by' => 'none'];
    }

    // ---- software and extensions ------------------------------------------------------------------
    public static function software(array $in): array
    {
        [$page, $size, $offset] = self::page($in);
        $recent = self::bool($in, 'recent');
        $where = ['true'];
        $params = [];
        $q = self::str($in, 'q', 200);
        if ($q !== '') {
            $where[] = $recent ? 's.name_norm LIKE ?' : 'name_norm LIKE ?';
            $params[] = strtolower(self::like($q));
        }
        $dir = self::str($in, 'dir', 4) === 'asc' ? 'ASC' : 'DESC';
        $sortKey = self::str($in, 'sort', 16);
        if (!$recent) {
            // The usual path: one row per product, computed by ctem_correlate.
            if (self::bool($in, 'rare')) {
                $where[] = 'hosts < 5';
            }
            $sorts = ['name' => 'name', 'hosts' => 'hosts', 'versions' => 'versions', 'installed' => 'last_installed'];
            $sort = $sorts[$sortKey] ?? 'hosts';
            $from = 'FROM software_summary WHERE ' . implode(' AND ', $where);
            $total = (int) Db::value("SELECT count(*) $from", $params);
            $rows = Db::all(
                "SELECT name_norm AS key, name, versions, latest_version, hosts, last_installed, source_type
                 $from ORDER BY $sort $dir NULLS LAST, 1 LIMIT ? OFFSET ?",
                array_merge($params, [$size, $offset])
            );
            return ['total' => $total, 'page' => $page, 'size' => $size, 'rows' => $rows];
        }
        $where[] = "hs.installed_at >= now() - interval '30 days'";
        $sorts = ['name' => 'min(s.name)', 'hosts' => 'count(DISTINCT hs.host_asset_id)', 'versions' => 'count(DISTINCT s.version)', 'installed' => 'max(hs.installed_at)'];
        $sort = $sorts[$sortKey] ?? $sorts['hosts'];
        $having = self::bool($in, 'rare') ? ' HAVING count(DISTINCT hs.host_asset_id) < 5' : '';
        $from = 'FROM host_software hs JOIN software s ON s.id = hs.software_id WHERE ' . implode(' AND ', $where) . " GROUP BY s.name_norm$having";
        $total = (int) Db::value("SELECT count(*) FROM (SELECT 1 $from) x", $params);
        $rows = Db::all(
            "SELECT s.name_norm AS key, min(s.name) AS name, count(DISTINCT s.version) AS versions, max(s.version) AS latest_version,
                    count(DISTINCT hs.host_asset_id) AS hosts, max(hs.installed_at) AS last_installed, min(hs.source_type) AS source_type
             $from ORDER BY $sort $dir NULLS LAST, 1 LIMIT ? OFFSET ?",
            array_merge($params, [$size, $offset])
        );
        return ['total' => $total, 'page' => $page, 'size' => $size, 'rows' => $rows];
    }

    public static function softwareHosts(array $in): array
    {
        return ['rows' => Db::all(
            "SELECT h.host_name, h.ip, h.critical, h.acr_score, s.version, hs.installed_at, hs.last_seen
             FROM host_software hs JOIN software s ON s.id = hs.software_id JOIN host_risk h ON h.host_asset_id = hs.host_asset_id
             WHERE s.name_norm = ? ORDER BY hs.installed_at DESC NULLS LAST, h.host_name LIMIT 300",
            [self::str($in, 'key', 2000)]
        )];
    }

    public static function extensions(array $in): array
    {
        [$page, $size, $offset] = self::page($in);
        $where = ['true'];
        $params = [];
        $browser = self::str($in, 'browser', 64);
        if ($browser !== '') {
            $where[] = 'e.browser = ?';
            $params[] = $browser;
        }
        $q = self::str($in, 'q', 200);
        if ($q !== '') {
            $where[] = '(e.name ILIKE ? OR e.extension_id ILIKE ?)';
            array_push($params, self::like($q), self::like($q));
        }
        $having = self::bool($in, 'rare') ? ' HAVING count(DISTINCT x.host_asset_id) < 5' : '';
        $sorts = ['name' => 'lower(e.name)', 'hosts' => 'count(DISTINCT x.host_asset_id)', 'users' => 'count(DISTINCT x.profile_user)', 'updated' => 'max(x.updated_at)'];
        $sort = $sorts[self::str($in, 'sort', 16)] ?? $sorts['hosts'];
        $dir = self::str($in, 'dir', 4) === 'asc' ? 'ASC' : 'DESC';
        $from = 'FROM browser_extensions e JOIN host_browser_extensions x ON x.extension_ref = e.id WHERE ' . implode(' AND ', $where) . " GROUP BY e.id$having";
        $total = (int) Db::value("SELECT count(*) FROM (SELECT 1 $from) x2", $params);
        $rows = Db::all(
            "SELECT e.id, e.browser, e.name, e.extension_id, e.description, count(DISTINCT x.host_asset_id) AS hosts,
                    count(DISTINCT x.profile_user) AS users, max(x.version) AS version, max(x.updated_at) AS updated_at
             $from ORDER BY $sort $dir NULLS LAST, e.id LIMIT ? OFFSET ?",
            array_merge($params, [$size, $offset])
        );
        return ['total' => $total, 'page' => $page, 'size' => $size, 'rows' => $rows,
                'browsers' => Db::all('SELECT e.browser, count(DISTINCT e.id) AS n FROM browser_extensions e GROUP BY 1 ORDER BY 2 DESC')];
    }

    public static function extensionHosts(array $in): array
    {
        return ['rows' => Db::all(
            "SELECT h.host_name, h.ip, x.profile_user, x.version, x.updated_at, p.ad_object_key
             FROM host_browser_extensions x JOIN host_risk h ON h.host_asset_id = x.host_asset_id
             LEFT JOIN principals p ON p.id = x.principal_id
             WHERE x.extension_ref = ? ORDER BY h.host_name LIMIT 300",
            [self::int($in, 'id')]
        )];
    }

    // ---- CTEM pipeline ------------------------------------------------------------------------------
    public static function ctemPublicSettings(): array
    {
        $data = Db::setting('ctem');
        return ['collect_interval_minutes' => (int) ($data['collect_interval_minutes'] ?? 360), 'acr_threshold' => (float) ($data['acr_threshold'] ?? 7),
                'scan_results_checked' => (int) ($data['scan_results_checked'] ?? 10), 'max_attachment_mb' => (int) ($data['max_attachment_mb'] ?? 20)];
    }

    public static function ctemSettingsSave(array $in): array
    {
        Db::saveSetting('ctem', [
            'collect_interval_minutes' => self::int($in, 'collect_interval_minutes', 360, 0, 525600),
            'acr_threshold' => self::int($in, 'acr_threshold', 7, 1, 10),
            'scan_results_checked' => self::int($in, 'scan_results_checked', 10, 0, 100),
            'max_attachment_mb' => self::int($in, 'max_attachment_mb', 20, 1, 500),
        ]);
        return ['ok' => true] + self::ctemPublicSettings();
    }

    public static function ctemRun(array $in): array
    {
        $jobs = ['plugin_collect' => 'sc', 'extract' => 'ctem', 'correlate' => 'ctem', 'rebuild' => 'ctem'];
        $kind = self::str($in, 'kind', 32);
        if (!isset($jobs[$kind])) {
            throw new ApiError('Unknown job.');
        }
        return Jobs::enqueue($jobs[$kind], $kind, null);
    }

    public static function ctemOverview(array $in): array
    {
        return [
            'jobs' => array_values(array_filter(Jobs::latestByKind(['sc', 'ctem']), fn ($j) => in_array($j['kind'], ['plugin_collect', 'extract', 'correlate', 'rebuild'], true))),
            'pipeline' => Db::all('SELECT * FROM v_ctem_pipeline WHERE collected > 0 OR extractor IS NOT NULL ORDER BY scenario, plugin_id'),
            'failed' => Db::all(
                "SELECT o.id, o.plugin_id, o.extract_error, h.dns_name, host(h.ip) AS ip FROM plugin_outputs o
                 JOIN host_assets h ON h.id = o.host_asset_id WHERE o.extract_status = 'failed' ORDER BY o.id LIMIT 50"
            ),
        ];
    }

    public static function catalog(array $in): array
    {
        [$page, $size, $offset] = self::page($in);
        $where = ['true'];
        $params = [];
        $status = self::str($in, 'status', 16);
        if (in_array($status, ['selected', 'candidate', 'ignored'], true)) {
            $where[] = 'review_status = ?';
            $params[] = $status;
        }
        $class = self::str($in, 'class', 16);
        if (in_array($class, ['inventory', 'product'], true)) {
            $where[] = 'plugin_class = ?';
            $params[] = $class;
        }
        $source = self::str($in, 'source', 16);
        if ($source === 'handbook') {
            $where[] = 'handbook_category IS NOT NULL';
        } elseif ($source === 'other') {
            $where[] = 'handbook_category IS NULL';
        } elseif ($source === 'scenario') {
            $where[] = 'scenario IS NOT NULL';
        }
        $q = self::str($in, 'q', 200);
        if ($q !== '') {
            $where[] = '(name ILIKE ? OR family ILIKE ? OR CAST(plugin_id AS text) = ?)';
            array_push($params, self::like($q), self::like($q), $q);
        }
        $from = 'FROM plugin_catalog WHERE ' . implode(' AND ', $where);
        $total = (int) Db::value("SELECT count(*) $from", $params);
        $rows = Db::all(
            "SELECT plugin_id, name, family, scenario, extractor, handbook_category, review_status, sc_finding_count, plugin_class
             $from ORDER BY (review_status = 'selected') DESC, scenario NULLS LAST, plugin_id LIMIT ? OFFSET ?",
            array_merge($params, [$size, $offset])
        );
        return ['total' => $total, 'page' => $page, 'size' => $size, 'rows' => $rows];
    }

    public static function catalogSet(array $in): array
    {
        $status = self::str($in, 'status', 16);
        if (!in_array($status, ['selected', 'candidate', 'ignored'], true)) {
            throw new ApiError('Unknown status.');
        }
        $changed = Db::run('UPDATE plugin_catalog SET review_status = ? WHERE plugin_id = ?', [$status, self::int($in, 'plugin_id')]);
        return ['ok' => $changed > 0];
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

    // ---- findings explorer -----------------------------------------------------------------------
    /**
     * Vulnerabilities filtered the way Agent Tenable groups them, so every grouped
     * number has a page that lists the rows behind it: plugin, cve, port, cidr/target,
     * host (id), account (the hosts an identity reaches), severity_min, exploitable, q.
     */
    public static function findings(array $in): array
    {
        [$page, $size, $offset] = self::page($in);
        $where = ['true'];
        $params = [];
        $scope = [];
        $host = self::int($in, 'host');
        if ($host > 0) {
            $where[] = 'v.host_asset_id = ?';
            $params[] = $host;
            $scope['host'] = Db::value('SELECT host_name FROM host_risk WHERE host_asset_id = ?', [$host]);
        }
        $target = self::str($in, 'target', 200) ?: self::str($in, 'cidr', 64);
        if ($target !== '') {
            [$sql, $p] = Tools::hostSet($target);
            $where[] = "v.host_asset_id IN ($sql)";
            array_push($params, ...$p);
            $scope['target'] = $target;
        }
        $account = self::str($in, 'account', 200);
        if ($account !== '') {
            $r = Tools::resolveIdentity($account);
            $where[] = 'v.host_asset_id IN (SELECT DISTINCT a.host_asset_id FROM v_risk_access a WHERE ' . $r['where'] . ')';
            array_push($params, ...$r['params']);
            $scope['account'] = $account;
        }
        $plugin = self::int($in, 'plugin');
        if ($plugin > 0) {
            $where[] = 'v.plugin_id = ?';
            $params[] = $plugin;
            $scope['plugin'] = $plugin;
        }
        $cve = strtoupper(self::str($in, 'cve', 32));
        if ($cve !== '') {
            $where[] = 'v.cve @> ARRAY[?]::text[]';
            $params[] = $cve;
            $scope['cve'] = $cve;
        }
        $port = self::str($in, 'port', 16);
        if ($port !== '' && preg_match('#^(\d{1,5})(?:/(\w+))?$#', $port, $m)) {
            $where[] = 'v.port = ?';
            $params[] = (int) $m[1];
            if (!empty($m[2])) {
                $where[] = 'v.protocol = ?';
                $params[] = strtolower($m[2]);
            }
            $scope['port'] = $port;
        }
        $sevMin = self::int($in, 'severity_min', 0, 0, 4);
        if ($sevMin > 0) {
            $where[] = 'v.severity >= ?';
            $params[] = $sevMin;
            $scope['severity_min'] = $sevMin;
        }
        $sev = self::str($in, 'severity', 1);
        if ($sev !== '' && ctype_digit($sev)) {
            $where[] = 'v.severity = ?';
            $params[] = (int) $sev;
            $scope['severity'] = (int) $sev;
        }
        if (self::bool($in, 'exploitable')) {
            $where[] = 'v.exploit_available';
            $scope['exploitable'] = true;
        }
        $q = self::str($in, 'q', 200);
        if ($q !== '') {
            $where[] = '(lower(v.plugin_name) LIKE ? OR lower(h.host_name) LIKE ? OR h.ip LIKE ? OR CAST(v.plugin_id AS text) = ?)';
            array_push($params, Tools::like($q), Tools::like($q), Tools::like($q), $q);
        }
        $sorts = ['vpr' => 'v.vpr_score', 'severity' => 'v.severity', 'cvss' => 'v.cvss_v3', 'host' => 'lower(h.host_name)', 'plugin' => 'v.plugin_id',
                  'port' => 'v.port', 'first_seen' => 'v.first_seen', 'last_seen' => 'v.last_seen', 'acr' => 'h.acr_score'];
        $sort = $sorts[self::str($in, 'sort', 16)] ?? 'v.vpr_score';
        $dir = self::str($in, 'dir', 4) === 'asc' ? 'ASC' : 'DESC';
        $from = 'FROM vulnerabilities v JOIN host_risk h ON h.host_asset_id = v.host_asset_id WHERE ' . implode(' AND ', $where);
        $summary = Db::one("SELECT count(*) AS total, count(DISTINCT v.host_asset_id) AS hosts, count(*) FILTER (WHERE v.severity = 4) AS critical,
                                   count(*) FILTER (WHERE v.severity = 3) AS high, count(*) FILTER (WHERE v.severity = 2) AS medium,
                                   count(*) FILTER (WHERE v.severity = 1) AS low, count(*) FILTER (WHERE v.severity = 0) AS info,
                                   count(*) FILTER (WHERE v.exploit_available) AS exploitable, max(v.vpr_score) AS max_vpr $from", $params);
        $rows = Db::all(
            "SELECT v.id, v.host_asset_id, h.host_name, h.ip, h.acr_score, h.host_tier, v.plugin_id, v.plugin_name, v.family_name, v.severity, v.vpr_score, v.cvss_v3,
                    v.epss_score, v.port, v.protocol, v.cve, v.exploit_available, v.first_seen, v.last_seen
             $from ORDER BY $sort $dir NULLS LAST, v.severity DESC, h.host_name, v.plugin_id LIMIT ? OFFSET ?",
            array_merge($params, [$size, $offset])
        );
        foreach ($rows as &$row) {
            $row['cve'] = self::pgArray((string) $row['cve']);
        }
        return ['total' => (int) $summary['total'], 'page' => $page, 'size' => $size, 'summary' => $summary, 'scope' => $scope, 'rows' => $rows];
    }

    // ---- Agent Tenable ---------------------------------------------------------------------------
    public static function aiPublicSettings(): array
    {
        $s = Llm::settings();
        $meta = Db::setting('ai_meta');
        return $s + ['meta' => ['ok' => $meta['ok'] ?? null, 'tested_at' => $meta['tested_at'] ?? null, 'version' => $meta['version'] ?? null,
                               'error' => $meta['error'] ?? null, 'models' => $meta['models'] ?? []]];
    }

    public static function aiSettingsSave(array $in): array
    {
        $url = self::str($in, 'url', 500);
        if (!preg_match('#^https?://[^\s/?\#]+(/[^\s]*)?$#i', $url)) {
            throw new ApiError('Enter the model server URL as http://host:11434.');
        }
        $model = self::str($in, 'model', 120);
        if ($model === '' || !preg_match('/^[\w.:\/-]+$/', $model)) {
            throw new ApiError('Enter the model name as the server lists it, e.g. gemma4:31b.');
        }
        $temperature = is_numeric($in['temperature'] ?? null) ? (float) $in['temperature'] : Llm::DEFAULTS['temperature'];
        Db::saveSetting('ai', [
            'url' => rtrim($url, '/'),
            'model' => $model,
            'num_ctx' => self::int($in, 'num_ctx', Llm::DEFAULTS['num_ctx'], 2048, 262144),
            'temperature' => max(0.0, min(2.0, round($temperature, 2))),
            'num_predict' => self::int($in, 'num_predict', Llm::DEFAULTS['num_predict'], 256, 32768),
            'think' => self::bool($in, 'think'),
            'timeout_seconds' => self::int($in, 'timeout_seconds', Llm::DEFAULTS['timeout_seconds'], 30, 1800),
            'max_tool_rounds' => self::int($in, 'max_tool_rounds', Llm::DEFAULTS['max_tool_rounds'], 1, 12),
        ]);
        return ['ok' => true] + self::aiPublicSettings();
    }

    /** Reach the model server, list its models, remember the result. */
    public static function aiProbe(array $in): array
    {
        $url = self::str($in, 'url', 500) ?: Llm::settings()['url'];
        try {
            $probe = Llm::probe($url);
            $meta = ['ok' => true, 'tested_at' => date('c'), 'version' => $probe['version'], 'models' => $probe['models'], 'error' => null];
        } catch (Throwable $e) {
            $meta = ['ok' => false, 'tested_at' => date('c'), 'error' => $e->getMessage(), 'models' => Db::setting('ai_meta')['models'] ?? []];
        }
        Db::saveSetting('ai_meta', $meta);
        return $meta;
    }

    /** What the chat panel needs when it opens: skills, prompt sets, tool list, model. */
    public static function aiBootstrap(array $in): array
    {
        $s = Llm::settings();
        return [
            'model' => $s['model'],
            'skills' => array_map(fn ($k) => ['name' => $k['name'], 'title' => $k['title'], 'description' => $k['meta']['description'] ?? '',
                                                'icon' => $k['meta']['icon'] ?? '', 'default' => ($k['meta']['default'] ?? '') === 'true', 'source' => $k['source']], Knowledge::skills()),
            'prompts' => Knowledge::prompts(),
            'tools' => array_values(array_map(fn ($t) => ['name' => $t['name'], 'description' => $t['meta']['description'] ?? '', 'source' => $t['source'],
                                                          'parameters' => array_keys($t['parameters']['properties'] ?? [])],
                                              array_filter(Knowledge::items('tools'), fn ($t) => isset($t['parameters'])))),
            'agent' => ($a = Knowledge::agent()) ? ['title' => $a['title'], 'description' => $a['meta']['description'] ?? ''] : null,
        ];
    }

    public static function aiKnowledge(array $in): array
    {
        return ['tree' => Knowledge::tree(), 'packs' => Knowledge::packs(), 'builtin_dir' => Knowledge::BUILTIN_DIR];
    }

    public static function aiKnowledgeFile(array $in): array
    {
        $kind = self::str($in, 'kind', 16);
        if (!in_array($kind, Knowledge::KINDS, true)) {
            throw new ApiError('Unknown kind.');
        }
        $file = Knowledge::raw($kind, preg_replace('/[^a-z0-9_-]/', '', self::str($in, 'name', 80)));
        if (!$file) {
            throw new ApiError('This file does not exist.', 404);
        }
        return $file;
    }

    public static function aiPacks(array $in): array
    {
        return ['packs' => Knowledge::packs()];
    }

    public static function aiPackSet(array $in): array
    {
        Knowledge::setPackActive(self::int($in, 'id', 0, 1), self::bool($in, 'active'));
        return ['ok' => true, 'packs' => Knowledge::packs()];
    }

    public static function aiPackDelete(array $in): array
    {
        Knowledge::deletePack(self::int($in, 'id', 0, 1));
        return ['ok' => true, 'packs' => Knowledge::packs()];
    }

    /** Run one tool by hand (Settings > Agent Tenable > Tools, and the test script). */
    public static function aiToolRun(array $in): array
    {
        $name = self::str($in, 'name', 64);
        $args = json_decode((string) ($in['arguments'] ?? '{}'), true);
        if (!is_array($args)) {
            throw new ApiError('Arguments must be a JSON object.');
        }
        $t0 = microtime(true);
        $result = Tools::run($name, $args);
        return ['ok' => true, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'result' => $result];
    }

    // ---- Agent Tenable: saved conversations ------------------------------------------------------
    private const CONVERSATION_MAX_BYTES = 6_000_000;

    public static function aiConversations(array $in): array
    {
        $q = self::str($in, 'q', 200);
        $rows = Db::all(
            "SELECT id, title, skill, message_count, created_at, updated_at FROM ai_conversations
             WHERE (? = '' OR title ILIKE ?) ORDER BY updated_at DESC, id DESC LIMIT 200",
            [$q, self::like($q)]
        );
        return ['rows' => $rows];
    }

    public static function aiConversation(array $in): array
    {
        $row = Db::one('SELECT id, title, skill, messages, message_count, created_at, updated_at FROM ai_conversations WHERE id = ?', [self::int($in, 'id')]);
        if (!$row) {
            throw new ApiError('This conversation was deleted.', 404);
        }
        $row['messages'] = json_decode((string) $row['messages'], true) ?: [];
        return $row;
    }

    /** Creates (no id) or replaces a conversation; the panel calls it after every answer. */
    public static function aiConversationSave(array $in): array
    {
        $raw = (string) ($in['messages'] ?? '');
        if (strlen($raw) > self::CONVERSATION_MAX_BYTES) {
            throw new ApiError('This conversation is too large to save. Start a new one.', 413);
        }
        $messages = json_decode($raw, true);
        if (!is_array($messages) || !array_is_list($messages)) {
            throw new ApiError('The messages are not a JSON list.');
        }
        $messages = array_values(array_filter($messages, fn ($m) => is_array($m) && in_array($m['role'] ?? '', ['user', 'assistant', 'system', 'error'], true)));
        if (!$messages) {
            throw new ApiError('There is nothing to save yet.');
        }
        $title = self::str($in, 'title', 200);
        if ($title === '') {
            foreach ($messages as $m) {
                if (($m['role'] ?? '') === 'user') {
                    $title = mb_substr(preg_replace('/\s+/', ' ', trim((string) $m['content'])), 0, 120);
                    break;
                }
            }
        }
        $title = $title !== '' ? $title : 'Conversation';
        $skill = self::str($in, 'skill', 64);
        $json = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $count = count(array_filter($messages, fn ($m) => in_array($m['role'], ['user', 'assistant'], true)));
        $id = self::int($in, 'id');
        if ($id > 0 && Db::run('UPDATE ai_conversations SET title = ?, skill = ?, messages = CAST(? AS jsonb), message_count = ?, updated_at = now() WHERE id = ?',
                [$title, $skill, $json, $count, $id]) > 0) {
            return ['id' => $id, 'title' => $title];
        }
        $id = (int) Db::value('INSERT INTO ai_conversations (title, skill, messages, message_count) VALUES (?, ?, CAST(? AS jsonb), ?) RETURNING id',
            [$title, $skill, $json, $count]);
        return ['id' => $id, 'title' => $title];
    }

    public static function aiConversationDelete(array $in): array
    {
        return ['ok' => true, 'deleted' => Db::run('DELETE FROM ai_conversations WHERE id = ?', [self::int($in, 'id')])];
    }

    public static function aiConversationsClear(array $in): array
    {
        return ['ok' => true, 'deleted' => Db::run('DELETE FROM ai_conversations', [])];
    }

    // ---- Asset details ------------------------------------------------------------------------------
    public static function assetSync(array $in): array
    {
        $kind = self::str($in, 'kind', 16);
        if (!in_array($kind, Assets::KINDS, true)) {
            throw new ApiError('Kind must be identity or host.');
        }
        $ref = array_filter([
            'ad_user_id' => self::int($in, 'ad_user_id') ?: null,
            'identity_key' => self::str($in, 'identity_key', 600) ?: null,
            'account' => self::str($in, 'account', 300) ?: null,
            'host_asset_id' => self::int($in, 'host_asset_id') ?: null,
            'target' => self::str($in, 'target', 300) ?: null,
        ]);
        if ($kind === 'host' && empty($ref['host_asset_id']) && self::int($in, 'ad_computer_id') > 0) {
            // An AD computer opens the Security Center host it is correlated with.
            $ref['host_asset_id'] = (int) Db::value('SELECT host_asset_id FROM v_asset_correlation WHERE ad_computer_id = ? LIMIT 1', [self::int($in, 'ad_computer_id')]);
            if (!$ref['host_asset_id']) {
                throw new ApiError('This computer is not matched to a Security Center host, so it has no findings to analyse.', 404);
            }
        }
        if (!$ref) {
            throw new ApiError('Name the asset to sync.');
        }
        $trigger = self::str($in, 'trigger', 16);
        $trigger = in_array($trigger, ['panel', 'row', 'resync'], true) ? $trigger : 'manual';
        $t0 = microtime(true);
        try {
            $r = Assets::sync($kind, $ref, 'manual');
        } catch (Throwable $e) {
            Assets::logSingle($trigger, $kind . ' ' . json_encode($ref), null, $e->getMessage(), (int) round((microtime(true) - $t0) * 1000));
            throw $e;
        }
        Assets::logSingle($trigger, $r['name'], $r, null, $r['ms']);
        return $r;
    }

    public static function assetProfile(array $in): array
    {
        return Assets::get(self::int($in, 'id'));
    }

    public static function assetProfiles(array $in): array
    {
        $size = self::int($in, 'size', 50, 1, 200);
        $page = self::int($in, 'page', 1, 1);
        return Assets::list(self::str($in, 'type', 16), self::str($in, 'q', 200), $size, ($page - 1) * $size, self::str($in, 'sort', 16));
    }

    /** Whether a panel's object already has a snapshot (for the "Synced 5 min ago" hint). */
    public static function assetLookup(array $in): array
    {
        $kind = self::str($in, 'kind', 16);
        if ($kind === 'host') {
            $key = (string) (self::int($in, 'host_asset_id') ?: (int) Db::value('SELECT host_asset_id FROM v_asset_correlation WHERE ad_computer_id = ? LIMIT 1', [self::int($in, 'ad_computer_id')]));
        } else {
            $key = (string) (Db::value('SELECT object_key FROM ad_users WHERE id = ?', [self::int($in, 'ad_user_id')]) ?: self::str($in, 'identity_key', 600));
        }
        return ['profile' => $key !== '' && $key !== '0' ? Assets::find($kind, $key) : null];
    }

    /** One raw Nessus plugin output (the evidence behind an access fact), capped for the browser. */
    public static function pluginOutput(array $in): array
    {
        $row = Db::one(
            "SELECT o.id, o.host_asset_id, h.host_name, h.ip, o.plugin_id, COALESCE(c.name, '') AS plugin_name, o.port, o.protocol,
                    o.first_seen, o.last_seen, o.truncated, length(o.output) AS length, left(o.output, 300000) AS output
             FROM plugin_outputs o LEFT JOIN host_risk h ON h.host_asset_id = o.host_asset_id LEFT JOIN plugin_catalog c ON c.plugin_id = o.plugin_id
             WHERE o.id = ?",
            [self::int($in, 'id')]
        );
        if (!$row) {
            throw new ApiError('This plugin output is no longer stored; run plugin_collect again.', 404);
        }
        $row['clipped'] = (int) $row['length'] > 300000;
        return $row;
    }

    /** The Asset Sync page filters, from the request. */
    private static function syncFilters(array $in): array
    {
        return ['q' => self::str($in, 'q', 200), 'domain_id' => self::int($in, 'domain_id'), 'type' => self::str($in, 'type', 20),
                'tier' => self::str($in, 'tier', 8), 'status' => self::str($in, 'status', 12), 'reach' => self::str($in, 'reach', 8),
                'snapshot' => self::str($in, 'snapshot', 12), 'ou' => self::str($in, 'ou', 200)];
    }

    public static function assetSyncCandidates(array $in): array
    {
        $size = self::int($in, 'size', 50, 1, 200);
        $page = self::int($in, 'page', 1, 1);
        return Assets::candidates(self::syncFilters($in), $size, ($page - 1) * $size);
    }

    public static function assetSyncBatch(array $in): array
    {
        return Assets::syncBatch(self::syncFilters($in), self::int($in, 'after_id'), self::int($in, 'limit', 50, 1, 200), 'bulk', self::int($in, 'run_id'));
    }

    public static function assetSyncRunStart(array $in): array
    {
        return ['id' => Assets::runStart('bulk', self::str($in, 'label', 500), self::syncFilters($in), self::int($in, 'planned'))];
    }

    public static function assetSyncRunFinish(array $in): array
    {
        return Assets::runFinish(self::int($in, 'run_id'), self::str($in, 'status', 16));
    }

    public static function assetSyncRuns(array $in): array
    {
        $size = self::int($in, 'size', 25, 1, 100);
        $page = self::int($in, 'page', 1, 1);
        return Assets::runs(self::str($in, 'trigger', 16), $size, ($page - 1) * $size);
    }

    public static function assetSyncRun(array $in): array
    {
        return Assets::run(self::int($in, 'id'));
    }

    public static function assetSyncRunsClear(array $in): array
    {
        return ['ok' => true, 'deleted' => Assets::runsClear()];
    }

    public static function adDirectories(array $in): array
    {
        return ['rows' => Db::all("SELECT d.id AS domain_id, d.netbios_name, d.dns_name, count(u.id) AS users
                                   FROM ad_users u LEFT JOIN LATERAL (SELECT dd.id, dd.netbios_name, dd.dns_name FROM ad_domains dd WHERE dd.directory_id = u.directory_id OR (dd.nc_name IS NOT NULL AND lower(u.dn) LIKE '%' || lower(dd.nc_name)) ORDER BY (dd.nc_name IS NOT NULL AND lower(u.dn) LIKE '%' || lower(dd.nc_name)) DESC, (dd.directory_id = u.directory_id) DESC, length(dd.nc_name) DESC NULLS LAST LIMIT 1) d ON true
                                   GROUP BY 1, 2, 3 ORDER BY lower(d.netbios_name)")];
    }

    public static function assetDelete(array $in): array
    {
        return ['ok' => true, 'deleted' => Assets::delete(self::int($in, 'id'))];
    }
}
