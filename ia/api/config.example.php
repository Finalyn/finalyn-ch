<?php
/**
 * Modele de configuration finalyn.ia (chat + back-office).
 *
 * A FAIRE AU DEPLOIEMENT :
 *   1. Copier ce fichier en  api/config.php  (meme dossier).
 *   2. Remplir les valeurs ci-dessous.
 *   3. NE JAMAIS committer api/config.php (il est dans .gitignore).
 */

return [
    // --- Clef API Anthropic (assistant chat) ---
    // Commence par "sk-ant-...". Alternative : variable d'env ANTHROPIC_API_KEY.
    'api_key' => 'COLLEZ_VOTRE_CLEF_API_ICI',

    // --- E-mail qui recoit les nouvelles reservations d'audit ---
    'notify_email' => 'contact@finalyn.com',

    // --- Adresse expeditrice des e-mails (doit appartenir a votre domaine) ---
    // Idealement autorisee par votre SPF/DKIM pour ne pas tomber en spam.
    'from_email' => 'noreply@finalyn.com',

    // --- Acces au back-office (admin/) ---
    // Le plus simple : mettre un mot de passe en clair ci-dessous.
    'admin_password' => 'CHOISISSEZ_UN_MOT_DE_PASSE',

    // Plus sur (optionnel) : laisser admin_password vide et mettre ici un hash
    // genere avec :  php -r "echo password_hash('votre-mdp', PASSWORD_DEFAULT);"
    'admin_password_hash' => '',

    // --- SMTP authentifie (optionnel mais recommande pour la livraison des e-mails) ---
    // Si rempli, les e-mails partent en SMTP authentifie (bien meilleure delivrabilite).
    // Si vide, on utilise la fonction mail() native du serveur.
    // Infomaniak : creez une adresse (ex. noreply@finalyn.ch), puis :
    //   smtp_host = 'mail.infomaniak.com', smtp_port = 465, smtp_secure = 'ssl'
    //   smtp_user = l'adresse complete, smtp_pass = son mot de passe
    // Important : mettez 'from_email' egal a 'smtp_user' (l'expediteur doit etre la boite authentifiee).
    'smtp_host'   => '',
    'smtp_port'   => 465,
    'smtp_user'   => '',
    'smtp_pass'   => '',
    'smtp_secure' => 'ssl', // 'ssl' (port 465) ou 'tls' (port 587)
];
