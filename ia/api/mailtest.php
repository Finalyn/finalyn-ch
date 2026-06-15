<?php
/**
 * Diagnostic e-mail finalyn.ia. A lancer en SSH UNIQUEMENT :
 *   php api/mailtest.php votre@email.com
 * Affiche la config (sans le mot de passe) + le dialogue SMTP + le resultat.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Accessible uniquement en ligne de commande (SSH).\n");
}
define('FINALYN_MAIL_DEBUG', true);
require __DIR__ . '/config_load.php';
require __DIR__ . '/mail.php';

$to = $argv[1] ?? '';
if ($to === '') {
    fwrite(STDERR, "Usage : php api/mailtest.php votre@email.com\n");
    exit(1);
}

$cfg = finalyn_config();
echo "=== Config e-mail ===\n";
$host = $cfg['smtp_host'] ?? '';
echo 'smtp_host   : ' . ($host !== '' ? $host : '(VIDE -> mail() natif, peu fiable sur Infomaniak)') . "\n";
echo 'smtp_port   : ' . ($cfg['smtp_port'] ?? '') . "\n";
echo 'smtp_user   : ' . ($cfg['smtp_user'] ?? '') . "\n";
echo 'smtp_secure : ' . ($cfg['smtp_secure'] ?? '') . "\n";
echo 'from_email  : ' . ($cfg['from_email'] ?? '') . "\n";
echo 'notify_email: ' . ($cfg['notify_email'] ?? '') . "\n";
echo "\n=== Envoi du test vers $to ===\n";

$from = $cfg['from_email'] ?? ($cfg['smtp_user'] ?? 'noreply@finalyn.ch');
$ok = finalyn_send_mail($to, 'Test finalyn.ia', "Ceci est un e-mail de test envoye depuis finalyn.ch/ia.\n", $from, $from, null);

echo "\n=== Resultat : " . ($ok ? 'ENVOYE (verifie ta boite + le dossier spam)' : 'ECHEC (voir le dialogue ci-dessus)') . " ===\n";
