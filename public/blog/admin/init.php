<?php
// init.php — bootstrap for the ADMIN area. Included by every admin page.
// Loads the shared core, then starts a hardened session and defines auth/CSRF.
declare(strict_types=1);
require_once __DIR__ . '/core.php';

/* Harden the session cookie BEFORE it is created:
   - httponly: JavaScript can't read it (limits damage from any XSS)
   - secure:   only sent over HTTPS, but only when the request IS https
               (so local http testing still works)
   - samesite: not sent on cross-site requests (CSRF defence in depth) */
$__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'secure'   => $__https,
    'samesite' => 'Lax',
]);
session_start();

define('DATA_DIR', dirname(__DIR__) . '/data'); // private runtime data (login throttle)

// The admin password hash lives in /config.php (the one per-site file).
$cfg = require dirname(__DIR__) . '/config.php';
define('ADMIN_HASH', (string)($cfg['admin_hash'] ?? ''));

/* ----------------------------------------------------------------------
   AUTH
---------------------------------------------------------------------- */
function is_logged_in(): bool {
    return ($_SESSION['admin'] ?? false) === true;
}
function require_auth(): void {
    if (!is_logged_in()) { header('Location: login.php'); exit; }
}

/* ----------------------------------------------------------------------
   CSRF — protects the save/delete actions from forged requests
---------------------------------------------------------------------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}
function csrf_check(): void {
    $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
    if (!$ok) { http_response_code(403); exit('Bad request token.'); }
}

/* ----------------------------------------------------------------------
   LOGIN THROTTLE — slows password brute-forcing on the public admin URL.
   Tracks recent failures per IP in a private JSON file under /data.
---------------------------------------------------------------------- */
const LOGIN_MAX_FAILS   = 5;     // failures allowed within the window
const LOGIN_WINDOW_SECS = 900;   // 15-minute rolling window
const LOGIN_LOCK_SECS   = 900;   // lockout length once the limit is hit

function _login_read(): array {
    $f = DATA_DIR . '/login-attempts.json';
    if (!is_file($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
/* Atomic read-modify-write under an exclusive lock, so two simultaneous failed
   logins can't clobber each other's count (closes the throttle race). */
function _login_update(callable $mutate): void {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
        @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n"); // keep it un-web-readable
    }
    $fh = fopen(DATA_DIR . '/login-attempts.json', 'c+');   // create if missing; don't truncate on open
    if ($fh === false) return;
    if (flock($fh, LOCK_EX)) {
        $raw  = stream_get_contents($fh);
        $data = json_decode($raw ?: '[]', true);
        $data = $mutate(is_array($data) ? $data : []);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}
function login_locked_seconds(string $ip): int {
    $until = _login_read()[$ip]['until'] ?? 0;
    return $until > time() ? $until - time() : 0;
}
function login_register_failure(string $ip): void {
    _login_update(function (array $data) use ($ip): array {
        $now = time();
        $rec = $data[$ip] ?? ['count' => 0, 'first' => $now, 'until' => 0];
        if ($now - ($rec['first'] ?? $now) > LOGIN_WINDOW_SECS) {
            $rec = ['count' => 0, 'first' => $now, 'until' => 0];   // window expired → reset
        }
        $rec['count']++;
        if ($rec['count'] >= LOGIN_MAX_FAILS) {
            $rec = ['count' => 0, 'first' => $now, 'until' => $now + LOGIN_LOCK_SECS];
        }
        $data[$ip] = $rec;
        foreach ($data as $k => $v) {            // prune stale entries
            if (($v['until'] ?? 0) < $now && ($now - ($v['first'] ?? 0)) > 3600) unset($data[$k]);
        }
        return $data;
    });
}
function login_clear(string $ip): void {
    _login_update(fn(array $data): array => array_diff_key($data, [$ip => 1]));
}
