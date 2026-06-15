<?php
/** Sert l'invitation .ics d'une reservation (lien "Ajouter au calendrier" des e-mails). Acces par jeton. */
require __DIR__ . '/config_load.php';
require __DIR__ . '/db.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/mail.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$t  = isset($_GET['t']) ? preg_replace('/[^a-f0-9]/', '', (string)$_GET['t']) : '';
if ($id < 1 || $t === '') { http_response_code(400); header('Content-Type: text/plain; charset=utf-8'); exit('Lien invalide.'); }

$pdo = finalyn_db();
$q = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
$q->execute([$id]);
$row = $q->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['token']) || !hash_equals((string)$row['token'], $t)) {
    http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Rendez-vous introuvable.');
}

$cfg = finalyn_config();
$organizer = $cfg['notify_email'] ?? 'contact@finalyn.com';
if (!filter_var($organizer, FILTER_VALIDATE_EMAIL)) { $organizer = 'contact@finalyn.com'; }
$duration = max(15, (int)(finalyn_avail()['duration'] ?? 30));

$ics = finalyn_build_ics($row['firstname'], $row['lastname'], $row['email'], $row['slot_date'], $row['slot_time'], $duration, $organizer);
if ($ics === '') { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); exit('Erreur de generation.'); }

header('Content-Type: text/calendar; charset=utf-8; method=REQUEST');
header('Content-Disposition: attachment; filename="audit-finalyn.ics"');
header('Cache-Control: no-store');
echo $ics;
