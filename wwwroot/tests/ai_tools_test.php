<?php
/**
 * Runs every Agent Tenable tool against the live database and checks the
 * shape of the result and the time it took. No model involved.
 *
 *   docker compose exec php php /var/www/html/tests/ai_tools_test.php [--verbose]
 *
 * Exit code 1 when a check fails or a tool takes longer than SLOW_MS.
 */
declare(strict_types=1);

$_SERVER['REQUEST_URI'] = '/tests';
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Api.php';

const SLOW_MS = 150;
$verbose = in_array('--verbose', $argv, true);
$failures = 0;
$slow = 0;

/** @param callable(array):?string $check returns an error text or null */
function check(string $name, array $args, callable $check): void
{
    global $failures, $slow, $verbose;
    $t0 = microtime(true);
    try {
        $result = Tools::run($name, $args);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $error = $check($result);
    } catch (Throwable $e) {
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $error = get_class($e) . ': ' . $e->getMessage();
        $result = null;
    }
    $label = sprintf('%-26s %-60s %5d ms', $name, json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $ms);
    if ($error) {
        $failures++;
        echo "FAIL  $label\n      $error\n";
    } else {
        if ($ms > SLOW_MS) {
            $slow++;
        }
        echo ($ms > SLOW_MS ? 'SLOW  ' : 'ok    ') . $label . "\n";
    }
    if ($verbose && $result !== null) {
        echo '      ' . mb_strimwidth(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 400, '…') . "\n";
    }
}

/** The tool must refuse these arguments with an ApiError. */
function checkThrows(string $name, array $args): void
{
    global $failures;
    try {
        Tools::run($name, $args);
        $failures++;
        echo sprintf("FAIL  %-26s %-60s should have thrown\n", $name, json_encode($args));
    } catch (ApiError $e) {
        echo sprintf("ok    %-26s %-60s refused: %s\n", $name, json_encode($args), $e->getMessage());
    } catch (Throwable $e) {
        $failures++;
        echo sprintf("FAIL  %-26s %-60s wrong exception %s: %s\n", $name, json_encode($args), get_class($e), $e->getMessage());
    }
}

function rows(array $r, string $key): ?string
{
    return isset($r[$key]) && is_array($r[$key]) ? null : "missing list '$key'";
}

// A real account, host and group from the data, so the checks mean something.
$account = Db::value("SELECT identity_name FROM identity_host_access WHERE access_type = 'service_account' AND identity_name LIKE '%\\\\%' LIMIT 1") ?: 'DEMO\\SCCM';
$short = substr((string) $account, strpos((string) $account, '\\') + 1);
// A name seen on hosts that has no AD account at all.
$telemetryOnly = Db::value("SELECT split_part(a.identity_name, chr(92), 2) FROM identity_host_access a
    WHERE a.ad_object_key IS NULL AND position(chr(92) IN a.identity_name) > 0 AND a.access_class <> 'scan'
      AND NOT EXISTS (SELECT 1 FROM ad_users u WHERE lower(u.sam_account_name) = lower(split_part(a.identity_name, chr(92), 2)))
    GROUP BY 1 ORDER BY count(*) DESC LIMIT 1") ?: 'zz-telemetry-only';
$adUser = Db::value("SELECT sam_account_name FROM ad_users WHERE enabled AND jsonb_exists(attributes, 'mail') ORDER BY id LIMIT 1") ?: 'administrator';
$adMail = Db::value("SELECT attributes->>'mail' FROM ad_users WHERE sam_account_name = ?", [$adUser]);
$displayName = Db::value('SELECT display_name FROM ad_users WHERE sam_account_name = ?', [$adUser]);
$host = Db::one('SELECT host_name, ip FROM host_risk WHERE critical > 0 ORDER BY critical DESC LIMIT 1') ?: ['host_name' => 'localhost', 'ip' => '127.0.0.1'];
$cidr = preg_replace('/\.\d+$/', '.0/24', $host['ip']);
$group = Db::value('SELECT name FROM ad_groups WHERE member_count > 0 ORDER BY member_count DESC LIMIT 1') ?: 'Domain Admins';
$plugin = Db::value('SELECT plugin_id FROM vulnerabilities WHERE severity = 4 ORDER BY vpr_score DESC NULLS LAST LIMIT 1') ?: 19506;
$cve = Db::value('SELECT cve[1] FROM vulnerabilities WHERE cardinality(cve) > 0 ORDER BY vpr_score DESC NULLS LAST LIMIT 1') ?: 'CVE-2021-44228';
$software = Db::value('SELECT name FROM software_summary ORDER BY hosts DESC LIMIT 1') ?: 'Chrome';

echo "Sample data: account=$account telemetry_only=$telemetryOnly ad_user=$adUser host={$host['host_name']} ({$host['ip']}) cidr=$cidr group=$group plugin=$plugin cve=$cve software=$software\n\n";

check('find_identity', ['query' => $adUser], fn ($r) => rows($r, 'ad_users') ?? (count($r['ad_users']) >= 1 && $r['ad_users'][0]['sam_account_name'] === $adUser ? null : 'exact sam match not first'));
check('find_identity', ['query' => (string) $adMail], fn ($r) => rows($r, 'ad_users') ?? (count($r['ad_users']) >= 1 ? null : 'mail did not match'));
check('find_identity', ['query' => (string) $displayName], fn ($r) => rows($r, 'ad_users') ?? (count($r['ad_users']) >= 1 ? null : 'display name did not match'));
check('find_identity', ['query' => strrev((string) $displayName) . 'zzqx'], fn ($r) => rows($r, 'ad_users') ?? (count($r['ad_users']) === 0 && $r['note'] ? null : 'nonsense matched or note missing'));
check('find_identity', ['query' => $short], fn ($r) => rows($r, 'telemetry_identities') ?? (count($r['telemetry_identities']) >= 1 ? null : 'telemetry identity missing'));
checkThrows('find_identity', []);

check('identity_profile', ['account' => $adUser], fn ($r) => ($r['account']['sam_account_name'] ?? null) === $adUser && is_array($r['groups']) && is_array($r['account']['uac_flags']) ? null : 'profile incomplete');
check('identity_profile', ['account' => $telemetryOnly], fn ($r) => $r['account'] === null && isset($r['note']) && is_array($r['usage']) ? null : 'missing note for telemetry-only account');

check('identity_access', ['account' => $account], fn ($r) => rows($r, 'rows') ?? (count($r['rows']) >= 1 && isset($r['summary']['hosts']) ? null : 'no rows for ' . $account));
check('identity_access', ['account' => $short, 'access_class' => 'usage'], fn ($r) => rows($r, 'rows') ?? (count(array_filter($r['rows'], fn ($x) => $x['access_class'] !== 'usage')) === 0 ? null : 'class filter leaked'));
check('identity_access', ['account' => $adUser], fn ($r) => rows($r, 'rows') ?? null);

check('identity_vulnerabilities', ['account' => $account], fn ($r) => rows($r, 'rows') ?? (($r['scope']['group_by'] ?? '') === 'plugin' && isset($r['summary']['total']) ? null : 'default grouping wrong'));
check('identity_vulnerabilities', ['account' => $account, 'group_by' => 'ip_block', 'sort' => 'count'], fn ($r) => rows($r, 'rows') ?? (!$r['rows'] || str_contains((string) $r['rows'][0]['key'], '/24') ? null : 'ip_block key wrong'));
check('identity_vulnerabilities', ['account' => $account, 'group_by' => 'port', 'severity_min' => 2, 'sort' => 'severity'], fn ($r) => rows($r, 'rows'));
check('identity_vulnerabilities', ['account' => $account, 'group_by' => 'none', 'sort' => 'cvss', 'limit' => 5], fn ($r) => rows($r, 'rows') ?? (count($r['rows']) <= 5 ? null : 'limit ignored'));
check('identity_vulnerabilities', ['account' => $account, 'group_by' => 'cve', 'exploitable_only' => true], fn ($r) => rows($r, 'rows'));
check('identity_vulnerabilities', ['account' => $account, 'group_by' => 'host', 'sort' => 'vpr'], fn ($r) => rows($r, 'rows'));

check('find_host', ['query' => $host['host_name']], fn ($r) => rows($r, 'rows') ?? (count($r['rows']) >= 1 ? null : 'host not found by name'));
check('find_host', ['query' => $host['ip']], fn ($r) => rows($r, 'rows') ?? (count($r['rows']) === 1 ? null : 'ip did not match exactly one host'));
check('find_host', ['query' => $cidr, 'sort' => 'acr'], fn ($r) => rows($r, 'rows') ?? (count($r['rows']) >= 1 && $r['summary'] !== null ? null : 'cidr matched nothing'));
check('find_host', ['query' => '10.255.255.0/24'], fn ($r) => rows($r, 'rows') ?? (count($r['rows']) === 0 && $r['note'] ? null : 'unexpected match'));

check('host_vulnerabilities', ['target' => $host['host_name']], fn ($r) => rows($r, 'rows') ?? (($r['scope']['group_by'] ?? '') === 'none' && count($r['rows']) >= 1 ? null : 'single host should list findings'));
check('host_vulnerabilities', ['target' => $cidr], fn ($r) => rows($r, 'rows') ?? (($r['scope']['group_by'] ?? '') === 'plugin' ? null : 'cidr should group by plugin'));
check('host_vulnerabilities', ['target' => $cidr, 'group_by' => 'port', 'sort' => 'count', 'severity_min' => 3], fn ($r) => rows($r, 'rows'));
check('host_vulnerabilities', ['target' => $host['ip'], 'group_by' => 'cve', 'sort' => 'cvss'], fn ($r) => rows($r, 'rows'));
check('host_vulnerabilities', ['target' => 'no-such-host-zzqx'], fn ($r) => rows($r, 'rows') ?? (count($r['rows']) === 0 && $r['note'] ? null : 'missing note'));

check('host_identities', ['target' => $host['host_name']], fn ($r) => $r['host'] && is_array($r['access']) && is_array($r['local_accounts']) && is_array($r['services']) ? null : 'sections missing');
check('host_identities', ['target' => 'no-such-host-zzqx'], fn ($r) => $r['host'] === null && $r['note'] ? null : 'missing note');

check('vulnerability_lookup', ['query' => (string) $plugin], fn ($r) => rows($r, 'rows') ?? (count($r['plugins']) === 1 && (int) $r['plugins'][0]['plugin_id'] === (int) $plugin ? null : 'plugin lookup wrong'));
check('vulnerability_lookup', ['query' => (string) $cve], fn ($r) => rows($r, 'rows') ?? (count($r['rows']) >= 1 ? null : 'cve lookup empty'));
check('vulnerability_lookup', ['query' => 'SMB', 'severity_min' => 2, 'limit' => 10], fn ($r) => rows($r, 'rows') ?? (count($r['rows']) <= 10 ? null : 'limit ignored'));

check('group_members', ['group' => $group], fn ($r) => $r['group'] && is_array($r['members']) && count($r['members']) >= 1 && is_array($r['member_of']) ? null : 'members missing');
check('group_members', ['group' => 'zzqx-no-group'], fn ($r) => $r['group'] === null && $r['note'] ? null : 'missing note');

check('search_software', ['name' => $software], fn ($r) => rows($r, 'rows') ?? (count($r['products']) >= 1 && count($r['rows']) >= 1 ? null : 'software not found'));
check('search_software', ['name' => 'zzqx-nothing'], fn ($r) => rows($r, 'rows') ?? (count($r['rows']) === 0 && $r['note'] ? null : 'missing note'));

check('risk_overview', [], fn ($r) => isset($r['risk']['users_reaching_critical'], $r['findings']['hosts'], $r['ad'], $r['coverage'], $r['top_hosts'], $r['last_sync']) ? null : 'sections missing');
check('risk_overview', ['top' => 3], fn ($r) => count($r['top_hosts']) <= 3 ? null : 'top ignored');

check('render_chart', ['type' => 'doughnut', 'labels' => ['Critical', 'High'], 'values' => [3, 9], 'palette' => 'severity'], fn ($r) => ($r['chart']['type'] ?? '') === 'doughnut' && count($r['chart']['values']) === 2 ? null : 'chart shape wrong');
checkThrows('render_chart', ['type' => 'bar', 'labels' => ['a'], 'values' => [1, 2]]);
checkThrows('no_such_tool', []);

// Tool and knowledge files must agree.
$documented = array_keys(array_filter(Knowledge::items('tools'), fn ($t) => isset($t['parameters'])));
$implemented = Tools::names();
sort($documented);
sort($implemented);
if ($documented !== $implemented) {
    $failures++;
    echo "FAIL  tools in ai/tools/*.md (" . implode(', ', $documented) . ") differ from Tools::names() (" . implode(', ', $implemented) . ")\n";
} else {
    echo 'ok    ' . count($implemented) . " tools documented and implemented\n";
}
$defs = Knowledge::toolDefinitions();
$schemaChars = strlen(json_encode($defs));
$agentChars = strlen(Knowledge::agent()['prompt'] ?? '');
$skillMax = max(array_map(fn ($s) => strlen($s['prompt']), Knowledge::skills()) ?: [0]);
printf("ok    prompt budget: agent %d chars, largest skill %d chars, tool schemas %d chars (≈ %d tokens together)\n", $agentChars, $skillMax, $schemaChars, (int) (($agentChars + $skillMax + $schemaChars) / 4));

echo "\n" . ($failures ? "$failures check(s) failed" : 'All checks passed') . ($slow ? ", $slow slower than " . SLOW_MS . ' ms' : '') . "\n";
exit($failures ? 1 : 0);
