<?php
declare(strict_types=1);

/**
 * The database tools Agent Tenable can call. Every tool is SELECT-only, runs
 * in a read-only transaction with a statement timeout, and reads the indexed
 * tables and read models (host_risk, v_risk_access, software_summary …).
 * Tool names and parameter schemas come from ai/tools/<name>.md; this class
 * implements them by name.
 */
final class Tools
{
    private const STATEMENT_TIMEOUT_MS = 8000;
    /** "DOMAIN\name" -> "name". Must match the expression index in revision 0012. */
    private const IDENTITY_SHORT = 'lower(CASE WHEN position(chr(92) IN a.identity_name) > 0 THEN split_part(a.identity_name, chr(92), 2) ELSE a.identity_name END)';
    private const HOST_SEARCH = "lower(coalesce(host_name, '') || ' ' || coalesce(ip, ''))";
    private const USER_SEARCH = "lower(coalesce(u.sam_account_name, '') || ' ' || coalesce(u.display_name, '') || ' ' || coalesce(u.name, '') || ' ' || coalesce(u.user_principal_name, '') || ' ' || coalesce(u.attributes->>'mail', ''))";
    private const UAC_FLAGS = [2 => 'ACCOUNTDISABLE', 16 => 'LOCKOUT', 32 => 'PASSWD_NOTREQD', 64 => 'PASSWD_CANT_CHANGE', 128 => 'ENCRYPTED_TEXT_PWD_ALLOWED',
        512 => 'NORMAL_ACCOUNT', 2048 => 'INTERDOMAIN_TRUST_ACCOUNT', 4096 => 'WORKSTATION_TRUST_ACCOUNT', 8192 => 'SERVER_TRUST_ACCOUNT',
        65536 => 'DONT_EXPIRE_PASSWORD', 262144 => 'SMARTCARD_REQUIRED', 524288 => 'TRUSTED_FOR_DELEGATION', 1048576 => 'NOT_DELEGATED',
        2097152 => 'USE_DES_KEY_ONLY', 4194304 => 'DONT_REQ_PREAUTH', 8388608 => 'PASSWORD_EXPIRED', 16777216 => 'TRUSTED_TO_AUTH_FOR_DELEGATION'];
    private const VULN_COLUMNS = 'v.host_asset_id, h.host_name, h.ip, v.port, v.protocol, v.plugin_id, v.plugin_name, v.severity, v.vpr_score, v.cvss_v3,
                                  array_to_string(v.cve, \', \') AS cve, v.exploit_available, v.first_seen::date AS first_seen, v.last_seen::date AS last_seen';

    public static function names(): array
    {
        return ['find_identity', 'identity_profile', 'identity_access', 'identity_vulnerabilities', 'find_host', 'host_vulnerabilities',
                'host_identities', 'vulnerability_lookup', 'group_members', 'search_software', 'risk_overview', 'render_chart'];
    }

    /** Run one tool by name. Throws ApiError for a bad name or bad arguments. */
    public static function run(string $name, array $args): array
    {
        if (!in_array($name, self::names(), true)) {
            throw new ApiError("Unknown tool: $name");
        }
        if ($name === 'render_chart') {
            return self::renderChart($args);
        }
        $pdo = Db::pdo();
        if ($pdo->inTransaction()) {
            // Called inside the caller's transaction (an asset sync, a test): a savepoint keeps the
            // timeout local to the tool and never ends the caller's transaction.
            Db::run('SAVEPOINT tool_run', []);
            try {
                Db::run('SET LOCAL statement_timeout = ' . self::STATEMENT_TIMEOUT_MS, []);
                $result = self::$name($args);
                Db::run('ROLLBACK TO SAVEPOINT tool_run', []);
                Db::run('RELEASE SAVEPOINT tool_run', []);
                return $result;
            } catch (Throwable $e) {
                try {
                    Db::run('ROLLBACK TO SAVEPOINT tool_run', []);
                    Db::run('RELEASE SAVEPOINT tool_run', []);
                } catch (Throwable $ignored) {
                }
                throw $e;
            }
        }
        Db::run('BEGIN READ ONLY', []);
        try {
            Db::run('SET LOCAL statement_timeout = ' . self::STATEMENT_TIMEOUT_MS, []);
            $result = self::$name($args);
            $pdo->rollBack();   // nothing to keep; end the read-only transaction
            return $result;
        } catch (Throwable $e) {
            try {
                $pdo->rollBack();
            } catch (Throwable $ignored) {
            }
            throw $e;
        }
    }

    // ---- argument helpers --------------------------------------------------------------------

    private static function text(array $a, string $key, bool $required = true, int $max = 200): string
    {
        $v = $a[$key] ?? '';
        $v = is_scalar($v) ? trim((string) $v) : '';
        if ($required && $v === '') {
            throw new ApiError("The argument '$key' is required.");
        }
        return mb_substr($v, 0, $max);
    }

    private static function limit(array $a, int $default, int $max): int
    {
        $v = $a['limit'] ?? $default;
        return is_numeric($v) ? max(1, min($max, (int) $v)) : $default;
    }

    private static function choice(array $a, string $key, array $allowed, string $default): string
    {
        $v = strtolower(trim((string) ($a[$key] ?? '')));
        return in_array($v, $allowed, true) ? $v : $default;
    }

    private static function severityMin(array $a, int $default): int
    {
        $v = $a['severity_min'] ?? null;
        return is_numeric($v) ? max(0, min(4, (int) $v)) : $default;
    }

    public static function like(string $s): string
    {
        return '%' . addcslashes(strtolower($s), '%_\\') . '%';
    }

    public static function isCidr(string $s): bool
    {
        return (bool) preg_match('#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $s);
    }

    public static function isIp(string $s): bool
    {
        return filter_var($s, FILTER_VALIDATE_IP) !== false;
    }

    // ---- identity resolution ------------------------------------------------------------------

    /**
     * Account text -> AD user row (or null) plus the WHERE fragment that finds the
     * identity's rows in v_risk_access (alias a). DOMAIN\name is honoured exactly;
     * a bare name matches the AD account and any telemetry identity with that name.
     */
    public static function resolveIdentity(string $account): array
    {
        // Models often JSON-escape twice: "DEMO\\\\SCCM". Any run of backslashes is one separator.
        $account = preg_replace('/\\\\+/', '\\', trim($account));
        $full = strtolower($account);
        $short = str_contains($account, '\\') ? strtolower(substr($account, strrpos($account, '\\') + 1)) : $full;
        if (str_contains($short, '@')) {
            $short = strtolower(substr($short, 0, strpos($short, '@')));
        }
        $select = "SELECT u.id, u.object_key, u.sam_account_name, u.display_name, u.user_principal_name, u.enabled, u.admin_count, u.account_type,
                    u.last_logon, u.pwd_last_set, u.when_created, u.dn, u.sid, u.attributes, d.netbios_name AS domain, d.dns_name AS domain_dns,
                    t.tier, t.source AS tier_source, t.detail AS tier_detail
             FROM ad_users u LEFT JOIN LATERAL (SELECT dd.id, dd.netbios_name, dd.dns_name FROM ad_domains dd WHERE dd.directory_id = u.directory_id OR (dd.nc_name IS NOT NULL AND lower(u.dn) LIKE '%' || lower(dd.nc_name)) ORDER BY (dd.nc_name IS NOT NULL AND lower(u.dn) LIKE '%' || lower(dd.nc_name)) DESC, (dd.directory_id = u.directory_id) DESC, length(dd.nc_name) DESC NULLS LAST LIMIT 1) d ON true
             LEFT JOIN v_ad_object_tier t ON t.object_key = u.object_key";
        if (preg_match('/^#ad:(\d{1,18})$/', $account, $m)) {
            // Internal form used by the asset pages: one exact AD user, no name guessing.
            $ad = Db::one("$select WHERE u.id = ?", [(int) $m[1]]);
            if (!$ad) {
                throw new ApiError('This AD account no longer exists.', 404);
            }
            return ['ad' => $ad, 'short' => strtolower((string) $ad['sam_account_name']),
                    'where' => "(a.ad_object_key = ?) AND a.access_class <> 'scan'", 'params' => [$ad['object_key']]];
        }
        if (preg_match('/^#key:(.{1,600})$/s', $account, $m)) {
            // A telemetry identity by its identity_host_access key (no AD match).
            $ad = Db::one("$select WHERE u.object_key = ?", [$m[1]]);
            if ($ad) {
                return ['ad' => $ad, 'short' => strtolower((string) $ad['sam_account_name']),
                        'where' => "(a.ad_object_key = ?) AND a.access_class <> 'scan'", 'params' => [$ad['object_key']]];
            }
            $name = (string) Db::value('SELECT identity_name FROM identity_host_access WHERE identity_key = ? LIMIT 1', [$m[1]]);
            return ['ad' => null, 'short' => strtolower($name !== '' ? $name : $m[1]),
                    'where' => "(a.identity_key = ?) AND a.access_class <> 'scan'", 'params' => [$m[1]]];
        }
        $ad = Db::one("$select WHERE lower(u.sam_account_name) = ? OR lower(u.user_principal_name) = ?
             ORDER BY (lower(u.sam_account_name) = ?) DESC, u.id LIMIT 1", [$short, $full, $short]);
        $where = [];
        $params = [];
        if ($ad) {
            $where[] = 'a.ad_object_key = ?';
            $params[] = $ad['object_key'];
        }
        if (str_contains($account, '\\')) {
            $where[] = 'lower(a.identity_name) = ?';
            $params[] = $full;
        } else {
            // Telemetry identities without an AD match that carry this name.
            $where[] = '(a.ad_object_key IS NULL AND ' . self::IDENTITY_SHORT . ' = ?)';
            $params[] = $short;
        }
        return ['ad' => $ad, 'short' => $short, 'where' => '(' . implode(' OR ', $where) . ")  AND a.access_class <> 'scan'", 'params' => $params];
    }

    /** "OU=Employees,OU=Accounts,DC=tcorp,DC=local" -> "Accounts / Employees"; CN containers (Users) kept. */
    public static function orgUnit(?string $dn): ?string
    {
        if (!$dn) {
            return null;
        }
        // Split on commas that are not escaped (\,), drop the object's own RDN and the DC= parts.
        $parts = preg_split('/(?<!\\\\),/', $dn);
        array_shift($parts);
        $path = [];
        foreach ($parts as $p) {
            if (preg_match('/^\s*(OU|CN)=(.*)$/i', $p, $m)) {
                $path[] = str_replace('\\,', ',', trim($m[2]));
            }
        }
        return $path ? implode(' / ', array_reverse($path)) : null;
    }

    /** The identity card every person investigation opens with. */
    public static function person(array $ad): array
    {
        $attrs = json_decode((string) $ad['attributes'], true) ?: [];
        return [
            'ad_user_id' => (int) $ad['id'], 'display_name' => $ad['display_name'], 'account' => ($ad['domain'] ? $ad['domain'] . '\\' : '') . $ad['sam_account_name'],
            'user_principal_name' => $ad['user_principal_name'], 'mail' => $attrs['mail'] ?? null, 'telephone' => $attrs['telephonenumber'] ?? null,
            'description' => $attrs['description'] ?? null, 'org_unit' => self::orgUnit($ad['dn']),
            'enabled' => $ad['enabled'], 'account_type' => $ad['account_type'], 'tier' => $ad['tier'],
            'last_logon' => $ad['last_logon'], 'pwd_last_set' => $ad['pwd_last_set'],
            'not_in_source' => 'department, title, manager, given name / surname are not collected by Identity Exposure; org_unit is the closest substitute',
        ];
    }

    private static function identityLabel(array $r): string
    {
        return $r['ad'] ? ($r['ad']['domain'] ? $r['ad']['domain'] . '\\' : '') . $r['ad']['sam_account_name'] : $r['short'];
    }

    // ---- tools -------------------------------------------------------------------------------------

    private static function find_identity(array $a): array
    {
        $q = self::text($a, 'query');
        $limit = self::limit($a, 20, 50);
        $lq = strtolower($q);
        $select = "SELECT u.id AS ad_user_id, u.sam_account_name, u.display_name, u.user_principal_name, u.attributes->>'mail' AS mail, u.enabled, t.tier,
                          u.admin_count, u.account_type, u.last_logon::date AS last_logon, u.pwd_last_set::date AS pwd_last_set, d.netbios_name AS directory
                   FROM ad_users u LEFT JOIN LATERAL (SELECT dd.id, dd.netbios_name, dd.dns_name FROM ad_domains dd WHERE dd.directory_id = u.directory_id OR (dd.nc_name IS NOT NULL AND lower(u.dn) LIKE '%' || lower(dd.nc_name)) ORDER BY (dd.nc_name IS NOT NULL AND lower(u.dn) LIKE '%' || lower(dd.nc_name)) DESC, (dd.directory_id = u.directory_id) DESC, length(dd.nc_name) DESC NULLS LAST LIMIT 1) d ON true LEFT JOIN v_ad_object_tier t ON t.object_key = u.object_key";
        $exact = Db::all("$select WHERE lower(u.sam_account_name) = ? OR lower(u.user_principal_name) = ? OR lower(u.attributes->>'mail') = ? ORDER BY u.id LIMIT ?",
            [$lq, $lq, $lq, $limit]);
        $rows = $exact;
        if (count($rows) < $limit) {
            // "name surname" in any order: every word must appear somewhere in the searchable text.
            $words = array_values(array_filter(preg_split('/\s+/', $lq)));
            $conds = [];
            $params = [];
            foreach ($words as $w) {
                $conds[] = self::USER_SEARCH . ' LIKE ?';
                $params[] = self::like($w);
            }
            $params[] = $limit - count($rows);
            $more = Db::all("$select WHERE " . implode(' AND ', $conds) . ' ORDER BY (u.enabled IS TRUE) DESC, t.tier NULLS LAST, u.sam_account_name LIMIT ?', $params);
            $seen = array_column($rows, 'sam_account_name');
            foreach ($more as $m) {
                if (!in_array($m['sam_account_name'], $seen, true)) {
                    $rows[] = $m;
                }
            }
        }
        $telemetry = Db::all(
            "SELECT a.identity_key, a.identity_name, a.identity_kind AS kind, count(DISTINCT a.host_asset_id) AS hosts, string_agg(DISTINCT a.access_type, ', ') AS access_types
             FROM identity_host_access a WHERE a.ad_object_key IS NULL AND a.access_class <> 'scan' AND lower(a.identity_name) LIKE ?
             GROUP BY a.identity_key, a.identity_name, a.identity_kind ORDER BY hosts DESC LIMIT ?",
            [self::like($lq), 20]
        );
        return ['query' => $q, 'ad_users' => $rows, 'telemetry_identities' => $telemetry,
                'note' => $rows ? null : 'No AD account matched. Telemetry identities are names seen on hosts (sessions, local admins, services) that have no AD match.'];
    }

    private static function identity_profile(array $a): array
    {
        $r = self::resolveIdentity(self::text($a, 'account'));
        if (!$r['ad']) {
            $usage = Db::all('SELECT a.identity_key, a.identity_name, a.access_type, count(DISTINCT a.host_asset_id) AS hosts FROM v_risk_access a WHERE ' . $r['where'] . ' GROUP BY 1, 2, 3 ORDER BY 4 DESC', $r['params']);
            return ['account' => null, 'note' => "No Active Directory account named '{$r['short']}' is synced. " . ($usage ? 'The name does appear in host telemetry; see usage.' : 'Try find_identity with part of the name.'),
                    'usage' => $usage];
        }
        $ad = $r['ad'];
        $attrs = json_decode((string) $ad['attributes'], true) ?: [];
        $uac = (int) ($attrs['useraccountcontrol'] ?? 0);
        $flags = [];
        foreach (self::UAC_FLAGS as $bit => $flag) {
            if ($uac & $bit) {
                $flags[] = $flag;
            }
        }
        $spn = $attrs['serviceprincipalname'] ?? null;
        $account = self::person($ad) + [
            'ad_user_id' => (int) $ad['id'], 'sam_account_name' => $ad['sam_account_name'], 'display_name' => $ad['display_name'], 'user_principal_name' => $ad['user_principal_name'],
            'domain' => $ad['domain'], 'domain_dns' => $ad['domain_dns'], 'dn' => $ad['dn'], 'sid' => $ad['sid'], 'enabled' => $ad['enabled'],
            'account_type' => $ad['account_type'], 'tier' => $ad['tier'], 'tier_source' => $ad['tier_source'],
            'tier_detail' => $ad['tier_detail'] ? json_decode((string) $ad['tier_detail'], true) : null,
            'admin_count' => $ad['admin_count'], 'created' => $ad['when_created'], 'last_logon' => $ad['last_logon'], 'pwd_last_set' => $ad['pwd_last_set'],
            'password_age_days' => $ad['pwd_last_set'] ? (int) floor((time() - strtotime((string) $ad['pwd_last_set'])) / 86400) : null,
            'description' => $attrs['description'] ?? null, 'mail' => $attrs['mail'] ?? null,
            'user_account_control' => $uac ?: null, 'uac_flags' => $flags,
            'service_principal_names' => is_array($spn) ? $spn : ($spn ? [$spn] : []),
            'allowed_to_delegate_to' => $attrs['msds-allowedtodelegateto'] ?? null,
            'sid_history' => $attrs['sidhistory'] ?? null,
            'password_findings' => $attrs['_derived'] ?? null,
            'bad_password_count' => $attrs['badpwdcount'] ?? null, 'lockout_time' => $attrs['lockouttime'] ?? null,
        ];
        $groups = Db::all(
            "SELECT g.id AS ad_group_id, g.name, g.sam_account_name, t.tier, g.group_type, g.member_count, g.description
             FROM ad_group_members m JOIN ad_groups g ON g.object_key = m.group_key LEFT JOIN v_ad_object_tier t ON t.object_key = g.object_key
             WHERE m.member_key = ? ORDER BY t.tier NULLS LAST, g.name LIMIT 100",
            [$ad['object_key']]
        );
        $usage = Db::all('SELECT a.identity_key, a.identity_name, a.access_type, a.access_class, count(DISTINCT a.host_asset_id) AS hosts FROM v_risk_access a WHERE ' . $r['where'] . ' GROUP BY 1, 2, 3, 4 ORDER BY 5 DESC', $r['params']);
        return ['account' => $account, 'groups' => $groups, 'usage' => $usage];
    }

    private static function identity_access(array $a): array
    {
        $r = self::resolveIdentity(self::text($a, 'account'));
        $class = self::choice($a, 'access_class', ['any', 'right', 'usage'], 'any');
        $limit = self::limit($a, 50, 200);
        $where = $r['where'] . ($class === 'any' ? '' : ' AND a.access_class = ?');
        $params = $class === 'any' ? $r['params'] : array_merge($r['params'], [$class]);
        $acr = (float) (Db::setting('ctem')['acr_threshold'] ?? 7);
        $summary = Db::one(
            "SELECT count(DISTINCT a.host_asset_id) AS hosts, count(DISTINCT a.host_asset_id) FILTER (WHERE a.critical > 0) AS hosts_with_critical,
                    count(DISTINCT a.host_asset_id) FILTER (WHERE a.acr_score >= ?) AS hosts_high_acr,
                    count(DISTINCT a.host_asset_id) FILTER (WHERE a.exploitable > 0) AS hosts_exploitable,
                    count(DISTINCT a.host_asset_id) FILTER (WHERE a.host_tier = 0) AS tier0_hosts
             FROM v_risk_access a WHERE $where",
            array_merge([$acr], $params)
        );
        $byType = Db::all("SELECT a.access_type, count(DISTINCT a.host_asset_id) AS hosts FROM v_risk_access a WHERE $where GROUP BY 1 ORDER BY 2 DESC", $params);
        $rows = Db::all(
            "SELECT a.host_name, a.ip, split_part(a.os, E'\\n', 1) AS os, a.access_type, a.access_class, a.identity_key, a.identity_name, a.via_group_key AS via_group, a.evidence, a.confidence,
                    a.last_seen::date AS last_seen, a.critical, a.high, a.max_vpr, a.acr_score, a.host_tier, a.credentialed, a.host_asset_id
             FROM v_risk_access a WHERE $where
             ORDER BY a.critical DESC, a.acr_score DESC NULLS LAST, a.host_name, a.access_type LIMIT ?",
            array_merge($params, [$limit])
        );
        return ['account' => self::identityLabel($r), 'person' => $r['ad'] ? self::person($r['ad']) : null,
                'in_ad' => (bool) $r['ad'], 'tier' => $r['ad']['tier'] ?? null, 'acr_threshold' => $acr,
                'identity_key' => $rows[0]['identity_key'] ?? null,
                'summary' => $summary + ['by_access_type' => $byType], 'rows' => $rows,
                'note' => $r['ad'] ? null : 'This identity is not in the synced AD; rows come from host telemetry that carries the name.'];
    }

    private static function identity_vulnerabilities(array $a): array
    {
        $r = self::resolveIdentity(self::text($a, 'account'));
        $hostSql = 'SELECT DISTINCT a.host_asset_id FROM v_risk_access a WHERE ' . $r['where'];
        $hosts = (int) Db::value("SELECT count(*) FROM ($hostSql) x", $r['params']);
        $out = self::vulnerabilities($a, $hostSql, $r['params'], 'plugin', 3);
        $out['scope'] = ['account' => self::identityLabel($r), 'in_ad' => (bool) $r['ad'], 'hosts' => $hosts] + $out['scope'];
        if ($hosts === 0) {
            $out['note'] = 'No host is linked to this account, so there are no findings to show. Check the name with find_identity.';
        }
        return $out;
    }

    private static function find_host(array $a): array
    {
        $q = self::text($a, 'query');
        $limit = self::limit($a, 25, 100);
        $sorts = ['critical' => 'critical DESC, max_vpr DESC NULLS LAST', 'vpr' => 'max_vpr DESC NULLS LAST, critical DESC', 'acr' => 'acr_score DESC NULLS LAST, critical DESC', 'name' => 'lower(host_name)'];
        $sort = $sorts[self::choice($a, 'sort', array_keys($sorts), 'critical')];
        if (self::isCidr($q) || self::isIp($q)) {
            $where = 'CAST(ip AS inet) <<= CAST(? AS inet)';
            $params = [$q];
        } else {
            $where = self::HOST_SEARCH . ' LIKE ?';
            $params = [self::like($q)];
        }
        $total = (int) Db::value("SELECT count(*) FROM host_risk WHERE $where", $params);
        $rows = Db::all(
            "SELECT host_asset_id, host_name, ip, split_part(os, E'\\n', 1) AS os, asset_class, domain_joined, ad_matched, host_tier, acr_score, aes_score,
                    critical, high, medium, max_vpr, exploitable, credentialed, last_seen::date AS last_seen
             FROM host_risk WHERE $where ORDER BY $sort, host_name LIMIT ?",
            array_merge($params, [$limit])
        );
        $summary = $total > 1 ? Db::one(
            "SELECT count(*) FILTER (WHERE critical > 0) AS hosts_with_critical, sum(critical) AS critical, sum(high) AS high,
                    count(*) FILTER (WHERE NOT credentialed) AS not_credentialed, count(*) FILTER (WHERE host_tier = 0) AS tier0_hosts
             FROM host_risk WHERE $where", $params) : null;
        return ['query' => $q, 'total' => $total, 'summary' => $summary, 'rows' => $rows,
                'note' => $total ? null : 'No host matched. Names are matched as "contains"; IPs and CIDRs exactly.'];
    }

    /** The host set for a host_* tool: [sql selecting host_asset_id, params, description]. */
    public static function hostSet(string $target): array
    {
        if (self::isCidr($target) || self::isIp($target)) {
            return ['SELECT host_asset_id FROM host_risk WHERE CAST(ip AS inet) <<= CAST(? AS inet)', [$target], self::isCidr($target)];
        }
        $lt = strtolower($target);
        $exact = (int) Db::value('SELECT count(*) FROM host_risk WHERE lower(host_name) = ?', [$lt]);
        if ($exact > 0) {
            return ['SELECT host_asset_id FROM host_risk WHERE lower(host_name) = ?', [$lt], false];
        }
        return ['SELECT host_asset_id FROM host_risk WHERE ' . self::HOST_SEARCH . ' LIKE ?', [self::like($lt)], true];
    }

    private static function host_vulnerabilities(array $a): array
    {
        $target = self::text($a, 'target');
        [$hostSql, $params, $many] = self::hostSet($target);
        $hosts = (int) Db::value("SELECT count(*) FROM ($hostSql) x", $params);
        $out = self::vulnerabilities($a, $hostSql, $params, $many ? 'plugin' : 'none', $many ? 3 : 2);
        $out['scope'] = ['target' => $target, 'hosts' => $hosts] + $out['scope'];
        if ($hosts === 0) {
            $out['note'] = 'No host matched this target. Use find_host to check the name or address.';
        }
        return $out;
    }

    /**
     * Shared by identity_vulnerabilities and host_vulnerabilities: findings on a host
     * set, flat or grouped. $hostSql selects host_asset_id.
     */
    private static function vulnerabilities(array $a, string $hostSql, array $hostParams, string $defaultGroup, int $defaultSeverity): array
    {
        $group = self::choice($a, 'group_by', ['none', 'host', 'ip_block', 'port', 'plugin', 'cve'], $defaultGroup);
        $sevMin = self::severityMin($a, $defaultSeverity);
        $sort = self::choice($a, 'sort', ['severity', 'cvss', 'vpr', 'count'], 'vpr');
        $limit = self::limit($a, 30, 100);
        $exploitable = !empty($a['exploitable_only']) && $a['exploitable_only'] !== 'false';
        $where = "v.host_asset_id IN ($hostSql) AND v.severity >= ?" . ($exploitable ? ' AND v.exploit_available' : '');
        $params = array_merge($hostParams, [$sevMin]);
        $base = "FROM vulnerabilities v JOIN host_risk h ON h.host_asset_id = v.host_asset_id WHERE $where";

        $summary = Db::one(
            "SELECT count(*) AS total, count(DISTINCT v.host_asset_id) AS hosts,
                    count(*) FILTER (WHERE v.severity = 4) AS critical, count(*) FILTER (WHERE v.severity = 3) AS high,
                    count(*) FILTER (WHERE v.severity = 2) AS medium, count(*) FILTER (WHERE v.severity = 1) AS low,
                    count(*) FILTER (WHERE v.exploit_available) AS exploitable, max(v.vpr_score) AS max_vpr $base",
            $params
        );
        $scope = ['group_by' => $group, 'severity_min' => $sevMin, 'sort' => $sort, 'exploitable_only' => $exploitable];
        if ($group === 'none') {
            $order = ['severity' => 'v.severity DESC, v.vpr_score DESC NULLS LAST', 'cvss' => 'v.cvss_v3 DESC NULLS LAST, v.severity DESC',
                      'vpr' => 'v.vpr_score DESC NULLS LAST, v.severity DESC', 'count' => 'v.severity DESC, v.vpr_score DESC NULLS LAST'][$sort];
            $rows = Db::all('SELECT ' . self::VULN_COLUMNS . " $base ORDER BY $order, h.host_name, v.plugin_id LIMIT ?", array_merge($params, [$limit]));
            return ['scope' => $scope, 'summary' => $summary, 'rows' => $rows];
        }
        // [group key, label (an aggregate, so the query groups by the key alone), extra join]
        $keys = [
            'host' => ['CAST(v.host_asset_id AS text)', 'min(h.host_name)', ''],
            'ip_block' => ["host(set_masklen(network(CAST(h.ip AS inet)), 24)) || '/24'", 'min(h.ip)', ''],
            'port' => ["v.port || '/' || v.protocol", 'min(v.protocol)', ''],
            'plugin' => ['CAST(v.plugin_id AS text)', 'min(v.plugin_name)', ''],
            'cve' => ['c.cve', 'min(v.plugin_name)', 'CROSS JOIN LATERAL unnest(v.cve) AS c(cve)'],
        ];
        [$key, $label, $join] = $keys[$group];
        $order = ['severity' => 'max(v.severity) DESC, max(v.vpr_score) DESC NULLS LAST', 'cvss' => 'max(v.cvss_v3) DESC NULLS LAST',
                  'vpr' => 'max(v.vpr_score) DESC NULLS LAST, count(*) DESC', 'count' => 'count(*) DESC, max(v.severity) DESC'][$sort];
        $rows = Db::all(
            "SELECT $key AS key, $label AS name, count(*) AS findings, count(DISTINCT v.host_asset_id) AS hosts,
                    count(*) FILTER (WHERE v.severity = 4) AS critical, count(*) FILTER (WHERE v.severity = 3) AS high,
                    max(v.severity) AS max_severity, max(v.vpr_score) AS max_vpr, max(v.cvss_v3) AS max_cvss,
                    count(*) FILTER (WHERE v.exploit_available) AS exploitable,
                    (array_agg(DISTINCT h.host_name))[1:3] AS sample_hosts
             FROM vulnerabilities v JOIN host_risk h ON h.host_asset_id = v.host_asset_id $join WHERE $where
             GROUP BY 1 ORDER BY $order, 1 LIMIT ?",
            array_merge($params, [$limit])
        );
        foreach ($rows as &$row) {
            $row['sample_hosts'] = self::pgArray((string) $row['sample_hosts']);
            if ($group === 'host') {
                $row['host_asset_id'] = (int) $row['key'];
            }
        }
        return ['scope' => $scope, 'summary' => $summary, 'rows' => $rows];
    }

    private static function host_identities(array $a): array
    {
        $target = self::text($a, 'target');
        $limit = self::limit($a, 40, 150);
        [$hostSql, $params] = self::hostSet($target);
        $host = Db::one("SELECT host_asset_id, host_name, ip, split_part(os, E'\\n', 1) AS os, asset_class, domain_joined, ad_matched, host_tier, acr_score, aes_score,
                                critical, high, max_vpr, exploitable, credentialed, last_seen::date AS last_seen
                         FROM host_risk WHERE host_asset_id IN ($hostSql) ORDER BY critical DESC LIMIT 1", $params);
        if (!$host) {
            return ['host' => null, 'note' => 'No host matched. Use find_host to check the name or address.'];
        }
        $id = (int) $host['host_asset_id'];
        $matched = (int) Db::value("SELECT count(*) FROM ($hostSql) x", $params);
        $access = Db::all(
            "SELECT a.identity_key, a.identity_name, a.identity_kind AS kind, a.scope, a.tier, a.tier0_like, a.access_type, a.access_class, a.via_group_key AS via_group, a.evidence, a.confidence,
                    a.last_seen::date AS last_seen, o.id AS ad_object_id, o.object_type,
                    (SELECT count(DISTINCT b.host_asset_id) FROM identity_host_access b WHERE b.identity_key = a.identity_key AND b.host_asset_id <> a.host_asset_id AND b.access_class <> 'scan') AS other_hosts
             FROM v_risk_access a LEFT JOIN v_ad_objects o ON o.object_key = a.ad_object_key WHERE a.host_asset_id = ? AND a.access_class <> 'scan'
             ORDER BY a.tier0_like DESC, a.access_class, a.identity_name LIMIT ?",
            [$id, $limit]
        );
        $local = Db::all('SELECT name, rid, account_type, disabled, privileged, groups, flags, last_seen::date AS last_seen FROM local_accounts WHERE host_asset_id = ? ORDER BY privileged DESC, name LIMIT ?', [$id, $limit]);
        foreach ($local as &$l) {
            $l['groups'] = self::pgArray((string) $l['groups']);
            $l['flags'] = self::pgArray((string) $l['flags']);
        }
        $services = Db::all(
            "SELECT s.service_name, s.display_name, COALESCE(NULLIF(p.domain, '') || chr(92), '') || p.name AS run_as, p.scope AS account_scope, s.start_type, s.path
             FROM host_services s LEFT JOIN principals p ON p.id = s.principal_id
             WHERE s.host_asset_id = ? AND (p.scope IS DISTINCT FROM 'builtin')
             ORDER BY (p.scope = 'domain') DESC, s.service_name LIMIT ?",
            [$id, $limit]
        );
        return ['host' => $host, 'note' => $matched > 1 ? "$matched hosts matched '$target'; showing the one with the most Critical findings." : null,
                'access' => $access, 'local_accounts' => $local, 'services' => $services];
    }

    private static function vulnerability_lookup(array $a): array
    {
        $q = self::text($a, 'query');
        $sevMin = self::severityMin($a, 0);
        $limit = self::limit($a, 40, 150);
        if (preg_match('/^\d{3,7}$/', $q)) {
            $where = 'v.plugin_id = ?';
            $params = [(int) $q];
        } elseif (preg_match('/^cve-\d{4}-\d{4,}$/i', $q)) {
            $where = 'v.cve @> ARRAY[?]::text[]';
            $params = [strtoupper($q)];
        } else {
            $where = 'lower(v.plugin_name) LIKE ?';
            $params = [self::like($q)];
        }
        $where .= ' AND v.severity >= ?';
        $params[] = $sevMin;
        $plugins = Db::all(
            "SELECT v.plugin_id, min(v.plugin_name) AS plugin_name, max(v.severity) AS severity, max(v.vpr_score) AS max_vpr, max(v.cvss_v3) AS max_cvss,
                    bool_or(v.exploit_available) AS exploit_available, count(DISTINCT v.host_asset_id) AS hosts, count(*) AS findings,
                    (array_agg(DISTINCT c.cve))[1:5] AS cve_sample
             FROM vulnerabilities v LEFT JOIN LATERAL unnest(v.cve) AS c(cve) ON true WHERE $where
             GROUP BY v.plugin_id ORDER BY max(v.vpr_score) DESC NULLS LAST, hosts DESC LIMIT 25",
            $params
        );
        foreach ($plugins as &$p) {
            $p['cve_sample'] = array_values(array_filter(self::pgArray((string) $p['cve_sample'])));
        }
        $rows = Db::all(
            "SELECT v.host_asset_id, h.host_name, h.ip, h.acr_score, h.host_tier, v.plugin_id, v.plugin_name, v.severity, v.vpr_score, v.cvss_v3, v.port, v.protocol,
                    array_to_string(v.cve, ', ') AS cve, v.exploit_available, v.first_seen::date AS first_seen, v.last_seen::date AS last_seen
             FROM vulnerabilities v JOIN host_risk h ON h.host_asset_id = v.host_asset_id WHERE $where
             ORDER BY v.vpr_score DESC NULLS LAST, v.severity DESC, h.acr_score DESC NULLS LAST, h.host_name LIMIT ?",
            array_merge($params, [$limit])
        );
        return ['query' => $q, 'plugins' => $plugins, 'rows' => $rows, 'note' => $plugins ? null : 'Nothing matched. Plugin IDs are digits, CVEs look like CVE-2024-1234, otherwise part of the plugin name is searched.'];
    }

    private static function group_members(array $a): array
    {
        $q = self::text($a, 'group');
        $limit = self::limit($a, 60, 300);
        $lq = strtolower($q);
        $select = "SELECT g.id, g.id AS ad_group_id, g.object_key, g.name, g.sam_account_name, g.group_type, g.member_count, g.description, t.tier, d.netbios_name AS directory
                   FROM ad_groups g LEFT JOIN v_ad_object_tier t ON t.object_key = g.object_key LEFT JOIN ad_domains d ON d.directory_id = g.directory_id";
        $group = Db::one("$select WHERE lower(g.name) = ? OR lower(g.sam_account_name) = ? ORDER BY g.member_count DESC NULLS LAST LIMIT 1", [$lq, $lq]);
        $candidates = [];
        if (!$group) {
            $candidates = Db::all("$select WHERE lower(coalesce(g.sam_account_name, '') || ' ' || coalesce(g.name, '')) LIKE ? ORDER BY g.member_count DESC NULLS LAST LIMIT 10", [self::like($lq)]);
            if (count($candidates) === 1) {
                $group = $candidates[0];
                $candidates = [];
            }
        }
        if (!$group) {
            return ['group' => null, 'candidates' => array_map(fn ($c) => ['name' => $c['name'], 'member_count' => $c['member_count'], 'tier' => $c['tier']], $candidates),
                    'note' => $candidates ? 'Several groups match; call again with one of the candidate names.' : 'No group matched.'];
        }
        $members = Db::all(
            "SELECT o.id AS ad_object_id, o.name, o.sam_account_name, o.object_type, o.enabled, t.tier, u.admin_count, u.last_logon::date AS last_logon, u.pwd_last_set::date AS pwd_last_set,
                    (SELECT count(DISTINCT a.host_asset_id) FROM identity_host_access a WHERE a.ad_object_key = o.object_key AND a.access_class <> 'scan') AS hosts_reached
             FROM ad_group_members m JOIN v_ad_objects o ON o.object_key = m.member_key
             LEFT JOIN v_ad_object_tier t ON t.object_key = o.object_key LEFT JOIN ad_users u ON u.object_key = o.object_key
             WHERE m.group_key = ? ORDER BY (o.object_type = 'group') DESC, t.tier NULLS LAST, o.name LIMIT ?",
            [$group['object_key'], $limit]
        );
        $memberOf = Db::all(
            'SELECT g.id AS ad_group_id, g.name, t.tier, g.member_count FROM ad_group_members m JOIN ad_groups g ON g.object_key = m.group_key
             LEFT JOIN v_ad_object_tier t ON t.object_key = g.object_key WHERE m.member_key = ? ORDER BY t.tier NULLS LAST, g.name LIMIT 50',
            [$group['object_key']]
        );
        unset($group['id'], $group['object_key']);
        return ['group' => $group, 'members' => $members, 'member_of' => $memberOf,
                'note' => count($members) >= $limit ? "Only the first $limit members are shown." : null];
    }

    private static function search_software(array $a): array
    {
        $name = self::text($a, 'name');
        $limit = self::limit($a, 40, 150);
        $like = self::like($name);
        $products = Db::all('SELECT name, versions, latest_version, hosts, last_installed::date AS last_installed, source_type FROM software_summary WHERE name_norm LIKE ? ORDER BY hosts DESC LIMIT 25', [$like]);
        $rows = Db::all(
            "SELECT h.host_asset_id, h.host_name, h.ip, split_part(h.os, E'\\n', 1) AS os, h.host_tier, h.acr_score, h.critical, s.name AS product, s.version,
                    hs.installed_at::date AS installed_at, hs.last_seen::date AS last_seen
             FROM software s JOIN host_software hs ON hs.software_id = s.id JOIN host_risk h ON h.host_asset_id = hs.host_asset_id
             WHERE s.name_norm LIKE ? ORDER BY h.host_tier NULLS LAST, h.acr_score DESC NULLS LAST, h.critical DESC, h.host_name LIMIT ?",
            [$like, $limit]
        );
        return ['name' => $name, 'products' => $products, 'rows' => $rows, 'note' => $products ? null : 'No product matched. The inventory holds software reported by Nessus plugins on scanned hosts.'];
    }

    private static function risk_overview(array $a): array
    {
        $top = self::limit(['limit' => $a['top'] ?? 10], 10, 30);
        $acr = (float) (Db::setting('ctem')['acr_threshold'] ?? 7);
        $dash = json_decode((string) Db::value('SELECT data FROM dashboard_summary'), true) ?: [];
        $risk = Db::one(
            "SELECT count(DISTINCT identity_key) FILTER (WHERE critical > 0 AND identity_kind NOT IN ('group', 'computer') AND access_class <> 'scan') AS users_reaching_critical,
                    count(DISTINCT identity_key) FILTER (WHERE acr_score >= ? AND identity_kind NOT IN ('group', 'computer') AND access_class <> 'scan') AS users_reaching_high_acr,
                    count(DISTINCT host_asset_id) FILTER (WHERE tier0_like AND access_class = 'usage') AS hosts_with_tier0_usage,
                    count(DISTINCT host_asset_id) FILTER (WHERE tier0_like AND access_class = 'usage' AND COALESCE(host_tier, 9) > 0) AS tier0_usage_outside_tier0,
                    count(DISTINCT identity_key) FILTER (WHERE critical > 0 AND access_type = 'service_account') AS service_accounts_on_critical
             FROM v_risk_access", [$acr]
        );
        $findings = Db::one(
            "SELECT count(*) AS hosts, count(*) FILTER (WHERE critical > 0) AS hosts_with_critical, sum(critical) AS critical, sum(high) AS high, sum(medium) AS medium, sum(low) AS low,
                    sum(exploitable) AS exploitable, count(*) FILTER (WHERE credentialed) AS credentialed_hosts, count(*) FILTER (WHERE NOT credentialed) AS uncredentialed_hosts,
                    count(*) FILTER (WHERE acr_score >= ?) AS high_acr_hosts, count(*) FILTER (WHERE host_tier = 0) AS tier0_hosts
             FROM host_risk", [$acr]
        );
        $tiers = [];
        foreach ($dash['tiers'] ?? [] as $t) {
            $tiers['tier_' . $t['tier']] = ($tiers['tier_' . $t['tier']] ?? 0) + (int) $t['n'];
        }
        $topHosts = Db::all(
            "SELECT a.host_asset_id, a.host_name, a.ip, max(a.critical) AS critical, max(a.max_vpr) AS max_vpr, max(a.acr_score) AS acr_score, max(a.host_tier) AS host_tier,
                    count(DISTINCT a.identity_key) AS identities, count(DISTINCT a.identity_key) FILTER (WHERE a.tier0_like) AS tier0_identities,
                    count(DISTINCT a.identity_key) FILTER (WHERE a.access_type = 'local_admin') AS local_admins
             FROM v_risk_access a WHERE a.access_class <> 'scan'
             GROUP BY a.host_asset_id, a.host_name, a.ip ORDER BY tier0_identities DESC, identities DESC, critical DESC LIMIT ?", [$top]
        );
        $matched = Db::one("SELECT count(*) AS identities, count(ad_object_key) AS matched_to_ad FROM principals WHERE scope IN ('domain', 'unknown')");
        $sync = Db::all("SELECT DISTINCT ON (connector) connector, kind, status, finished_at FROM sync_jobs WHERE status IN ('succeeded', 'failed') ORDER BY connector, id DESC");
        return [
            'risk' => $risk + ['acr_threshold' => $acr],
            'findings' => $findings + ['top_vpr_plugins' => array_slice($dash['top_vpr'] ?? [], 0, 5)],
            'ad' => ($dash['ad'] ?? []) + $tiers,
            'coverage' => ['credentialed_hosts' => $findings['credentialed_hosts'], 'uncredentialed_hosts' => $findings['uncredentialed_hosts'],
                           'identities_seen' => $matched['identities'], 'identities_matched_to_ad' => $matched['matched_to_ad']],
            'top_hosts' => $topHosts,
            'last_sync' => $sync,
        ];
    }

    private static function renderChart(array $a): array
    {
        $labels = array_values(array_map(fn ($l) => mb_substr((string) (is_scalar($l) ? $l : ''), 0, 60), (array) ($a['labels'] ?? [])));
        $values = array_values(array_map(fn ($v) => is_numeric($v) ? (float) $v : 0.0, (array) ($a['values'] ?? [])));
        if (!$labels || count($labels) !== count($values)) {
            throw new ApiError('labels and values must be non-empty arrays of the same length.');
        }
        return ['rendered' => true, 'chart' => [
            'type' => self::choice($a, 'type', ['bar', 'horizontalbar', 'doughnut', 'line'], 'bar'),
            'title' => mb_substr((string) ($a['title'] ?? ''), 0, 120), 'labels' => array_slice($labels, 0, 20), 'values' => array_slice($values, 0, 20),
            'series_label' => mb_substr((string) ($a['series_label'] ?? ''), 0, 60), 'palette' => self::choice($a, 'palette', ['severity', 'accent', 'tier'], 'accent'),
        ]];
    }

    private static function pgArray(string $literal): array
    {
        $inner = trim($literal, '{}');
        if ($inner === '') {
            return [];
        }
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $inner, $m, PREG_SET_ORDER);
        return array_map(fn ($x) => isset($x[2]) && $x[2] !== '' ? $x[2] : stripslashes($x[1]), $m);
    }
}
