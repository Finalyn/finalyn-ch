<?php
/** Annulation d'une reservation par le client, via lien signe (id + jeton) recu par e-mail. */
require __DIR__ . '/config_load.php';
require __DIR__ . '/db.php';
require __DIR__ . '/mail.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$t  = isset($_GET['t']) ? preg_replace('/[^a-f0-9]/', '', (string)$_GET['t']) : '';

$state = 'invalid'; // invalid | already | ok | past
$row   = null;

if ($id > 0 && $t !== '') {
    $pdo = finalyn_db();
    $q = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $q->execute([$id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['token']) && hash_equals((string)$row['token'], $t)) {
        if ($row['status'] === 'cancelled') {
            $state = 'already';
        } elseif (strtotime(($row['slot_date'] ?? '') . ' ' . ($row['slot_time'] ?? '')) < time()) {
            $state = 'past'; // rendez-vous deja passe : on n'annule pas
        } else {
            $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")->execute([$id]);
            $state = 'ok';
            // E-mail au client (on espere le revoir)
            finalyn_booking_notify('client_cancel', $row);
            // Notification a l'equipe
            $cfg = finalyn_config();
            $team = $cfg['notify_email'] ?? '';
            $from = $cfg['from_email'] ?? 'noreply@finalyn.com';
            if ($team !== '') {
                $months = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
                $d = strtotime($row['slot_date']);
                $dateFr = (int)date('j', $d) . ' ' . $months[(int)date('n', $d)] . ' ' . date('Y', $d);
                finalyn_send_mail($team, 'Annulation client : ' . $row['firstname'] . ' ' . $row['lastname'],
                    "Le client a annulé son rendez-vous via le lien.\n\n"
                    . 'Nom        : ' . $row['firstname'] . ' ' . $row['lastname'] . "\n"
                    . 'E-mail     : ' . $row['email'] . "\n"
                    . 'Creneau    : ' . $dateFr . ' à ' . $row['slot_time'] . "\n",
                    $from, $row['email']);
            }
        }
    }
}

$titles = [
    'ok'      => 'Rendez-vous annulé',
    'already' => 'Rendez-vous déjà annulé',
    'past'    => 'Ce rendez-vous est passé',
    'invalid' => 'Lien invalide',
];
$messages = [
    'ok'      => "C'est noté, votre rendez-vous est annulé. On espère vous revoir très vite, reprenez le créneau qui vous arrange quand vous voulez.",
    'already' => 'Ce rendez-vous avait déjà été annulé. Vous pouvez en reprendre un nouveau à tout moment.',
    'past'    => "Ce rendez-vous est déjà passé, il n'y a rien à annuler. Pour en planifier un nouveau, c'est par ici.",
    'invalid' => "Ce lien d'annulation n'est pas valide ou a expiré. Pour toute question, écrivez-nous à contact@finalyn.com.",
];
$title = $titles[$state];
$msg   = $messages[$state];
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="fr-CH">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title><?= htmlspecialchars($title) ?> · finalyn.ia</title>
<meta name="robots" content="noindex" />
<link rel="icon" type="image/png" href="/favicon.png" />
<link rel="icon" type="image/svg+xml" href="/favicon.svg" />
<style>
  :root { --warm-white:#FAF8F4; --ink:#0E0E0E; --muted:#6b6b6b; --line:#DCD6CB; --purple:#7C4DEF; }
  * { box-sizing: border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:1.5rem;
         background:var(--warm-white); color:var(--ink); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,sans-serif; }
  .card { background:#fff; border:1px solid var(--line); border-radius:18px; max-width:460px; width:100%;
          padding:2.4rem 2rem; text-align:center; box-shadow:0 20px 50px -25px rgba(40,30,60,0.25); }
  .brand { font-size:1.4rem; font-weight:600; margin-bottom:1.4rem; }
  .brand .ia { color:var(--purple); }
  h1 { font-size:1.4rem; margin:0 0 0.7rem; letter-spacing:-0.01em; }
  p { color:var(--muted); line-height:1.6; margin:0 0 1.6rem; }
  .btn { display:inline-block; background:var(--ink); color:#fff; text-decoration:none; font-weight:500;
         padding:0.8rem 1.5rem; border-radius:999px; transition:background .2s; }
  .btn:hover { background:var(--purple); }
</style>
</head>
<body>
  <div class="card">
    <div class="brand">finalyn<span class="ia">.ia</span></div>
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($msg) ?></p>
    <a class="btn" href="https://finalyn.ch/ia/#audit">Reprendre un rendez-vous</a>
  </div>
</body>
</html>
