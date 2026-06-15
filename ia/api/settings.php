<?php
/** Reglages de disponibilite du calendrier (style Calendly), stockes en base. */
require_once __DIR__ . '/db.php';

function finalyn_avail_default() {
    return [
        'days'     => [1, 2, 3, 4, 5], // ISO : 1=lundi .. 7=dimanche
        'slots'    => ['08:00', '09:00', '10:00', '11:00', '14:00', '15:00', '16:00', '17:00'],
        'duration' => 30,
    ];
}

function finalyn_avail() {
    $def = finalyn_avail_default();
    try {
        $pdo = finalyn_db();
        $s = $pdo->prepare('SELECT sval FROM settings WHERE skey = ?');
        $s->execute(['availability']);
        $raw = $s->fetchColumn();
        if ($raw) {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                return [
                    'days'     => (isset($j['days']) && is_array($j['days']) && $j['days']) ? array_values(array_map('intval', $j['days'])) : $def['days'],
                    'slots'    => (isset($j['slots']) && is_array($j['slots']) && $j['slots']) ? array_values($j['slots']) : $def['slots'],
                    'duration' => isset($j['duration']) ? (int)$j['duration'] : $def['duration'],
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('finalyn avail: ' . $e->getMessage());
    }
    return $def;
}

function finalyn_avail_save($cfg) {
    $pdo = finalyn_db();
    $pdo->prepare('INSERT INTO settings (skey, sval) VALUES (?, ?) ON CONFLICT(skey) DO UPDATE SET sval = excluded.sval')
        ->execute(['availability', json_encode($cfg)]);
}
