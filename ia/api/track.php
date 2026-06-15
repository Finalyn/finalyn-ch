<?php
/** Traceur de pages maison (respecte le consentement, cote client). Sans cookie tiers. */
require __DIR__ . '/guard.php';
require __DIR__ . '/db.php';

finalyn_json_out();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') finalyn_die(405, 'Methode non autorisee.');
finalyn_check_origin();
finalyn_rate_limit('track', 240, 600);

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { echo json_encode(['ok' => false]); exit; }

$path = isset($body['path']) && is_string($body['path']) ? mb_substr($body['path'], 0, 300) : '';
$ref  = isset($body['ref']) && is_string($body['ref']) ? mb_substr($body['ref'], 0, 300) : '';
if ($path === '') { echo json_encode(['ok' => false]); exit; }

// On ne garde que le chemin (pas de query string a rallonge)
$path = strtok($path, '?');

try {
    $pdo = finalyn_db();
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare('INSERT INTO pageviews (created_at, path, referrer, visitor_hash) VALUES (?,?,?,?)');
    $stmt->execute([$now, $path, $ref, finalyn_ip_hash()]);
} catch (Throwable $e) {
    error_log('finalyn track: ' . $e->getMessage());
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
