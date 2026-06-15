<?php
/** Garde-fous partages par les endpoints publics (book, track, availability). */

$FINALYN_ALLOWED_HOSTS = ['finalyn.com', 'finalyn.ch', 'localhost', '127.0.0.1'];

function finalyn_json_out() {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

function finalyn_die($status, $msg) {
    http_response_code($status);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function finalyn_check_origin() {
    global $FINALYN_ALLOWED_HOSTS;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return; // meme-origine : pas d'en-tete Origin
    $host = parse_url($origin, PHP_URL_HOST) ?: '';
    foreach ($FINALYN_ALLOWED_HOSTS as $s) {
        if ($host === $s || str_ends_with($host, '.' . $s)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            return;
        }
    }
    finalyn_die(403, 'Origine non autorisee.');
}

function finalyn_rate_limit($prefix, $max, $window) {
    $dir = __DIR__ . '/.cache';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (!is_dir($dir) || !is_writable($dir)) return;
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'x');
    $f = $dir . '/' . $prefix . '_' . hash('sha256', $ip) . '.json';
    $now = time();
    $b = ['count' => 0, 'reset' => $now + $window];
    if (is_file($f)) {
        $r = json_decode((string)@file_get_contents($f), true);
        if (is_array($r) && ($r['reset'] ?? 0) > $now) $b = $r;
    }
    $b['count'] = (int)$b['count'] + 1;
    @file_put_contents($f, json_encode($b), LOCK_EX);
    if ($b['count'] > $max) {
        finalyn_die(429, 'Trop de requetes. Merci de patienter quelques minutes.');
    }
}
