<?php
/**
 * Checks the central asset table: sync of an identity and a host against the live
 * database, the shape of the stored snapshot, determinism (two syncs give the same
 * analysis) and the saved-conversation actions. Everything runs in one transaction
 * that is rolled back, so the database is left as it was.
 *
 *   docker compose exec php php /var/www/html/tests/assets_test.php
 */
declare(strict_types=1);

$_SERVER['REQUEST_URI'] = '/tests';
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Api.php';

$failures = 0;
function ok(bool $cond, string $what): void
{
    global $failures;
    echo ($cond ? 'ok    ' : 'FAIL  ') . $what . "\n";
    if (!$cond) {
        $failures++;
    }
}

Db::run('BEGIN', []);
try {
    // an AD user that reaches hosts, and a host that identities reach
    $user = Db::one("SELECT u.id, u.object_key FROM ad_users u JOIN identity_host_access a ON a.ad_object_key = u.object_key
                     WHERE a.access_class <> 'scan' GROUP BY u.id, u.object_key ORDER BY count(*) DESC LIMIT 1");
    $host = (int) Db::value("SELECT host_asset_id FROM identity_host_access WHERE access_class <> 'scan' GROUP BY 1 ORDER BY count(*) DESC LIMIT 1");
    ok($user !== null && $host > 0, 'test data: an AD user and a host with access facts');

    if ($user) {
        $t0 = microtime(true);
        $a = Assets::sync('identity', ['ad_user_id' => (int) $user['id']], 'manual');
        $ms = (microtime(true) - $t0) * 1000;
        $p = Assets::get($a['id']);
        ok($p['asset_kind'] === 'identity' && $p['asset_key'] === $user['object_key'], 'identity snapshot keyed by the AD object key');
        ok(!empty($p['profile']['display_name']) || !empty($p['profile']['account']), 'identity card has a name');
        ok(count($p['systems']) > 0 && isset($p['systems'][0]['host_asset_id'], $p['systems'][0]['access_type']), 'connected systems stored');
        ok(array_key_exists('evidence_plugin_id', $p['systems'][0]) && array_key_exists('output_excerpt', $p['systems'][0]), 'correlation evidence carries plugin and output excerpt');
        $f = $p['systems'][0];
        ok(!empty($f['explanation']) && count($f['correlation'] ?? []) >= 3 && in_array($f['match_quality'] ?? '', ['exact', 'name', 'inferred', 'demo alias', 'telemetry only'], true),
            'each access fact carries an explanation, a correlation chain and a match quality');
        $via = array_values(array_filter($p['systems'], fn ($s) => !empty($s['via_group'])));
        ok(!$via || in_array('membership', array_column($via[0]['correlation'], 'kind'), true), 'a right held through a group explains the AD membership');
        $conns = $p['profile']['connections'] ?? [];
        $hostsReached = count(array_unique(array_column($p['systems'], 'host_asset_id')));
        ok(count($conns) === $hostsReached, 'one connection summary per connected system');
        ok($conns && !empty($conns[0]['summary']) && $conns[0]['facts'] && isset($p['systems'][$conns[0]['facts'][0]]), 'a connection has a summary and points at its access facts');
        ok(isset($p['risk']['score'], $p['risk']['level'], $p['risk']['factors'], $p['risk']['questions']), 'risk block has score, level, factors, questions');
        ok($p['risk']['score'] >= 0 && $p['risk']['score'] <= 100, 'risk score within 0..100');
        ok($ms < 2000, sprintf('identity sync took %.0f ms', $ms));

        $again = Assets::get(Assets::sync('identity', ['ad_user_id' => (int) $user['id']], 'agent')['id']);
        ok($again['id'] === $p['id'], 'a re-sync updates the same row');
        ok($again['risk'] == $p['risk'] && $again['summary'] == $p['summary'] && $again['systems'] == $p['systems'], 'the same data gives the same analysis');
        ok($again['source'] === 'agent', 'source records who synced');
    }

    $h = Assets::get(Assets::sync('host', ['host_asset_id' => $host])['id']);
    ok($h['asset_kind'] === 'host' && (int) $h['host_asset_id'] === $host, 'host snapshot keyed by host id');
    ok(isset($h['summary']['identities']) && count($h['systems']) > 0, 'host lists the identities that reach it');
    ok(count($h['profile']['connections'] ?? []) === count(array_unique(array_column($h['systems'], 'identity_key'))), 'host has one connection summary per identity');

    $list = Assets::list('host', '', 50, 0, 'risk');
    ok($list['total'] >= 1 && $list['rows'][0]['asset_type'] === 'host', 'list filters by type');

    try {
        Assets::sync('identity', ['account' => 'zz-no-such-account-qq'], 'manual');
        ok(false, 'unknown account is refused');
    } catch (ApiError $e) {
        ok($e->status === 404, 'unknown account is refused with 404');
    }

    // sync history: a bulk run records counts and items; a single sync is logged as a run of one
    $run = Assets::runStart('bulk', 'test run', ['reach' => 'yes'], 3);
    $b = Assets::syncBatch(['reach' => 'yes'], 0, 3, 'bulk', $run);
    $fin = Assets::runFinish($run, 'done');
    $detail = Assets::run($run);
    ok($fin['status'] === 'done' && (int) $fin['synced'] === $b['synced'] && (int) $fin['failed'] === $b['failed'], 'a bulk run records synced and failed counts');
    ok(count($detail['items']) === $b['synced'] + $b['failed'] && !empty($detail['items'][0]['name']) && isset($detail['items'][0]['ms']), 'a bulk run lists every asset it processed');
    ok((int) $detail['work_ms'] >= 0 && $detail['finished_at'] !== null, 'a bulk run records its work time and end');
    $one = Assets::sync('identity', ['ad_user_id' => (int) $user['id']]);
    Assets::logSingle('row', $one['name'], $one, null, $one['ms']);
    $runs = Assets::runs('single', 5, 0);
    ok($runs['rows'] && $runs['rows'][0]['trigger'] === 'row' && (int) $runs['rows'][0]['synced'] === 1, 'a single sync is logged as a run of one');
    ok(Assets::runs('bulk', 5, 0)['rows'][0]['id'] == $run, 'history filters by trigger');

    // saved conversations
    $c = Api::aiConversationSave(['messages' => json_encode([['role' => 'user', 'content' => 'test question'], ['role' => 'assistant', 'content' => 'answer']]), 'skill' => 'x']);
    ok($c['id'] > 0 && $c['title'] === 'test question', 'conversation saved with a title from the first question');
    Api::aiConversationSave(['id' => (string) $c['id'], 'messages' => json_encode([['role' => 'user', 'content' => 'test question'], ['role' => 'assistant', 'content' => 'answer'], ['role' => 'user', 'content' => 'more']])]);
    ok(count(Api::aiConversation(['id' => (string) $c['id']])['messages']) === 3, 'conversation updated in place');
    ok(Api::aiConversationDelete(['id' => (string) $c['id']])['deleted'] === 1, 'conversation deleted');
} catch (Throwable $e) {
    ok(false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    Db::run('ROLLBACK', []);
}

echo $failures ? "\n$failures check(s) failed\n" : "\nAll checks passed\n";
exit($failures ? 1 : 0);
