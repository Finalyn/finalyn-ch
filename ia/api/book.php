<?php
/** Enregistre une reservation d'audit (calendrier maison) + notifie par e-mail. */
require __DIR__ . '/guard.php';
require __DIR__ . '/config_load.php';
require __DIR__ . '/db.php';
require __DIR__ . '/settings.php';

finalyn_json_out();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') finalyn_die(405, 'Methode non autorisee.');
finalyn_check_origin();
finalyn_rate_limit('book', 8, 600);

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) finalyn_die(400, 'Requete invalide.');

function finalyn_field($body, $key, $max = 120) {
    $v = isset($body[$key]) && is_string($body[$key]) ? trim($body[$key]) : '';
    return mb_substr($v, 0, $max);
}

require __DIR__ . '/mail.php';

$firstname = finalyn_field($body, 'firstname', 80);
$lastname  = finalyn_field($body, 'lastname', 80);
$email     = finalyn_field($body, 'email', 160);
$company   = finalyn_field($body, 'company', 120);
$date      = finalyn_field($body, 'date', 10);
$time      = finalyn_field($body, 'time', 5);
$message   = finalyn_field($body, 'message', 500);

// Validation
if ($firstname === '' || $lastname === '' || $email === '' || $company === '' || $date === '' || $time === '') {
    finalyn_die(400, 'Merci de remplir tous les champs.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) finalyn_die(400, 'Adresse e-mail invalide.');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) finalyn_die(400, 'Date invalide.');
if (!preg_match('/^\d{2}:\d{2}$/', $time)) finalyn_die(400, 'Heure invalide.');

// Date dans le futur et jour ouvre (lundi-vendredi)
$ts = strtotime($date . ' ' . $time . ':00');
if ($ts === false) finalyn_die(400, 'Creneau invalide.');
if ($ts < time() + 12 * 3600) finalyn_die(400, "Les rendez-vous se reservent au moins une demi-journee a l'avance (12 h). Merci de choisir un creneau un peu plus tard.");
$weekday = (int)date('N', strtotime($date)); // 1=lundi .. 7=dimanche
if ($weekday >= 6) finalyn_die(400, 'Les audits ont lieu du lundi au vendredi.');

$pdo = finalyn_db();

// Date bloquee par l'admin ?
$chk = $pdo->prepare('SELECT 1 FROM blocked_dates WHERE slot_date = ?');
$chk->execute([$date]);
if ($chk->fetchColumn()) finalyn_die(409, 'Ce jour n\'est plus disponible. Merci d\'en choisir un autre.');

// Creneau deja pris ?
$dup = $pdo->prepare("SELECT 1 FROM bookings WHERE slot_date = ? AND slot_time = ? AND status != 'cancelled'");
$dup->execute([$date, $time]);
if ($dup->fetchColumn()) finalyn_die(409, 'Ce creneau vient d\'etre reserve. Merci d\'en choisir un autre.');

$now = gmdate('Y-m-d H:i:s');
$token = bin2hex(random_bytes(16));
$ins = $pdo->prepare("INSERT INTO bookings (created_at, firstname, lastname, email, company, slot_date, slot_time, message, status, token)
                      VALUES (?,?,?,?,?,?,?,?, 'confirmed', ?)");
$ins->execute([$now, $firstname, $lastname, $email, $company, $date, $time, $message, $token]);
$bookingId = (int)$pdo->lastInsertId();
$cancelUrl = 'https://finalyn.ch/ia/api/cancel.php?id=' . $bookingId . '&t=' . $token;

// ----- E-mails + invitation agenda (best effort, ne bloquent jamais la reservation) -----
$cfg      = finalyn_config();
$team     = $cfg['notify_email'] ?? '';
$from      = $cfg['from_email'] ?? 'noreply@finalyn.com';
$organizer = ($team !== '' && filter_var($team, FILTER_VALIDATE_EMAIL)) ? $team : 'contact@finalyn.com';
$duration  = max(15, (int)(finalyn_avail()['duration'] ?? 30));

$frMonths = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$dts = strtotime($date);
$dateFr = (int)date('j', $dts) . ' ' . $frMonths[(int)date('n', $dts)] . ' ' . date('Y', $dts);

// Invitation .ics jointe + lien "Ajouter au calendrier" universel (sert le .ics : l'appareil choisit l'agenda ou met le defaut)
$ics    = finalyn_build_ics($firstname, $lastname, $email, $date, $time, $duration, $organizer) ?: null;
$icsUrl = 'https://finalyn.ch/ia/api/ics.php?id=' . $bookingId . '&t=' . $token;

// 1) Notification a l'equipe finalyn (Reply-To = client) - HTML + texte
if ($team !== '') {
    $tBody = "Nouvelle reservation d'audit via finalyn.ch/ia\n\n"
        . 'Nom        : ' . $firstname . ' ' . $lastname . "\n"
        . 'E-mail     : ' . $email . "\n"
        . 'Entreprise : ' . $company . "\n"
        . 'Creneau    : ' . $dateFr . ' a ' . $time . " (heure de Zurich)\n";
    if ($message !== '') { $tBody .= "\nMessage    : " . $message . "\n"; }
    $tBody .= "\nAjouter au calendrier : " . $icsUrl . "\n";

    $tPar = '<p style="margin:0 0 14px;">Nouvelle réservation d\'audit via <strong>finalyn.ch/ia</strong>.</p>'
        . '<p style="margin:0 0 6px;"><strong>Nom</strong> : ' . htmlspecialchars($firstname . ' ' . $lastname) . '<br>'
        . '<strong>E-mail</strong> : ' . htmlspecialchars($email) . '<br>'
        . '<strong>Entreprise</strong> : ' . htmlspecialchars($company) . '<br>'
        . '<strong>Créneau</strong> : ' . htmlspecialchars($dateFr . ' à ' . $time) . ' (heure de Zurich)</p>'
        . ($message !== '' ? '<p style="margin:14px 0 6px;background:#F4F0E9;border-radius:10px;padding:12px 14px;"><strong>Message</strong> : ' . nl2br(htmlspecialchars($message)) . '</p>' : '');
    $tBtns = [['label' => 'Ajouter au calendrier', 'url' => $icsUrl, 'primary' => true]];
    $tHtml = finalyn_email_html('Nouvelle réservation', $tPar, $tBtns);
    finalyn_send_mail($team, 'Nouvelle reservation : ' . $firstname . ' ' . $lastname . ' (' . $company . ')', $tBody, $from, $email, $ics, $tHtml);
}

// 2) Confirmation au client (Reply-To = equipe finalyn) - HTML + texte
$cBody = "Bonjour " . $firstname . ",\n\n"
    . "Votre rendez-vous avec finalyn.ia est bien confirmé.\n\n"
    . "Date : " . $dateFr . " à " . $time . " (heure de Zurich)\n"
    . "Format : visioconférence, environ 30 minutes\n\n"
    . "Pour l'ajouter à votre agenda, cliquez sur le lien ci-dessous : votre application (Apple, Outlook, Google...) vous proposera de l'enregistrer dans le calendrier de votre choix.\n"
    . "Ajouter au calendrier : " . $icsUrl;
$cBody .= "\n\nNous vous enverrons le lien de connexion peu avant le rendez-vous.\n"
    . "Besoin d'annuler ou de décaler ? C'est ici, en un clic : " . $cancelUrl . "\n\n"
    . "À très bientôt,\n"
    . "L'équipe finalyn.ia\n"
    . "contact@finalyn.com · +41 79 639 36 84";

$cPar = '<p style="margin:0 0 14px;">Bonjour ' . htmlspecialchars($firstname) . ',</p>'
    . '<p style="margin:0 0 14px;">Votre <strong>rendez-vous</strong> avec finalyn.ia est bien confirmé.</p>'
    . '<p style="margin:0 0 14px;background:#F4F0E9;border-radius:10px;padding:14px 16px;">'
    . '<strong>Date</strong> : ' . htmlspecialchars($dateFr . ' à ' . $time) . ' (heure de Zurich)<br>'
    . '<strong>Format</strong> : visioconférence, environ 30 minutes</p>'
    . '<p style="margin:0 0 6px;">Cliquez sur <strong>Ajouter au calendrier</strong> : votre application (Apple, Outlook, Google...) vous proposera de l\'enregistrer dans l\'agenda de votre choix. Nous vous enverrons le lien de connexion peu avant le rendez-vous.</p>';
$cBtns = [
    ['label' => 'Ajouter au calendrier', 'url' => $icsUrl, 'primary' => true],
    ['label' => 'Annuler ou décaler', 'url' => $cancelUrl, 'primary' => false],
];
$cHtml = finalyn_email_html('Votre rendez-vous est confirmé', $cPar, $cBtns);
finalyn_send_mail($email, 'Votre rendez-vous est confirmé · finalyn.ia', $cBody, $from, $organizer, $ics, $cHtml);

echo json_encode(['ok' => true, 'date' => $date, 'time' => $time, 'cancel' => $cancelUrl], JSON_UNESCAPED_UNICODE);
