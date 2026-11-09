<?php
declare(strict_types=1);

/**
 * The central asset table (asset_profiles): one analysed snapshot per identity or host.
 *
 * A snapshot is built on demand only — the "Sync asset details" button of a detail panel,
 * or Agent Tenable while it investigates an account or a host — and never in bulk, because
 * one sync reads every access fact and finding behind the asset. The Asset Details page
 * reads the stored snapshot, so it opens instantly and shows the state at sync time.
 */
final class Assets
{
    public const KINDS = ['identity', 'host'];
    private const MAX_SYSTEMS = 500;
    private const MAX_FINDINGS = 3000;

    // ---- public API ------------------------------------------------------------------------

    /**
     * Analyse one asset and store the snapshot. $ref for an identity: an AD user id
     * ("ad_user_id"), a telemetry identity key ("identity_key") or an account name
     * ("account"); for a host: "host_asset_id" or "target" (name or IP).
     */
    public static function sync(string $kind, array $ref, string $source = 'manual'): array
    {
        $t0 = microtime(true);
        $snap = $kind === 'host' ? self::hostSnapshot($ref) : self::identitySnapshot($ref);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $json = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $id = (int) Db::value(
            "INSERT INTO asset_profiles (asset_kind, asset_key, name, subtitle, ad_object_key, host_asset_id, tier, risk_score,
                                         profile, systems, vulnerabilities, risk, summary, source, sync_ms, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS jsonb), CAST(? AS jsonb), CAST(? AS jsonb), CAST(? AS jsonb), ?, ?, now())
             ON CONFLICT (asset_kind, asset_key) DO UPDATE SET
                name = EXCLUDED.name, subtitle = EXCLUDED.subtitle, ad_object_key = EXCLUDED.ad_object_key,
                host_asset_id = EXCLUDED.host_asset_id, tier = EXCLUDED.tier, risk_score = EXCLUDED.risk_score,
                profile = EXCLUDED.profile, systems = EXCLUDED.systems, vulnerabilities = EXCLUDED.vulnerabilities,
                risk = EXCLUDED.risk, summary = EXCLUDED.summary, source = EXCLUDED.source, sync_ms = EXCLUDED.sync_ms, synced_at = now()
             RETURNING id",
            [$kind, $snap['key'], $snap['name'], $snap['subtitle'], $snap['ad_object_key'], $snap['host_asset_id'], $snap['tier'],
             $snap['risk']['score'], $json($snap['profile']), $json($snap['systems']), $json($snap['vulnerabilities']),
             $json($snap['risk']), $json($snap['summary']), $source, $ms]
        );
        return ['id' => $id, 'kind' => $kind, 'key' => $snap['key'], 'name' => $snap['name'], 'ms' => $ms, 'url' => '/explore/assets/view?id=' . $id];
    }

    public static function get(int $id): array
    {
        $row = Db::one('SELECT * FROM asset_profiles WHERE id = ?', [$id]);
        if (!$row) {
            throw new ApiError('This asset has not been synced, or it was deleted.', 404);
        }
        foreach (['profile', 'systems', 'vulnerabilities', 'risk', 'summary'] as $k) {
            $row[$k] = json_decode((string) $row[$k], true);
        }
        return $row;
    }

    /** The stored snapshot for a kind + key, or null; lets a panel say "synced 5 min ago". */
    public static function find(string $kind, string $key): ?array
    {
        return Db::one('SELECT id, name, synced_at, source FROM asset_profiles WHERE asset_kind = ? AND asset_key = ?', [$kind, $key]);
    }

    /** user, service_account or host: the filter of the Assets page. */
    private const TYPE_SQL = "CASE WHEN asset_kind = 'host' THEN 'host' WHEN profile->>'kind' IN ('service', 'gmsa', 'service_account') THEN 'service_account' ELSE 'user' END";
    public const TYPES = ['user', 'service_account', 'host'];

    public static function list(string $type, string $q, int $limit, int $offset, string $sort): array
    {
        $where = ['TRUE'];
        $params = [];
        if (in_array($type, self::TYPES, true)) {
            $where[] = self::TYPE_SQL . ' = ?';
            $params[] = $type;
        }
        if ($q !== '') {
            $where[] = '(name ILIKE ? OR subtitle ILIKE ?)';
            array_push($params, '%' . addcslashes($q, '%_\\') . '%', '%' . addcslashes($q, '%_\\') . '%');
        }
        $order = ['synced' => 'synced_at DESC', 'risk' => 'risk_score DESC NULLS LAST, synced_at DESC', 'name' => 'lower(name)'][$sort] ?? 'synced_at DESC';
        $w = implode(' AND ', $where);
        $rows = Db::all(
            "SELECT id, asset_kind, " . self::TYPE_SQL . " AS asset_type, asset_key, name, subtitle, tier, risk_score, summary, source, sync_ms, synced_at,
                    (risk->>'level') AS risk_level, profile->>'mail' AS mail, profile->>'org_unit' AS org_unit, host_asset_id,
                    CASE WHEN asset_kind = 'host' THEN profile->>'os' END AS os
             FROM asset_profiles WHERE $w ORDER BY $order, id LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
        foreach ($rows as &$r) {
            $r['summary'] = json_decode((string) $r['summary'], true);
        }
        return ['total' => (int) Db::value("SELECT count(*) FROM asset_profiles WHERE $w", $params), 'rows' => $rows,
                'counts' => Db::one("SELECT count(*) AS all, count(*) FILTER (WHERE " . self::TYPE_SQL . " = 'user') AS user,
                                            count(*) FILTER (WHERE " . self::TYPE_SQL . " = 'service_account') AS service_account,
                                            count(*) FILTER (WHERE asset_kind = 'host') AS host FROM asset_profiles")];
    }

    public static function delete(int $id): int
    {
        return Db::run('DELETE FROM asset_profiles WHERE id = ?', [$id]);
    }

    // ---- bulk sync of AD users (Explore > Assets > Asset Sync) ---------------------------------

    /** Filters of the Asset Sync page -> [WHERE over ad_users u (with domain d), params]. */
    private static function candidateWhere(array $f): array
    {
        $w = ['TRUE'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            // same matching as Agent Tenable's find_identity: Turkish letters folded, and the term
            // without spaces or punctuation also matches ("aliokan" finds "Ali Okan Yuksel")
            $esc = fn ($v) => '%' . addcslashes($v, '%_\\') . '%';
            $words = array_values(array_filter(preg_split('/[\s,;]+/', Tools::fold($q))));
            $c = array_map(fn () => Tools::USER_SEARCH_FOLDED . ' LIKE ?', $words);
            $cond = '(' . implode(' AND ', $c) . ')';
            foreach ($words as $word) $p[] = $esc($word);
            $compact = Tools::compact($q);
            if (strlen($compact) >= 3) {
                $cond = "($cond OR " . Tools::USER_SEARCH_COMPACT . ' LIKE ?)';
                $p[] = $esc($compact);
            }
            $w[] = $cond;
        }
        if (!empty($f['domain_id'])) {
            $w[] = 'd.id = ?';
            $p[] = (int) $f['domain_id'];
        }
        $type = (string) ($f['type'] ?? '');
        if ($type === 'user') $w[] = "u.account_type = 'user'";
        elseif ($type === 'service_account') $w[] = "u.account_type <> 'user'";
        $tier = (string) ($f['tier'] ?? '');
        if (in_array($tier, ['0', '1', '2'], true)) {
            $w[] = 't.tier = ?';
            $p[] = (int) $tier;
        } elseif ($tier === 'none') {
            $w[] = 't.tier IS NULL';
        }
        $status = (string) ($f['status'] ?? '');
        if ($status === 'enabled') $w[] = 'u.enabled IS TRUE';
        elseif ($status === 'disabled') $w[] = 'u.enabled IS FALSE';
        $reach = (string) ($f['reach'] ?? '');
        $exists = "EXISTS (SELECT 1 FROM identity_host_access a WHERE a.ad_object_key = u.object_key AND a.access_class <> 'scan')";
        if ($reach === 'yes') $w[] = $exists;
        elseif ($reach === 'no') $w[] = "NOT $exists";
        $snap = (string) ($f['snapshot'] ?? '');
        $snapSql = "SELECT 1 FROM asset_profiles ap WHERE ap.asset_kind = 'identity' AND ap.asset_key = u.object_key";
        if ($snap === 'none') $w[] = "NOT EXISTS ($snapSql)";
        elseif ($snap === 'synced') $w[] = "EXISTS ($snapSql)";
        elseif ($snap === 'stale') $w[] = "EXISTS ($snapSql AND ap.synced_at < now() - interval '1 day')";
        $ou = trim((string) ($f['ou'] ?? ''));
        if ($ou !== '') {
            $w[] = 'u.dn ILIKE ?';
            $p[] = '%' . addcslashes($ou, '%_\\') . '%';
        }
        return [implode(' AND ', $w), $p];
    }

    private const CANDIDATE_FROM = "FROM ad_users u LEFT JOIN LATERAL (SELECT dd.id, dd.netbios_name, dd.dns_name FROM ad_domains dd WHERE dd.directory_id = u.directory_id OR (dd.nc_name IS NOT NULL AND lower(u.dn) LIKE '%' || lower(dd.nc_name)) ORDER BY (dd.nc_name IS NOT NULL AND lower(u.dn) LIKE '%' || lower(dd.nc_name)) DESC, (dd.directory_id = u.directory_id) DESC, length(dd.nc_name) DESC NULLS LAST LIMIT 1) d ON true LEFT JOIN v_ad_object_tier t ON t.object_key = u.object_key";

    /** Count, breakdown and one page of the AD users a filter selects. */
    public static function candidates(array $f, int $limit, int $offset): array
    {
        [$where, $params] = self::candidateWhere($f);
        $counts = Db::one(
            "SELECT count(*) AS total,
                    count(*) FILTER (WHERE EXISTS (SELECT 1 FROM identity_host_access a WHERE a.ad_object_key = u.object_key AND a.access_class <> 'scan')) AS with_access,
                    count(*) FILTER (WHERE EXISTS (SELECT 1 FROM asset_profiles ap WHERE ap.asset_kind = 'identity' AND ap.asset_key = u.object_key)) AS synced
             " . self::CANDIDATE_FROM . " WHERE $where",
            $params
        );
        $rows = Db::all(
            "SELECT u.id, u.sam_account_name, u.display_name, u.user_principal_name, u.account_type, u.enabled, d.netbios_name AS domain, t.tier,
                    (SELECT count(DISTINCT a.host_asset_id) FROM identity_host_access a WHERE a.ad_object_key = u.object_key AND a.access_class <> 'scan') AS hosts,
                    ap.id AS asset_id, ap.synced_at, ap.risk->>'level' AS risk_level, ap.risk_score
             " . self::CANDIDATE_FROM . "
             LEFT JOIN asset_profiles ap ON ap.asset_kind = 'identity' AND ap.asset_key = u.object_key
             WHERE $where ORDER BY hosts DESC, t.tier NULLS LAST, lower(u.sam_account_name), u.id LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
        $counts = array_map('intval', $counts);
        // measured: about 7 ms for a user without host access, about 40 ms with
        $counts['estimate_seconds'] = (int) ceil(($counts['with_access'] * 40 + ($counts['total'] - $counts['with_access']) * 7) / 1000);
        return ['counts' => $counts, 'rows' => $rows];
    }

    /**
     * Syncs the next batch of users a filter selects, in id order after $afterId. The page
     * calls it until `done`, which gives it progress and keeps every request short.
     */
    public static function syncBatch(array $f, int $afterId, int $limit, string $source = 'bulk', int $runId = 0): array
    {
        [$where, $params] = self::candidateWhere($f);
        $users = Db::all(
            "SELECT u.id, COALESCE(NULLIF(u.display_name, ''), u.sam_account_name) AS name, d.netbios_name AS domain, u.sam_account_name
             " . self::CANDIDATE_FROM . " WHERE $where AND u.id > ? ORDER BY u.id LIMIT ?",
            array_merge($params, [$afterId, $limit])
        );
        $t0 = microtime(true);
        $ok = 0;
        $errors = [];
        $items = [];
        foreach ($users as $u) {
            $t1 = microtime(true);
            try {
                $r = self::sync('identity', ['ad_user_id' => (int) $u['id']], $source);
                $ok++;
                $items[] = ['ok' => true, 'asset_id' => $r['id'], 'ad_user_id' => (int) $u['id'], 'name' => $r['name'], 'account' => ($u['domain'] ? $u['domain'] . '\\' : '') . $u['sam_account_name'], 'ms' => $r['ms']];
            } catch (Throwable $e) {
                $errors[] = ['id' => (int) $u['id'], 'error' => $e->getMessage()];
                $items[] = ['ok' => false, 'ad_user_id' => (int) $u['id'], 'name' => $u['name'], 'account' => ($u['domain'] ? $u['domain'] . '\\' : '') . $u['sam_account_name'],
                            'ms' => (int) round((microtime(true) - $t1) * 1000), 'error' => $e->getMessage()];
            }
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if ($runId > 0) {
            self::runRecord($runId, $items, $ok, count($errors), $ms);
        }
        $last = $users ? (int) end($users)['id'] : $afterId;
        return ['synced' => $ok, 'failed' => count($errors), 'errors' => array_slice($errors, 0, 10), 'last_id' => $last,
                'done' => count($users) < $limit, 'ms' => $ms];
    }

    // ---- sync history (asset_sync_runs) ------------------------------------------------------------
    public const TRIGGERS = ['bulk', 'panel', 'row', 'resync', 'agent', 'manual'];
    private const RUN_STATUSES = ['done', 'cancelled', 'stopped'];

    public static function runStart(string $trigger, string $label, array $filters, int $planned): int
    {
        return (int) Db::value(
            'INSERT INTO asset_sync_runs (trigger, label, filters, planned) VALUES (?, ?, CAST(? AS jsonb), ?) RETURNING id',
            [in_array($trigger, self::TRIGGERS, true) ? $trigger : 'manual', mb_substr($label, 0, 500), json_encode((object) array_filter($filters, fn ($v) => $v !== '' && $v !== 0 && $v !== null)), max(0, $planned)]
        );
    }

    private static function runRecord(int $runId, array $items, int $ok, int $failed, int $ms): void
    {
        Db::run(
            "UPDATE asset_sync_runs SET synced = synced + ?, failed = failed + ?, work_ms = work_ms + ?, updated_at = now(),
                    items = items || CAST(? AS jsonb) WHERE id = ? AND status = 'running'",
            [$ok, $failed, $ms, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $runId]
        );
    }

    public static function runFinish(int $runId, string $status): array
    {
        $status = in_array($status, self::RUN_STATUSES, true) ? $status : 'done';
        Db::run("UPDATE asset_sync_runs SET status = ?, finished_at = now(), updated_at = now() WHERE id = ? AND status = 'running'", [$status, $runId]);
        return self::run($runId, false);
    }

    /** A single sync (panel, row, re-sync, Agent Tenable) as a run of one, so History shows every sync. */
    public static function logSingle(string $trigger, string $label, ?array $result, ?string $error, int $ms): void
    {
        try {
            $item = $result ? ['ok' => true, 'asset_id' => $result['id'], 'name' => $result['name'], 'kind' => $result['kind'], 'ms' => $result['ms']]
                            : ['ok' => false, 'name' => $label, 'ms' => $ms, 'error' => $error];
            Db::run(
                "INSERT INTO asset_sync_runs (trigger, label, status, planned, synced, failed, work_ms, items, finished_at)
                 VALUES (?, ?, ?, 1, ?, ?, ?, CAST(? AS jsonb), now())",
                [in_array($trigger, self::TRIGGERS, true) ? $trigger : 'manual', mb_substr($result['name'] ?? $label, 0, 500),
                 $result ? 'done' : 'stopped', $result ? 1 : 0, $result ? 0 : 1, $ms, json_encode([$item], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
        } catch (Throwable $e) {
            error_log('asset sync history: ' . $e->getMessage());   // history must never break a sync
        }
    }

    /** A run still "running" with no progress for two minutes was left by a closed tab. */
    private const RUN_STATUS_SQL = "CASE WHEN status = 'running' AND updated_at < now() - interval '2 minutes' THEN 'interrupted' ELSE status END";

    public static function runs(string $trigger, int $limit, int $offset): array
    {
        $where = $trigger === 'single' ? "trigger <> 'bulk'" : (in_array($trigger, self::TRIGGERS, true) ? 'trigger = ?' : 'TRUE');
        $params = in_array($trigger, self::TRIGGERS, true) ? [$trigger] : [];
        $rows = Db::all(
            "SELECT id, trigger, label, filters, " . self::RUN_STATUS_SQL . " AS status, planned, synced, failed, work_ms, started_at, finished_at,
                    EXTRACT(EPOCH FROM (COALESCE(finished_at, updated_at) - started_at)) * 1000 AS elapsed_ms
             FROM asset_sync_runs WHERE $where ORDER BY started_at DESC, id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
        foreach ($rows as &$r) {
            $r['filters'] = json_decode((string) $r['filters'], true);
            $r['elapsed_ms'] = (int) round((float) $r['elapsed_ms']);
        }
        $totals = Db::one(
            "SELECT count(*) AS runs, count(*) FILTER (WHERE trigger = 'bulk') AS bulk_runs, COALESCE(sum(synced), 0) AS synced, COALESCE(sum(failed), 0) AS failed,
                    COALESCE(sum(work_ms), 0) AS work_ms FROM asset_sync_runs WHERE $where", $params);
        return ['total' => (int) $totals['runs'], 'totals' => array_map('intval', $totals), 'rows' => $rows];
    }

    public static function run(int $id, bool $withItems = true): array
    {
        $r = Db::one(
            "SELECT id, trigger, label, filters, " . self::RUN_STATUS_SQL . " AS status, planned, synced, failed, work_ms, started_at, finished_at, updated_at,
                    EXTRACT(EPOCH FROM (COALESCE(finished_at, updated_at) - started_at)) * 1000 AS elapsed_ms" . ($withItems ? ', items' : '') . "
             FROM asset_sync_runs WHERE id = ?", [$id]);
        if (!$r) {
            throw new ApiError('This sync run is not in the history.', 404);
        }
        $r['filters'] = json_decode((string) $r['filters'], true);
        $r['elapsed_ms'] = (int) round((float) $r['elapsed_ms']);
        if ($withItems) {
            $r['items'] = json_decode((string) $r['items'], true) ?: [];
        }
        return $r;
    }

    public static function runsClear(): int
    {
        return Db::run('DELETE FROM asset_sync_runs', []);
    }

    // ---- identity ------------------------------------------------------------------------------

    private static function identitySnapshot(array $ref): array
    {
        if (!empty($ref['ad_user_id'])) {
            $account = '#ad:' . (int) $ref['ad_user_id'];
        } elseif (!empty($ref['identity_key'])) {
            $account = '#key:' . $ref['identity_key'];
        } else {
            $account = trim((string) ($ref['account'] ?? ''));
        }
        if ($account === '') {
            throw new ApiError('Name the account to sync.');
        }
        $r = Tools::resolveIdentity($account);
        $ad = $r['ad'];
        $acr = (float) (Db::setting('ctem')['acr_threshold'] ?? 7);

        if ($ad) {
            $profileTool = Tools::run('identity_profile', ['account' => '#ad:' . $ad['id']]);
            $profile = $profileTool['account'] + ['groups' => $profileTool['groups'], 'kind' => $ad['account_type'], 'in_ad' => true];
            $key = (string) $ad['object_key'];
            $name = (string) ($ad['display_name'] ?: $ad['sam_account_name']);
            $subtitle = ($ad['domain'] ? $ad['domain'] . '\\' : '') . $ad['sam_account_name'];
        } else {
            $first = Db::one('SELECT a.identity_key, a.identity_name, a.identity_kind, a.scope FROM v_risk_access a WHERE ' . $r['where'] . ' LIMIT 1', $r['params']);
            if (!$first) {
                throw new ApiError("No AD account or host telemetry matches '$account'.", 404);
            }
            $key = (string) $first['identity_key'];
            $name = (string) $first['identity_name'];
            $subtitle = 'Seen in host telemetry only';
            $profile = ['account' => $first['identity_name'], 'kind' => $first['identity_kind'], 'scope' => $first['scope'], 'in_ad' => false,
                        'note' => 'This identity appears on hosts (local groups, sessions, services) but has no match in the synced Active Directory.'];
        }

        $systems = Db::all(
            "SELECT a.host_asset_id, a.host_name, a.ip, split_part(a.os, E'\\n', 1) AS os, a.access_type, a.access_class,
                    a.identity_name, a.via_group_key, g.name AS via_group, a.evidence, a.confidence, a.last_seen,
                    a.critical, a.high, a.max_vpr, a.exploitable, a.acr_score, a.aes_score, a.host_tier, a.credentialed,
                    " . self::EVIDENCE_COLUMNS . "
             FROM v_risk_access a LEFT JOIN LATERAL (SELECT o2.name, o2.sam_account_name, d2.netbios_name AS domain FROM v_ad_objects o2 LEFT JOIN ad_domains d2 ON d2.directory_id = o2.directory_id WHERE o2.object_key = a.via_group_key LIMIT 1) g ON true " . self::EVIDENCE_JOINS . "
             WHERE {$r['where']}
             ORDER BY a.critical DESC, a.max_vpr DESC NULLS LAST, a.host_name, a.access_type LIMIT " . self::MAX_SYSTEMS,
            $r['params']
        );
        self::attachEvidence($systems, $ad ? (string) ($ad['display_name'] ?: $ad['sam_account_name']) : null);
        $hostIds = array_values(array_unique(array_map(fn ($s) => (int) $s['host_asset_id'], $systems)));
        $findings = self::findings($hostIds);

        // One row per host for the breakdowns (a host reached three ways counts once).
        $hosts = [];
        foreach ($systems as $s) {
            $h = &$hosts[(int) $s['host_asset_id']];
            $h ??= $s + ['access' => []];
            $h['access'][] = $s['access_type'];
            unset($h);
        }
        $risk = self::identityRisk($profile, $systems, $hosts, $findings, $acr);
        $profile['connections'] = self::connections($systems, 'host_asset_id', $ad ? (string) ($ad['display_name'] ?: $ad['sam_account_name']) : $name);
        $summary = self::summary($hosts, $findings, $acr) + ['access_facts' => count($systems), 'groups' => count($profile['groups'] ?? [])];

        return ['key' => $key, 'name' => $name, 'subtitle' => $subtitle, 'ad_object_key' => $ad['object_key'] ?? null, 'host_asset_id' => null,
                'tier' => isset($ad['tier']) ? (int) $ad['tier'] : null, 'profile' => $profile, 'systems' => $systems,
                'vulnerabilities' => $findings, 'risk' => $risk, 'summary' => $summary];
    }

    private static function identityRisk(array $profile, array $systems, array $hosts, array $findings, float $acr): array
    {
        $tier = $profile['tier'] ?? null;
        $factors = [];
        $add = function (string $level, string $title, string $detail) use (&$factors) { $factors[] = ['level' => $level, 'title' => $title, 'detail' => $detail]; };

        $critHosts = array_filter($hosts, fn ($h) => (int) $h['critical'] > 0);
        $acrHosts = array_filter($hosts, fn ($h) => (float) $h['acr_score'] >= $acr);
        $rightCrit = array_filter($systems, fn ($s) => $s['access_class'] === 'right' && (int) $s['critical'] > 0);
        $usage = array_filter($systems, fn ($s) => $s['access_class'] === 'usage');
        $usageOutsideT0 = array_filter($usage, fn ($s) => $s['host_tier'] === null || (int) $s['host_tier'] > 0);
        $exploitHosts = array_filter($hosts, fn ($h) => (int) $h['exploitable'] > 0);
        $uncred = array_filter($hosts, fn ($h) => $h['credentialed'] === false || $h['credentialed'] === 'f');

        if ($tier === 0) $add('critical', 'Tier 0 account', 'Controls the domain' . (!empty($profile['tier_detail']['path']) ? ' through ' . implode(' → ', array_map(fn ($p) => $p['to'], $profile['tier_detail']['path'])) : '') . '.');
        elseif ($tier === 1) $add('high', 'Tier 1 account', 'Administers servers or applications.');
        if ($tier === 0 && $usageOutsideT0) $add('critical', 'Tier 0 credentials exposed outside Tier 0', count(array_unique(array_column($usageOutsideT0, 'host_name'))) . ' host(s) where the account logged on or ran a session are not Tier 0; credentials can be harvested there.');
        if ($rightCrit) $add('critical', 'Admin or remote rights on vulnerable hosts', count(array_unique(array_column($rightCrit, 'host_name'))) . ' host(s) with Critical findings that this account can administer or log on to.');
        elseif ($critHosts) $add('high', 'Reaches hosts with Critical vulnerabilities', count($critHosts) . ' of ' . count($hosts) . ' reached hosts have Critical findings.');
        if ($acrHosts) $add('high', 'Reaches business-critical hosts', count($acrHosts) . " host(s) with ACR ≥ $acr.");
        if ($exploitHosts) $add('high', 'Known exploits on reached hosts', count($exploitHosts) . ' host(s) have findings with a public exploit.');
        $pf = $profile['password_findings'] ?? null;
        if (is_array($pf) && !empty($pf['isbreached'])) $add('critical', 'Password found in a breach corpus', 'Identity Exposure matched the password hash against known breached passwords.');
        if (is_array($pf) && !empty($pf['isweak'])) $add('high', 'Weak password', 'Identity Exposure flagged the password as weak.');
        if (($profile['password_age_days'] ?? 0) > 365) $add('medium', 'Old password', 'Last changed ' . $profile['password_age_days'] . ' days ago.');
        if (!empty($profile['service_principal_names'])) $add('high', 'Kerberoastable', 'Has ' . count($profile['service_principal_names']) . ' SPN(s); any domain user can request a ticket and crack it offline.');
        if (in_array('DONT_EXPIRE_PASSWORD', $profile['uac_flags'] ?? [], true)) $add('medium', 'Password never expires', 'userAccountControl has DONT_EXPIRE_PASSWORD.');
        if (in_array('PASSWD_NOTREQD', $profile['uac_flags'] ?? [], true)) $add('high', 'Password not required', 'userAccountControl has PASSWD_NOTREQD.');
        if (!empty($profile['allowed_to_delegate_to'])) $add('high', 'Constrained delegation', 'Can impersonate users to: ' . implode(', ', (array) $profile['allowed_to_delegate_to']) . '.');
        if (array_filter($systems, fn ($s) => $s['access_type'] === 'service_account' && (int) $s['critical'] > 0)) $add('high', 'Runs services on vulnerable hosts', 'A compromise of the host exposes this account\'s credentials.');
        if (($profile['enabled'] ?? null) === false && $hosts) $add('medium', 'Disabled but still has access', 'The account is disabled in AD yet still appears on hosts.');
        if ($uncred) $add('info', 'Incomplete telemetry', count($uncred) . ' reached host(s) were scanned without credentials; sessions and local groups there are unknown.');
        if (!$hosts) $add('info', 'No host access seen', 'No local group, session, logon or service ties this identity to a scanned host.');
        $demo = array_filter($systems, fn ($s) => ($s['match_quality'] ?? '') === 'demo alias');
        if ($demo) $add('info', 'Relies on the demo identity mapping', count($demo) . ' of ' . count($systems) . ' access facts exist only because of an alias in the identity_mapping settings; see Correlation Evidence.');

        return self::riskBlock($factors, $hosts, $findings, $acr) + [
            'questions' => [
                ['question' => 'Reaches hosts with Critical vulnerabilities', 'answer' => (bool) $critHosts, 'count' => count($critHosts)],
                ['question' => "Reaches high-ACR hosts (ACR ≥ $acr)", 'answer' => (bool) $acrHosts, 'count' => count($acrHosts)],
                ['question' => 'Tier 0 account used outside Tier 0', 'answer' => $tier === 0 && (bool) $usageOutsideT0, 'count' => $tier === 0 ? count(array_unique(array_column($usageOutsideT0, 'host_name'))) : 0],
                ['question' => 'Runs services on hosts with Critical vulnerabilities', 'answer' => (bool) array_filter($systems, fn ($s) => $s['access_type'] === 'service_account' && (int) $s['critical'] > 0), 'count' => count(array_filter($systems, fn ($s) => $s['access_type'] === 'service_account' && (int) $s['critical'] > 0))],
            ],
            'by_access_type' => self::countBy($systems, 'access_type'),
            'by_access_class' => self::countBy($systems, 'access_class'),
        ];
    }

    // ---- host ----------------------------------------------------------------------------------

    private static function hostSnapshot(array $ref): array
    {
        $id = (int) ($ref['host_asset_id'] ?? 0);
        if (!$id && !empty($ref['target'])) {
            [$sql, $params] = Tools::hostSet((string) $ref['target']);
            $id = (int) Db::value("SELECT h.host_asset_id FROM host_risk h WHERE h.host_asset_id IN ($sql) ORDER BY h.critical DESC LIMIT 1", $params);
        }
        $host = $id ? Db::one(
            "SELECT h.id AS host_asset_id, h.repository_name, host(h.ip) AS ip, h.dns_name, h.netbios_name, h.mac_address, h.os, h.device_type, h.asset_class,
                    h.domain_joined, h.domain_name, h.ad_matched, h.ad_sam_account_name, h.acr_score, h.aes_score, h.first_seen, h.last_seen, h.host_uuid, h.agent_uuid,
                    r.host_name, r.host_tier, r.critical, r.high, r.medium, r.low, r.max_vpr, r.exploitable, r.credentialed,
                    c.ad_dn, k.id AS ad_computer_id, k.os AS ad_os, k.last_logon AS ad_last_logon, k.enabled AS ad_enabled
             FROM v_host_assets h LEFT JOIN host_risk r ON r.host_asset_id = h.id
             LEFT JOIN v_asset_correlation c ON c.host_asset_id = h.id LEFT JOIN ad_computers k ON k.id = c.ad_computer_id
             WHERE h.id = ?", [$id]) : null;
        if (!$host) {
            throw new ApiError('This host does not exist.', 404);
        }
        $tool = Tools::run('host_identities', ['target' => (string) ($host['host_name'] ?: $host['ip']), 'limit' => 150]);
        $sameHost = isset($tool['host']['host_asset_id']) && (int) $tool['host']['host_asset_id'] === $id;
        $profile = $host + ['org_unit' => Tools::orgUnit($host['ad_dn']),
                            'local_accounts' => $sameHost ? $tool['local_accounts'] : [], 'services' => $sameHost ? $tool['services'] : []];
        $systems = Db::all(
            "SELECT a.identity_key, a.identity_name, a.identity_kind, a.scope, a.tier, a.tier0_like, a.privileged_hint, a.access_type, a.access_class,
                    a.via_group_key, g.name AS via_group, a.evidence, a.confidence, a.last_seen, o.id AS ad_object_id, o.object_type,
                    a.host_asset_id, a.host_name, a.ip, " . self::EVIDENCE_COLUMNS . ",
                    (SELECT count(DISTINCT b.host_asset_id) FROM identity_host_access b WHERE b.identity_key = a.identity_key AND b.host_asset_id <> a.host_asset_id AND b.access_class <> 'scan') AS other_hosts
             FROM v_risk_access a LEFT JOIN v_ad_objects o ON o.object_key = a.ad_object_key LEFT JOIN LATERAL (SELECT o2.name, o2.sam_account_name, d2.netbios_name AS domain FROM v_ad_objects o2 LEFT JOIN ad_domains d2 ON d2.directory_id = o2.directory_id WHERE o2.object_key = a.via_group_key LIMIT 1) g ON true
             " . self::EVIDENCE_JOINS . "
             WHERE a.host_asset_id = ? AND a.access_class <> 'scan'
             ORDER BY a.tier0_like DESC, a.tier NULLS LAST, a.access_class, a.identity_name LIMIT " . self::MAX_SYSTEMS,
            [$id]
        );
        self::attachEvidence($systems);
        $findings = self::findings([$id]);
        $acr = (float) (Db::setting('ctem')['acr_threshold'] ?? 7);
        $hosts = [$id => $host + ['access' => []]];

        $factors = [];
        $add = function (string $level, string $title, string $detail) use (&$factors) { $factors[] = ['level' => $level, 'title' => $title, 'detail' => $detail]; };
        if ($host['host_tier'] !== null && (int) $host['host_tier'] === 0) $add('critical', 'Tier 0 host', 'A compromise gives control of the domain.');
        if ((float) $host['acr_score'] >= $acr) $add('high', 'Business-critical host', 'ACR ' . $host['acr_score'] . " (threshold $acr).");
        if ((int) $host['critical'] > 0) $add('critical', 'Critical vulnerabilities', $host['critical'] . ' Critical and ' . $host['high'] . ' High findings, max VPR ' . $host['max_vpr'] . '.');
        if ((int) $host['exploitable'] > 0) $add('high', 'Publicly exploitable', $host['exploitable'] . ' findings have a known exploit.');
        $t0 = array_filter($systems, fn ($s) => $s['tier0_like'] === true || $s['tier0_like'] === 't');
        $t0used = array_filter($t0, fn ($s) => $s['access_class'] === 'usage');
        if ($t0used && ((int) ($host['host_tier'] ?? 9)) !== 0) $add('critical', 'Tier 0 credentials on a non-Tier-0 host', count(array_unique(array_column($t0used, 'identity_name'))) . ' Tier 0 identity(ies) logged on here; their credentials can be harvested.');
        $admins = array_filter($systems, fn ($s) => $s['access_type'] === 'local_admin');
        if (count(array_unique(array_column($admins, 'identity_key'))) > 10) $add('medium', 'Many local administrators', count(array_unique(array_column($admins, 'identity_key'))) . ' identities are local administrators.');
        $svc = array_filter($systems, fn ($s) => $s['access_type'] === 'service_account' && $s['scope'] === 'domain');
        if ($svc) $add('high', 'Domain accounts run services here', implode(', ', array_unique(array_column($svc, 'identity_name'))) . '.');
        if ($host['credentialed'] === false || $host['credentialed'] === 'f') $add('info', 'Not scanned with credentials', 'Sessions, local groups and services are unknown.');
        if (!$host['domain_joined']) $add('info', 'Not domain joined', 'Identity correlation relies on local accounts only.');
        $demo = array_filter($systems, fn ($s) => ($s['match_quality'] ?? '') === 'demo alias');
        if ($demo) $add('info', 'Relies on the demo identity mapping', count($demo) . ' of ' . count($systems) . ' access facts exist only because of an alias in the identity_mapping settings; see Correlation Evidence.');

        $profile['connections'] = self::connections($systems, 'identity_key', null);
        $risk = self::riskBlock($factors, $hosts, $findings, $acr) + [
            'by_access_type' => self::countBy($systems, 'access_type'),
            'by_identity_kind' => self::countBy($systems, 'identity_kind'),
            'by_port' => array_slice(self::countBy($findings, 'port_label'), 0, 15),
        ];
        $summary = self::summary($hosts, $findings, $acr) + [
            'identities' => count(array_unique(array_column($systems, 'identity_key'))),
            'tier0_identities' => count(array_unique(array_column($t0, 'identity_key'))),
            'local_accounts' => count($profile['local_accounts']), 'services' => count($profile['services']),
        ];
        $name = (string) ($host['host_name'] ?: $host['dns_name'] ?: $host['ip']);
        return ['key' => (string) $id, 'name' => $name, 'subtitle' => trim(($host['ip'] ?? '') . ' · ' . strtok((string) $host['os'], "\n"), ' ·'),
                'ad_object_key' => null, 'host_asset_id' => $id, 'tier' => $host['host_tier'] !== null ? (int) $host['host_tier'] : null,
                'profile' => $profile, 'systems' => $systems, 'vulnerabilities' => $findings, 'risk' => $risk, 'summary' => $summary];
    }

    // ---- shared --------------------------------------------------------------------------------

    /** The plugin and the raw account name behind each access fact (identity_host_access.principal_id). */
    /**
     * The correlation behind each access fact. For a right that comes through an AD group the
     * fact itself has no principal; the group's own fact on the same host carries it (gh/gpr).
     */
    private const EVIDENCE_COLUMNS = "a.evidence_plugin_id, pc.name AS plugin_name, a.ad_object_key AS subject_key,
                    COALESCE(pr.name, gpr.name) AS principal_name, COALESCE(pr.domain, gpr.domain) AS principal_domain,
                    COALESCE(pr.kind, gpr.kind) AS principal_kind, COALESCE(pr.resolved_by, gpr.resolved_by) AS resolved_by,
                    mo.name AS matched_name, mo.object_type AS matched_type, mo.domain AS matched_domain,
                    g.sam_account_name AS via_group_sam, g.domain AS via_group_domain";
    private const EVIDENCE_JOINS = "LEFT JOIN identity_host_access ih ON ih.id = a.id LEFT JOIN principals pr ON pr.id = ih.principal_id
             LEFT JOIN identity_host_access gh ON a.via_group_key IS NOT NULL AND gh.identity_key = a.via_group_key
                                               AND gh.host_asset_id = a.host_asset_id AND gh.access_type = a.access_type
             LEFT JOIN principals gpr ON gpr.id = gh.principal_id
             LEFT JOIN LATERAL (SELECT o3.name, o3.object_type, d3.netbios_name AS domain FROM v_ad_objects o3
                                LEFT JOIN ad_domains d3 ON d3.directory_id = o3.directory_id
                                WHERE o3.object_key = COALESCE(pr.ad_object_key, gpr.ad_object_key) LIMIT 1) mo ON true
             LEFT JOIN plugin_catalog pc ON pc.plugin_id = a.evidence_plugin_id";
    private const EXCERPT_MAX = 900;

    /**
     * Adds plugin_output_id and output_excerpt to each access fact: the lines of the Nessus
     * plugin output that name the account (or the group the right comes through), with the
     * section header above them. The full output is fetched on demand (plugin_output action).
     */
    private static function attachEvidence(array &$systems, ?string $subject = null): void
    {
        $hosts = $plugins = [];
        foreach ($systems as $s) {
            if ($s['evidence_plugin_id']) {
                $hosts[(int) $s['host_asset_id']] = true;
                $plugins[(int) $s['evidence_plugin_id']] = true;
            }
        }
        $outputs = [];
        if ($hosts) {
            $rows = Db::all(
                'SELECT DISTINCT ON (host_asset_id, plugin_id) id, host_asset_id, plugin_id, output
                 FROM plugin_outputs WHERE host_asset_id = ANY(CAST(? AS bigint[])) AND plugin_id = ANY(CAST(? AS int[]))
                 ORDER BY host_asset_id, plugin_id, port',
                ['{' . implode(',', array_keys($hosts)) . '}', '{' . implode(',', array_keys($plugins)) . '}']
            );
            foreach ($rows as $o) {
                $outputs[$o['host_asset_id'] . ':' . $o['plugin_id']] = $o;
            }
        }
        foreach ($systems as &$s) {
            $o = $outputs[$s['host_asset_id'] . ':' . $s['evidence_plugin_id']] ?? null;
            $s['plugin_output_id'] = $o ? (int) $o['id'] : null;
            $needles = array_filter([$s['principal_name'] ?? null, $s['via_group_sam'] ?? null, $s['via_group'] ?? null]);
            if (!$needles && !empty($s['identity_name'])) {
                $needles[] = preg_replace('/^.*\\\\/', '', (string) $s['identity_name']);
            }
            $s['output_excerpt'] = $o ? self::excerpt((string) $o['output'], $needles) : null;
        }
        unset($s);
        self::attachCorrelation($systems, $subject);
    }

    private const ACCESS_PHRASE = [
        'local_admin' => 'is a member of the local Administrators group', 'rdp_right' => 'is a member of the local Remote Desktop Users group',
        'psremote_right' => 'is a member of the local Remote Management Users group', 'session' => 'had an interactive session',
        'last_logon' => 'was the last account to log on', 'smb_session' => 'had an SMB session', 'service_account' => 'runs a Windows service',
    ];
    private const ACCESS_RESULT = [
        'local_admin' => 'has local administrator rights on', 'rdp_right' => 'can log on over Remote Desktop to',
        'psremote_right' => 'can use PowerShell remoting on', 'session' => 'had a session on', 'last_logon' => 'last logged on to',
        'smb_session' => 'had an SMB session on', 'service_account' => 'runs a service on',
    ];

    /**
     * Writes, for each access fact, how the correlation reached it: what the plugin output
     * shows, how that name was matched to AD (and whether a demo alias did it), the AD
     * membership path for a right held through a group, a one-paragraph explanation and a
     * match quality. $subject names the identity when the snapshot is about one.
     */
    private static function attachCorrelation(array &$systems, ?string $subject): void
    {
        $mapping = Db::setting('identity_mapping');
        $domainAliases = array_change_key_case((array) ($mapping['domain_aliases'] ?? []), CASE_LOWER);

        // AD membership paths subject -> via group, one recursive query for the whole snapshot
        $pairs = [];
        foreach ($systems as $s) {
            if (!empty($s['via_group_key']) && !empty($s['subject_key'])) {
                $pairs[$s['subject_key']][$s['via_group_key']] = true;
            }
        }
        $paths = [];
        if ($pairs) {
            $members = array_keys($pairs);
            $groups = array_keys(array_merge(...array_values($pairs)));
            $rows = Db::all(
                "WITH RECURSIVE up(member_key, group_key, path, depth) AS (
                    SELECT gm.member_key, gm.group_key, ARRAY[gm.member_key, gm.group_key]::text[], 1
                    FROM ad_group_members gm WHERE gm.member_key = ANY(CAST(? AS text[]))
                    UNION ALL
                    SELECT up.member_key, gm.group_key, up.path || gm.group_key::text, up.depth + 1
                    FROM up JOIN ad_group_members gm ON gm.member_key = up.group_key
                    WHERE up.depth < 10 AND NOT gm.group_key = ANY(up.path))
                 SELECT DISTINCT ON (member_key, group_key) member_key, group_key, array_to_string(path, chr(31)) AS path
                 FROM up WHERE group_key = ANY(CAST(? AS text[])) ORDER BY member_key, group_key, depth",
                [self::pgTextArray($members), self::pgTextArray($groups)]
            );
            $keys = [];
            foreach ($rows as $r) {
                $paths[$r['member_key'] . '|' . $r['group_key']] = explode("\x1f", $r['path']);
                foreach (explode("\x1f", $r['path']) as $k) $keys[$k] = true;
            }
            $names = [];
            if ($keys) {
                foreach (Db::all('SELECT DISTINCT ON (object_key) object_key, name FROM v_ad_objects WHERE object_key = ANY(CAST(? AS text[]))', [self::pgTextArray(array_keys($keys))]) as $n) {
                    $names[$n['object_key']] = $n['name'];
                }
            }
            foreach ($paths as $k => $p) {
                $paths[$k] = array_map(fn ($key) => $names[$key] ?? $key, $p);
            }
        }

        foreach ($systems as &$s) {
            $who = $subject ?? (string) $s['identity_name'];
            $host = (string) ($s['host_name'] ?? '');
            $type = (string) $s['access_type'];
            $observed = $s['principal_name'] !== null ? (($s['principal_domain'] ? $s['principal_domain'] . '\\' : '') . $s['principal_name']) : null;
            $matched = $s['matched_name'] !== null ? (($s['matched_domain'] ? $s['matched_domain'] . '\\' : '') . $s['matched_name']) : null;
            $phrase = self::ACCESS_PHRASE[$type] ?? str_replace('_', ' ', $type);
            $plugin = $s['evidence_plugin_id'] ? 'Nessus plugin ' . $s['evidence_plugin_id'] . ($s['plugin_name'] ? ' (' . $s['plugin_name'] . ')' : '') : 'the host telemetry';
            $steps = [];

            // 1. what the scan saw
            $steps[] = ['kind' => 'observed', 'title' => 'Seen on the host',
                        'text' => "$plugin on $host reports " . ($observed ?? 'the account') . " — it $phrase."];

            // 2. how that name became an AD object
            $method = $s['resolved_by'];
            $pdom = strtolower(explode('.', (string) $s['principal_domain'])[0]);
            $viaDomainAlias = $method === 'domain_name' && $pdom !== '' && isset($domainAliases[$pdom])
                && strtolower((string) $s['matched_domain']) === strtolower((string) $domainAliases[$pdom]);
            if ($method === 'alias') {
                $quality = 'demo alias';
                $how = "$observed is mapped to $matched by an identity alias in the identity_mapping settings (demo data; the names differ on purpose).";
            } elseif ($viaDomainAlias) {
                $quality = 'demo alias';
                $how = "$observed was matched to $matched by account name, after the domain alias " . strtoupper($pdom) . ' → ' . strtoupper((string) $domainAliases[$pdom])
                     . ' in the identity_mapping settings (demo data; without the alias there would be no match).';
            } elseif ($method === 'sid') {
                $quality = 'exact';
                $how = "$observed was matched to $matched by SID, an exact match.";
            } elseif ($method === 'domain_name') {
                $quality = 'name';
                $how = "$observed was matched to $matched by domain and account name.";
            } elseif ($method === 'upn') {
                $quality = 'name';
                $how = "$observed was matched to $matched by user principal name.";
            } elseif ($method === 'host_domain') {
                $quality = 'inferred';
                $how = "The output gives the bare name $observed; it was matched to $matched in the domain the host belongs to.";
            } else {
                $quality = 'telemetry only';
                $how = ($observed ?? 'This identity') . ' has no match in the synced Active Directory; it is known only from host telemetry.';
            }
            $steps[] = ['kind' => 'matched', 'title' => 'Matched to Active Directory', 'text' => $how, 'method' => $method];

            // 3. a right held through a group: the AD membership that links the subject to it
            $group = $s['via_group'] ?? null;
            $groupFull = $group ? (($s['via_group_domain'] ? $s['via_group_domain'] . '\\' : '') . $group) : null;
            $path = $group ? ($paths[($s['subject_key'] ?? '') . '|' . $s['via_group_key']] ?? null) : null;
            if ($group) {
                $nested = $path && count($path) > 2;
                $steps[] = ['kind' => 'membership', 'title' => 'Group membership in Active Directory',
                            'text' => "$who is " . ($nested ? 'a nested' : 'a direct') . " member of $groupFull, from the Identity Exposure membership data"
                                    . ($path ? ': ' . implode(' → ', $path) . '.' : '.'),
                            'path' => $path];
                $s['explanation'] = "$who is a member of $groupFull" . ($nested ? ' (nested, through ' . implode(' → ', array_slice($path, 1, -1)) . ')' : '')
                    . "; $groupFull $phrase on $host. $who's name is not in the plugin output: the link comes from Active Directory group membership"
                    . ($quality === 'demo alias' ? ', and the group in the output was tied to this directory by a demo alias.' : '.');
            } elseif ($quality === 'demo alias') {
                $s['explanation'] = "$host reports $observed, which $phrase there. $observed is mapped to $who by the demo identity mapping, so the name in the output differs from the AD account.";
            } elseif ($quality === 'telemetry only') {
                $s['explanation'] = "$host reports $who, which $phrase there. The identity is not in the synced Active Directory.";
            } else {
                $s['explanation'] = "$host reports $observed, which $phrase there; $observed is $who in Active Directory ($quality match).";
            }
            $steps[] = ['kind' => 'result', 'title' => 'Result', 'text' => "$who " . (self::ACCESS_RESULT[$type] ?? $type . ' on') . " $host" . ($group ? " through $groupFull." : '.')];
            $s['correlation'] = $steps;
            $s['match_quality'] = $quality;
        }
    }

    private const QUALITY_ORDER = ['demo alias' => 0, 'telemetry only' => 1, 'inferred' => 2, 'name' => 3, 'exact' => 4];
    private const ACCESS_NOUN = [
        'local_admin' => 'local administrator', 'rdp_right' => 'Remote Desktop right', 'psremote_right' => 'PowerShell remoting right',
        'session' => 'interactive session', 'last_logon' => 'last logon', 'smb_session' => 'SMB session', 'service_account' => 'service it runs',
    ];

    /**
     * One entry per connected system (identity snapshot, grouped by host) or per identity
     * (host snapshot): how the link was established, in one sentence, with the indexes of
     * the access facts (rows of `systems`) whose chains explain it.
     */
    private static function connections(array $systems, string $by, ?string $subject): array
    {
        $out = [];
        foreach ($systems as $i => $s) {
            $k = (string) $s[$by];
            $c = &$out[$k];
            $c ??= ['key' => $k, 'host_asset_id' => $s['host_asset_id'] ?? null, 'host_name' => $s['host_name'] ?? null,
                    'identity_key' => $s['identity_key'] ?? null, 'identity_name' => $s['identity_name'] ?? null,
                    'facts' => [], 'ways' => [], 'direct_as' => [], 'groups' => [], 'plugins' => [], 'quality' => $s['match_quality'] ?? null];
            $c['facts'][] = $i;
            $noun = self::ACCESS_NOUN[$s['access_type']] ?? str_replace('_', ' ', (string) $s['access_type']);
            if (!empty($s['via_group'])) {
                $g = ($s['via_group_domain'] ? $s['via_group_domain'] . '\\' : '') . $s['via_group'];
                $c['ways'][] = "$noun through $g";
                $c['groups'][$g] = true;
            } else {
                $as = $s['principal_name'] !== null ? (($s['principal_domain'] ? $s['principal_domain'] . '\\' : '') . $s['principal_name']) : null;
                $c['ways'][] = $noun;
                if ($as) $c['direct_as'][$as] = true;
            }
            if ($s['evidence_plugin_id']) $c['plugins'][(string) $s['evidence_plugin_id']] = true;
            $q = $s['match_quality'] ?? null;
            if ($q !== null && (self::QUALITY_ORDER[$q] ?? 9) < (self::QUALITY_ORDER[$c['quality']] ?? 9)) $c['quality'] = $q;
            unset($c);
        }
        foreach ($out as &$c) {
            $who = $subject ?? (string) $c['identity_name'];
            $where = (string) ($c['host_name'] ?? 'this host');
            $ways = array_values(array_unique($c['ways']));
            $n = count($ways);
            $list = $n > 1 ? implode(', ', array_slice($ways, 0, -1)) . ' and ' . end($ways) : ($ways[0] ?? 'access');
            $parts = ["$who is connected to $where in " . ($n === 1 ? 'one way' : "$n ways") . ": $list."];
            if ($c['direct_as']) {
                $parts[] = 'The scan names the account directly as ' . implode(', ', array_keys($c['direct_as'])) . '.';
            }
            if ($c['groups']) {
                $parts[] = 'Through ' . implode(', ', array_keys($c['groups'])) . ' the name is not in the plugin output; the link is the Active Directory group membership.';
            }
            if ($c['plugins']) {
                $parts[] = 'Evidence: Nessus plugin' . (count($c['plugins']) > 1 ? 's ' : ' ') . implode(', ', array_keys($c['plugins'])) . '.';
            }
            if ($c['quality'] === 'demo alias') {
                $parts[] = 'This connection exists only because of the demo identity_mapping.';
            } elseif ($c['quality'] === 'inferred') {
                $parts[] = 'The match is inferred from the host\'s domain (the output gives a bare name).';
            }
            $c['summary'] = implode(' ', $parts);
            $c['ways'] = $ways;
            $c['groups'] = array_keys($c['groups']);
            $c['direct_as'] = array_keys($c['direct_as']);
            $c['plugins'] = array_keys($c['plugins']);
        }
        unset($c);
        return array_values($out);
    }

    private static function pgTextArray(array $values): string
    {
        return '{' . implode(',', array_map(fn ($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"', $values)) . '}';
    }

    private static function excerpt(string $output, array $needles): string
    {
        $lines = preg_split('/\r?\n/', $output);
        $keep = [];
        foreach ($lines as $i => $line) {
            foreach ($needles as $n) {
                if ($n !== '' && preg_match('/(^|[\s:\\\\])' . preg_quote((string) $n, '/') . '($|[\s,;])/i', $line)) {
                    // the section header above the match ("Group Name : Administrators", "Service : ...")
                    $start = $i;
                    while ($start > 0 && $start > $i - 200 && trim($lines[$start - 1]) !== '') {
                        $start--;
                    }
                    // the non-indented lines that open the section: "Group Name : Administrators", "Group SID : ..."
                    for ($h = $start; $h < $i && $h < $start + 3; $h++) {
                        if (preg_match('/^\S/', $lines[$h])) $keep[$h] = true;
                    }
                    // a list item ("  - DEMO\\x (User)") stands alone; a block ("Name : x" + Domain/SID) keeps its lines
                    $last = preg_match('/^\s*-\s/', $line) ? $i : $i + 3;
                    for ($j = $i; $j <= $last && isset($lines[$j]); $j++) {
                        if ($j > $i && trim($lines[$j]) === '') break;
                        if ($j > $i && preg_match('/^\s*Name\s*:/', $lines[$j])) break;
                        $keep[$j] = true;
                    }
                    break;
                }
            }
            if (count($keep) > 24) break;
        }
        if (!$keep) {
            $keep = array_fill_keys(range(0, min(5, count($lines) - 1)), true);
        }
        ksort($keep);
        $out = [];
        $prev = null;
        foreach (array_keys($keep) as $k) {
            if ($prev !== null && $k > $prev + 1) $out[] = '  …';
            $out[] = rtrim($lines[$k]);
            $prev = $k;
        }
        $text = implode("\n", $out);
        return mb_strlen($text) > self::EXCERPT_MAX ? mb_substr($text, 0, self::EXCERPT_MAX) . '…' : $text;
    }

    /** Findings on a set of hosts, worst first. */
    private static function findings(array $hostIds): array
    {
        if (!$hostIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($hostIds), '?'));
        $rows = Db::all(
            "SELECT v.host_asset_id, h.host_name, h.ip, h.host_tier, h.acr_score, v.plugin_id, v.plugin_name, v.family_name, v.severity, v.vpr_score, v.cvss_v3, v.epss_score,
                    v.port, v.protocol, array_to_string(v.cve, ',') AS cve, v.exploit_available, v.first_seen, v.last_seen
             FROM vulnerabilities v JOIN host_risk h ON h.host_asset_id = v.host_asset_id
             WHERE v.host_asset_id IN ($in) AND v.severity >= 1
             ORDER BY v.severity DESC, v.vpr_score DESC NULLS LAST, h.host_name, v.plugin_id LIMIT " . self::MAX_FINDINGS,
            $hostIds
        );
        foreach ($rows as &$r) {
            $r['cve'] = $r['cve'] === '' || $r['cve'] === null ? [] : explode(',', $r['cve']);
            $r['port_label'] = (int) $r['port'] ? $r['port'] . '/' . $r['protocol'] : 'host';
            $r['subnet'] = preg_match('/^(\d+\.\d+\.\d+)\.\d+$/', (string) $r['ip'], $m) ? $m[1] . '.0/24' : (string) $r['ip'];
        }
        return $rows;
    }

    private static function summary(array $hosts, array $findings, float $acr): array
    {
        $sev = self::severityCounts($findings);
        return [
            'hosts' => count($hosts),
            'hosts_with_critical' => count(array_filter($hosts, fn ($h) => (int) $h['critical'] > 0)),
            'high_acr_hosts' => count(array_filter($hosts, fn ($h) => (float) $h['acr_score'] >= $acr)),
            'tier0_hosts' => count(array_filter($hosts, fn ($h) => $h['host_tier'] !== null && (int) $h['host_tier'] === 0)),
            'findings' => count($findings), 'critical' => $sev['critical'], 'high' => $sev['high'], 'medium' => $sev['medium'], 'low' => $sev['low'],
            'exploitable' => count(array_filter($findings, fn ($f) => $f['exploit_available'] === true || $f['exploit_available'] === 't')),
            'max_vpr' => $findings ? max(array_map(fn ($f) => (float) $f['vpr_score'], $findings)) : null,
            'findings_truncated' => count($findings) >= self::MAX_FINDINGS,
        ];
    }

    /** Score, level, factors and the breakdowns both kinds share. */
    private static function riskBlock(array $factors, array $hosts, array $findings, float $acr): array
    {
        $weights = ['critical' => 25, 'high' => 12, 'medium' => 5, 'info' => 0];
        $score = min(100, array_sum(array_map(fn ($f) => $weights[$f['level']] ?? 0, $factors)));
        $level = $score >= 70 ? 'critical' : ($score >= 40 ? 'high' : ($score >= 15 ? 'medium' : 'low'));
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'info' => 3];
        usort($factors, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        $byHost = [];
        foreach ($findings as $f) {
            $k = (int) $f['host_asset_id'];
            $b = &$byHost[$k];
            $b ??= ['host_asset_id' => $k, 'host_name' => $f['host_name'], 'ip' => $f['ip'], 'host_tier' => $f['host_tier'], 'acr_score' => $f['acr_score'],
                    'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'exploitable' => 0, 'max_vpr' => 0.0];
            $b[[4 => 'critical', 3 => 'high', 2 => 'medium', 1 => 'low'][(int) $f['severity']] ?? 'low']++;
            if ($f['exploit_available'] === true || $f['exploit_available'] === 't') $b['exploitable']++;
            $b['max_vpr'] = max($b['max_vpr'], (float) $f['vpr_score']);
            unset($b);
        }
        foreach ($hosts as $id => $h) {
            if (!isset($byHost[$id])) {
                $byHost[$id] = ['host_asset_id' => $id, 'host_name' => $h['host_name'], 'ip' => $h['ip'], 'host_tier' => $h['host_tier'], 'acr_score' => $h['acr_score'],
                                'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'exploitable' => 0, 'max_vpr' => null];
            }
            $byHost[$id]['access'] = array_values(array_unique($h['access'] ?? []));
        }
        $byHost = array_values($byHost);
        usort($byHost, fn ($a, $b) => [$b['critical'], $b['max_vpr'], $b['high']] <=> [$a['critical'], $a['max_vpr'], $a['high']]);

        $plugins = [];
        foreach ($findings as $f) {
            if ((int) $f['severity'] < 3) continue;
            $p = &$plugins[(int) $f['plugin_id']];
            $p ??= ['plugin_id' => (int) $f['plugin_id'], 'plugin_name' => $f['plugin_name'], 'severity' => (int) $f['severity'], 'max_vpr' => 0.0, 'hosts' => 0, 'exploit' => false, 'cve' => []];
            $p['hosts']++;
            $p['max_vpr'] = max($p['max_vpr'], (float) $f['vpr_score']);
            $p['exploit'] = $p['exploit'] || $f['exploit_available'] === true || $f['exploit_available'] === 't';
            $p['cve'] = array_slice(array_values(array_unique(array_merge($p['cve'], $f['cve']))), 0, 5);
            unset($p);
        }
        $plugins = array_values($plugins);
        usort($plugins, fn ($a, $b) => [$b['max_vpr'], $b['hosts']] <=> [$a['max_vpr'], $a['hosts']]);

        $tiers = [];
        foreach ($hosts as $h) {
            $t = $h['host_tier'] === null ? 'untiered' : 'tier ' . $h['host_tier'];
            $tiers[$t] = ($tiers[$t] ?? 0) + 1;
        }
        $subnets = [];
        foreach ($byHost as $h) {
            $s = preg_match('/^(\d+\.\d+\.\d+)\.\d+$/', (string) $h['ip'], $m) ? $m[1] . '.0/24' : (string) $h['ip'];
            $x = &$subnets[$s];
            $x ??= ['subnet' => $s, 'hosts' => 0, 'critical' => 0, 'high' => 0];
            $x['hosts']++; $x['critical'] += $h['critical']; $x['high'] += $h['high'];
            unset($x);
        }
        $subnets = array_values($subnets);
        usort($subnets, fn ($a, $b) => [$b['critical'], $b['hosts']] <=> [$a['critical'], $a['hosts']]);

        $cves = [];
        foreach ($findings as $f) {
            if ((int) $f['severity'] < 3) continue;
            foreach ($f['cve'] as $c) {
                $cves[$c] = ['cve' => $c, 'hosts' => ($cves[$c]['hosts'] ?? 0) + 1, 'max_vpr' => max($cves[$c]['max_vpr'] ?? 0, (float) $f['vpr_score'])];
            }
        }
        $cves = array_values($cves);
        usort($cves, fn ($a, $b) => [$b['max_vpr'], $b['hosts']] <=> [$a['max_vpr'], $a['hosts']]);

        return ['score' => $score, 'level' => $level, 'acr_threshold' => $acr, 'factors' => $factors,
                'severity' => self::severityCounts($findings), 'by_host' => array_slice($byHost, 0, 100), 'by_tier' => $tiers,
                'by_subnet' => array_slice($subnets, 0, 30), 'top_plugins' => array_slice($plugins, 0, 25), 'top_cves' => array_slice($cves, 0, 25),
                'by_family' => array_slice(self::countBy(array_filter($findings, fn ($f) => (int) $f['severity'] >= 3), 'family_name'), 0, 12)];
    }

    private static function severityCounts(array $findings): array
    {
        $c = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($findings as $f) {
            $k = [4 => 'critical', 3 => 'high', 2 => 'medium', 1 => 'low'][(int) $f['severity']] ?? null;
            if ($k) $c[$k]++;
        }
        return $c;
    }

    /** [{label, n}] counted by one column, largest first. */
    private static function countBy(array $rows, string $col): array
    {
        $c = [];
        foreach ($rows as $r) {
            $k = (string) ($r[$col] ?? '') ?: '(none)';
            $c[$k] = ($c[$k] ?? 0) + 1;
        }
        arsort($c);
        return array_map(fn ($k, $n) => ['label' => (string) $k, 'n' => $n], array_keys($c), $c);
    }
}
