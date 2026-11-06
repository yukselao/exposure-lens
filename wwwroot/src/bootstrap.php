<?php
declare(strict_types=1);

date_default_timezone_set(getenv('TZ') ?: 'Europe/Istanbul');

require '/opt/app/vendor/autoload.php';
require __DIR__ . '/Db.php';
require __DIR__ . '/Crypto.php';
require __DIR__ . '/Jobs.php';
require __DIR__ . '/Logs.php';

session_name('el_session');
session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
// Nothing else is kept in the session; release the lock so parallel AJAX calls do not queue.
$GLOBALS['CSRF'] = $_SESSION['csrf'];
session_write_close();

function csrf_token(): string
{
    return $GLOBALS['CSRF'];
}

/** Version string for asset URLs: changes whenever the file changes. */
function asset_ver(string $path): string
{
    $mtime = @filemtime(__DIR__ . '/../public' . $path);
    return $mtime ? (string) $mtime : '0';
}

function twig(): \Twig\Environment
{
    static $twig = null;
    if ($twig === null) {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : 0;
        $cache = '/tmp/twig-' . $uid;
        if (!is_dir($cache)) {
            @mkdir($cache, 0700, true);
        }
        $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates'), [
            'cache' => is_writable($cache) ? $cache : false,
            'auto_reload' => true,
            'strict_variables' => false,
        ]);
        $twig->addFunction(new \Twig\TwigFunction('asset', fn (string $p) => $p . '?v=' . asset_ver($p)));
        $twig->addGlobal('CSRF', csrf_token());
    }
    return $twig;
}
