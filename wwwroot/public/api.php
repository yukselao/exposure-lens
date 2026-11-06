<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/Api.php';

$action = (string) ($_GET['action'] ?? '');

// Log download is the only action that does not answer with JSON.
if ($action === 'logs_download') {
    try {
        Logs::download((string) ($_GET['connector'] ?? ''));
    } catch (Throwable $e) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unknown connector.';
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$handler = Api::ACTIONS[$action] ?? null;
if ($handler === null) {
    respond(['error' => 'Unknown action.'], 404);
}
[$method, $function] = $handler;
if ($_SERVER['REQUEST_METHOD'] !== $method) {
    respond(['error' => "This action needs $method."], 405);
}
if ($method === 'POST' && !hash_equals(csrf_token(), (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    respond(['error' => 'The page is out of date. Reload it and try again.'], 403);
}

try {
    respond(Api::$function($method === 'POST' ? $_POST : $_GET));
} catch (ApiError $e) {
    respond(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('api ' . $action . ': ' . get_class($e) . ': ' . $e->getMessage());
    respond(['error' => 'The request failed on the server. Details are in the php container log.'], 500);
}
