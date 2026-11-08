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

// Agent Tenable: the chat streams server-sent events; the knowledge export is a zip;
// the pack upload is multipart. All three need the CSRF token like any POST.
if (in_array($action, ['ai_chat', 'ai_pack_upload'], true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf_token(), (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'The page is out of date. Reload it and try again.']);
        exit;
    }
    if ($action === 'ai_chat') {
        Chat::stream($_POST);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    try {
        $file = $_FILES['pack'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            throw new ApiError(in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The file is larger than 20 MB.' : 'Choose a zip file to upload.');
        }
        echo json_encode(['ok' => true] + Knowledge::importPack((string) $file['tmp_name'], (string) $file['name']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (ApiError $e) {
        http_response_code($e->status);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('ai_pack_upload: ' . get_class($e) . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'The upload failed on the server. Details are in the php container log.']);
    }
    exit;
}
if ($action === 'ai_knowledge_download') {
    try {
        $zip = Knowledge::zip();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="exposure-lens-knowledge-' . date('Ymd-His') . '.zip"');
        header('Content-Length: ' . filesize($zip));
        header('Cache-Control: no-store');
        readfile($zip);
        unlink($zip);
    } catch (Throwable $e) {
        error_log('ai_knowledge_download: ' . get_class($e) . ': ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'The export failed.';
    }
    exit;
}

if ($action === 'risk_export') {
    try {
        Api::riskExport($_GET);
    } catch (Throwable $e) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'The export failed.';
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
