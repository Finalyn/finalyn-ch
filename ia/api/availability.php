<?php
/** Disponibilites du calendrier public : jours ouvres, creneaux, dates bloquees/completes. */
require __DIR__ . '/guard.php';
require __DIR__ . '/db.php';
require __DIR__ . '/settings.php';

finalyn_json_out();
header('Cache-Control: public, max-age=60');
finalyn_check_origin();

$avail = finalyn_avail();
$today = gmdate('Y-m-d');
$blocked = [];
$taken = []; // { 'YYYY-MM-DD' : ['HH:MM', ...] } creneaux indisponibles (reserves ou bloques)

try {
    $pdo = finalyn_db();
    $slotCount = max(1, count($avail['slots']));

    $stmt = $pdo->prepare('SELECT slot_date FROM blocked_dates WHERE slot_date >= ?');
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) { $blocked[$d] = true; }

    // Creneaux deja reserves
    $stmt = $pdo->prepare("SELECT slot_date, slot_time FROM bookings WHERE status != 'cancelled' AND slot_date >= ?");
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $taken[$r['slot_date']][] = $r['slot_time']; }

    // Creneaux bloques a la main par l'admin
    $stmt = $pdo->prepare("SELECT slot_date, slot_time FROM blocked_slots WHERE slot_date >= ?");
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $taken[$r['slot_date']][] = $r['slot_time']; }

    // Un jour dont tous les creneaux sont pris est considere comme complet
    foreach ($taken as $d => $times) {
        if (count(array_unique($times)) >= $slotCount) { $blocked[$d] = true; }
    }
} catch (Throwable $e) {
    error_log('finalyn availability: ' . $e->getMessage());
}

echo json_encode([
    'blocked'  => array_keys($blocked),
    'taken'    => (object)$taken,
    'days'     => $avail['days'],
    'slots'    => $avail['slots'],
    'duration' => $avail['duration'],
], JSON_UNESCAPED_UNICODE);
