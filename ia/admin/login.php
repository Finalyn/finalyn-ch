<?php
require __DIR__ . '/auth.php';

if (finalyn_is_logged()) { header('Location: index.php'); exit; }

$error = '';
$now = time();

// Throttle simple anti-force-brute
$fails = $_SESSION['login_fails'] ?? ['n' => 0, 'until' => 0];
$locked = $fails['until'] > $now;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($locked) {
        $error = 'Trop de tentatives. Reessayez dans une minute.';
    } elseif (!finalyn_password_configured()) {
        $error = "Aucun mot de passe n'est defini dans api/config.php (champ admin_password).";
    } elseif (finalyn_password_ok($_POST['password'] ?? '')) {
        session_regenerate_id(true);
        $_SESSION['finalyn_admin'] = true;
        unset($_SESSION['login_fails']);
        header('Location: index.php');
        exit;
    } else {
        usleep(600000); // ralentit les tentatives
        $fails['n'] = (int)$fails['n'] + 1;
        if ($fails['n'] >= 5) { $fails = ['n' => 0, 'until' => $now + 60]; }
        $_SESSION['login_fails'] = $fails;
        $error = 'Mot de passe incorrect.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr-CH">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex, nofollow" />
<title>Connexion · Back-office finalyn.ia</title>
<link rel="stylesheet" href="style.css" />
</head>
<body class="login-body">
  <form class="login-card" method="post" autocomplete="off">
    <div class="login-brand">finalyn<span class="ia">.ia</span></div>
    <p class="login-sub">Back-office</p>
    <?php if ($error): ?><div class="login-error"><?= h($error) ?></div><?php endif; ?>
    <label class="login-label" for="password">Mot de passe</label>
    <input class="login-input" type="password" name="password" id="password" required autofocus />
    <button class="login-btn" type="submit">Se connecter</button>
  </form>
</body>
</html>
