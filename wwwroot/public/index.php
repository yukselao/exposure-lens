<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Api.php';

$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';

/** path => [page id, title, main menu section] */
$routes = [
    '/' => ['dashboard', 'Dashboard', 'dashboard'],
    '/explore/assets' => ['explore-assets', 'Asset Inventory', 'explore'],
    '/explore/ad-objects' => ['explore-ad', 'AD Objects', 'explore'],
    '/explore/software' => ['explore-software', 'Software Inventory', 'explore'],
    '/explore/local-users' => ['explore-local-users', 'Local Users', 'explore'],
    '/explore/browser-extensions' => ['explore-extensions', 'Browser Extensions', 'explore'],
    '/explore/findings' => ['explore-findings', 'Findings', 'explore'],
    '/risk' => ['risk', 'Risk', 'risk'],
    '/reports' => ['reports', 'Reports', 'reports'],
    '/settings/security-center' => ['settings-sc', 'Security Center', 'settings'],
    '/settings/identity-exposure' => ['settings-tie', 'Identity Exposure', 'settings'],
    '/settings/ctem' => ['settings-ctem', 'CTEM', 'settings'],
    '/settings/ctem/plugins' => ['settings-catalog', 'Plugin Catalog', 'settings'],
    '/settings/agent' => ['settings-ai', 'Agent Tenable', 'settings'],
    '/logs' => ['logs', 'Connector Logs', 'logs'],
];

if ($path === '/settings') {
    header('Location: /settings/security-center', true, 302);
    exit;
}
if ($path === '/explore') {
    header('Location: /explore/assets', true, 302);
    exit;
}

$route = $routes[$path] ?? null;
if ($route === null) {
    http_response_code(404);
    $route = ['not-found', 'Page not found', ''];
}
[$page, $title, $section] = $route;

$context = ['page' => $page, 'title' => $title, 'section' => $section];
try {
    if ($page === 'settings-sc') {
        $context['sc'] = Api::scPublicSettings();
    } elseif ($page === 'settings-tie') {
        $context['tie'] = Api::tiePublicSettings();
    } elseif ($page === 'settings-ctem') {
        $context['ctem'] = Api::ctemPublicSettings();
    } elseif ($page === 'settings-ai') {
        $context['ai'] = Api::aiPublicSettings();
    } elseif ($page === 'logs') {
        $context['connectors'] = Logs::CONNECTORS;
    }
} catch (Throwable $e) {
    error_log('page ' . $page . ': ' . get_class($e) . ': ' . $e->getMessage());
    $context['db_error'] = true;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
    . "script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
echo twig()->render('base.html.twig', $context);
