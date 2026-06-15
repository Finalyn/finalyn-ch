<?php
/** Authentification et utilitaires partages du back-office. */
require_once __DIR__ . '/../api/config_load.php';
require_once __DIR__ . '/../api/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('finalyn_admin');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function finalyn_password_configured() {
    $cfg = finalyn_config();
    return !empty($cfg['admin_password_hash']) || !empty($cfg['admin_password']);
}

function finalyn_password_ok($input) {
    $cfg = finalyn_config();
    $hash = $cfg['admin_password_hash'] ?? '';
    $plain = $cfg['admin_password'] ?? '';
    if ($hash !== '') return password_verify($input, $hash);
    if ($plain !== '') return hash_equals($plain, (string)$input);
    return false;
}

function finalyn_is_logged() {
    return !empty($_SESSION['finalyn_admin']);
}

function finalyn_require_login() {
    if (!finalyn_is_logged()) {
        header('Location: login.php');
        exit;
    }
}

function finalyn_csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function finalyn_csrf_ok($token) {
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

/** Echappement HTML court. */
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
