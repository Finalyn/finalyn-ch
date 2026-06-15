<?php
/**
 * Proxy serveur pour l'assistant finalyn.ia (Claude Haiku).
 *
 * Pourquoi un proxy : la clef API Anthropic ne doit JAMAIS se trouver dans le
 * JavaScript du site (elle serait volable par n'importe qui). Ce fichier tourne
 * cote serveur (PHP, ex. Infomaniak), detient la clef, applique les garde-fous,
 * puis appelle l'API Claude. Le navigateur ne parle qu'a ce proxy.
 *
 * Deploiement :
 *   1. Copier api/config.example.php en api/config.php et y coller la clef.
 *      (ou definir la variable d'environnement ANTHROPIC_API_KEY sur l'hebergeur)
 *   2. Verifier que le dossier api/ est servi par PHP (Infomaniak : actif par defaut).
 *   3. Le site appelle POST /api/chat.php avec { "messages": [ {role, content}, ... ] }.
 *
 * Aucune dependance (curl natif). Pas de tarif, pas de tiret cadratin (regles editoriales).
 */

// ----- Reglages -----
const FINALYN_MODEL        = 'claude-haiku-4-5'; // Claude Haiku 4.5
const FINALYN_MAX_TOKENS   = 600;                // longueur max d'une reponse
const FINALYN_MAX_HISTORY  = 12;                 // nb de messages conserves
const FINALYN_MAX_CHARS    = 1000;               // longueur max d'un message utilisateur
const FINALYN_RATE_MAX     = 40;                 // requetes max par IP (chat + agent besoin partagent ce quota)
const FINALYN_RATE_WINDOW  = 600;                // sur cette fenetre (secondes)
const FINALYN_TIMEOUT      = 30;                  // timeout appel API (secondes)

// Origines autorisees (anti-abus depuis d'autres sites)
$ALLOWED_HOST_SUFFIXES = ['finalyn.com', 'finalyn.ch', 'localhost', '127.0.0.1'];

// ----- Reponse JSON utilitaire -----
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function finalyn_fail($status, $message) {
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ----- Methode -----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    finalyn_fail(405, "Methode non autorisee.");
}

// ----- Controle d'origine (le meme-origine n'envoie pas toujours Origin) -----
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $host = parse_url($origin, PHP_URL_HOST) ?: '';
    $ok = false;
    foreach ($ALLOWED_HOST_SUFFIXES as $suffix) {
        if ($host === $suffix || str_ends_with($host, '.' . $suffix)) { $ok = true; break; }
    }
    if (!$ok) {
        finalyn_fail(403, "Origine non autorisee.");
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

// ----- Clef API -----
$apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
if ($apiKey === '' && is_file(__DIR__ . '/config.php')) {
    $cfg = require __DIR__ . '/config.php';
    if (is_array($cfg) && !empty($cfg['api_key'])) {
        $apiKey = $cfg['api_key'];
    }
}
if ($apiKey === '') {
    finalyn_fail(500, "Service momentanement indisponible.");
}

// ----- Limitation de debit par IP (fichier, best effort) -----
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$cacheDir = __DIR__ . '/.cache';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0700, true); }
if (is_dir($cacheDir) && is_writable($cacheDir)) {
    $bucketFile = $cacheDir . '/rl_' . hash('sha256', $ip) . '.json';
    $now = time();
    $bucket = ['count' => 0, 'reset' => $now + FINALYN_RATE_WINDOW];
    if (is_file($bucketFile)) {
        $raw = json_decode((string)@file_get_contents($bucketFile), true);
        if (is_array($raw) && isset($raw['reset']) && $raw['reset'] > $now) {
            $bucket = $raw;
        }
    }
    $bucket['count'] = (int)$bucket['count'] + 1;
    @file_put_contents($bucketFile, json_encode($bucket), LOCK_EX);
    if ($bucket['count'] > FINALYN_RATE_MAX) {
        finalyn_fail(429, "Vous avez envoye beaucoup de messages. Merci de patienter quelques minutes, ou reservez directement l'audit gratuit.");
    }
}

// ----- Corps de la requete -----
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['messages']) || !is_array($body['messages'])) {
    finalyn_fail(400, "Requete invalide.");
}

// ----- Nettoyage / validation des messages -----
$clean = [];
foreach ($body['messages'] as $m) {
    if (!is_array($m) || !isset($m['role'], $m['content'])) continue;
    $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
    $content = is_string($m['content']) ? trim($m['content']) : '';
    if ($content === '') continue;
    if (mb_strlen($content) > FINALYN_MAX_CHARS) {
        $content = mb_substr($content, 0, FINALYN_MAX_CHARS);
    }
    $clean[] = ['role' => $role, 'content' => $content];
}
$clean = array_slice($clean, -FINALYN_MAX_HISTORY);
if (count($clean) === 0 || $clean[0]['role'] !== 'user') {
    // Le premier message doit etre un message utilisateur.
    while (count($clean) > 0 && $clean[0]['role'] !== 'user') {
        array_shift($clean);
    }
}
if (count($clean) === 0) {
    finalyn_fail(400, "Aucun message valide.");
}

// ----- Prompt systeme (identite, faits, garde-fous) -----
$system = <<<'PROMPT'
Tu es l'assistant conversationnel de finalyn.ia, une agence d'intelligence artificielle et d'automatisation basee a Rolle (canton de Vaud), en Suisse. Tu reponds sur le site finalyn.ch/ia.

Ta mission : aider les visiteurs (surtout des PME et independants suisses) a comprendre ce que finalyn.ia peut faire pour eux, repondre a leurs questions sur les services et les integrations, et les inviter a reserver l'audit gratuit quand c'est pertinent.

CE QUE PROPOSE FINALYN.IA :
- Agents IA : assistants qui qualifient, repondent, traitent des demandes 24/7.
- Automatisation : workflows qui font le travail repetitif a la place des equipes.
- Integrations : connexion de l'IA aux outils deja utilises (Bexio, Outlook, Gmail, Notion, Microsoft Teams, Slack, WhatsApp, LinkedIn, n8n, Make, et la plupart des outils via API ou webhook).
- Formation : accompagner les equipes a utiliser l'IA et Claude au quotidien.
- Personnalisation : solutions sur mesure adaptees au metier du client.
- Audit gratuit : un premier echange sans engagement pour identifier les automatisations a fort impact.

QUELQUES REALISATIONS (a citer si on demande des exemples) :
- Diagly : la brique IA (vision par ordinateur) d'une app pour les pros du batiment, qui analyse des photos de materiaux pour en evaluer l'etat. finalyn.ia s'est occupe uniquement de la partie IA.
- Extension LinkedIn : prospection assistee qui qualifie les profils et suggere des messages, en respectant les limites de LinkedIn.
- Tri d'e-mails + Notion : un agent qui trie la boite chaque matin, cree des taches et alimente Notion et le calendrier.
- Agents IA marketing : une equipe de 9 agents specialises qui epaulent et guident une equipe marketing.
- Agent conversationnel : le chatbot du site (c'est toi).

INFOS PRATIQUES :
- Base a Rolle (Vaud), Suisse. Contact : contact@finalyn.com, +41 79 639 36 84.
- Site et donnees heberges en Suisse (Infomaniak), demarche conforme a la nLPD (Suisse) et au RGPD (UE).

REGLES ABSOLUES (ne jamais enfreindre) :
1. Ne donne JAMAIS de prix, de tarif, de fourchette ni d'estimation chiffree. Si on te demande le prix, explique simplement que chaque projet est chiffre sur mesure, puis propose de prendre un rendez-vous gratuit (30 min en visio). Quand tu invites a passer a l'action, parle de "prendre un rendez-vous" (gratuit, 30 min en visio), pas du mot "audit". Ce rendez-vous est bien gratuit et sans engagement.
2. N'emploie JAMAIS le tiret cadratin (le caractere long). Utilise une virgule, deux points, des parentheses ou le mot juste a la place.
3. Reponds en francais, ton chaleureux, clair et professionnel, en vouvoyant. Sois TRES concis : 1 a 3 phrases courtes, pas de longs paragraphes, pas de titres markdown. Le visiteur doit pouvoir te lire en un coup d'oeil.
4. Reste sur le sujet de finalyn.ia, de l'IA et de l'automatisation pour les entreprises, et de la prise de rendez-vous. Si la question est hors sujet, recentre poliment vers ta mission.
5. N'invente rien : pas de faux clients, pas de fausses garanties, pas de promesses de resultats chiffres. Si tu ne sais pas, propose l'audit gratuit ou le contact.
6. Tu es un assistant IA, ne pretends pas etre humain. Ne donne pas de conseils juridiques, medicaux ou financiers engageants.
7. Ne demande pas de donnees sensibles. Pour un echange detaille, oriente vers l'audit gratuit ou contact@finalyn.com.

CERNER LE BESOIN (important) :
Ton role n'est pas seulement de repondre, c'est aussi de comprendre le besoin du visiteur pour mieux l'aider et l'orienter. De maniere naturelle et non insistante, cherche a savoir :
- son secteur ou son activite ;
- les taches repetitives ou chronophages qui lui font perdre du temps ;
- les outils qu'il utilise deja au quotidien ;
- ce qu'il aimerait gagner ou automatiser.
Pose UNE question a la fois (jamais un interrogatoire), rebondis sur ses reponses, et quand tu as cerne un besoin concret, montre comment finalyn.ia pourrait aider puis propose de prendre rendez-vous (gratuit, 30 min) comme prochaine etape.

PROPOSER DES CHOIX (a CHAQUE reponse) :
Tes reponses sont courtes : aide TOUJOURS le visiteur a avancer en terminant CHAQUE reponse par une DERNIERE ligne, seule, au format EXACT :
[OPTIONS] Choix court 1 | Choix court 2 | Choix court 3
Regles pour les options :
- 2 a 4 options a CHAQUE reponse, chacune tres courte (1 a 5 mots), formulee du point de vue du visiteur.
- Sers-toi en pour orienter (quel service, quel outil, quel secteur), approfondir, ou avancer (par exemple "Prendre rendez-vous").
- [OPTIONS] uniquement sur la toute derniere ligne. Seule exception : si tu emets [BOOK] (voir plus bas), ne mets pas de [OPTIONS].

Quand c'est naturel, termine en posant UNE question pour mieux cerner le besoin (avec des options), ou en proposant de prendre rendez-vous.
PROMPT;

// Consignes dynamiques : date du jour + prise de rendez-vous + liens cliquables
try {
    $tz = new DateTimeZone('Europe/Zurich');
    $nowDt = new DateTime('now', $tz);
    $joursFr = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $moisFr = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $todayFr = $joursFr[(int)$nowDt->format('w')] . ' ' . (int)$nowDt->format('j') . ' ' . $moisFr[(int)$nowDt->format('n')] . ' ' . $nowDt->format('Y');
    $todayIso = $nowDt->format('Y-m-d');
    $daysList = '';
    for ($i = 0; $i < 14; $i++) {
        $d = (clone $nowDt)->modify('+' . $i . ' day');
        $w = (int)$d->format('w');
        $tag = $i === 0 ? " (aujourd'hui)" : ($i === 1 ? ' (demain)' : '');
        $weekend = ($w === 0 || $w === 6) ? ' [week-end, pas de RDV]' : '';
        $daysList .= '- ' . $joursFr[$w] . ' ' . (int)$d->format('j') . ' ' . $moisFr[(int)$d->format('n')] . $tag . ' = ' . $d->format('Y-m-d') . $weekend . "\n";
    }
} catch (Throwable $e) { $todayFr = ''; $todayIso = ''; $daysList = ''; }

$system .= "\n\nLIENS ET CONTACT :\n"
    . "- Pour renvoyer vers une partie du site, utilise un lien markdown : [nos services](#services), [cas d'usage](#cas-usage), [reservez](#audit), [le blog](/blog/).\n"
    . "- Pour le contact, ecris l'e-mail contact@finalyn.com et le numero +41 79 639 36 84 tels quels : ils deviennent automatiquement cliquables (le numero ouvre WhatsApp).\n\n"
    . "PRISE DE RENDEZ-VOUS (marqueur [BOOK]) :\n"
    . "Nous sommes le " . $todayFr . ", heure de Zurich. Les rendez-vous se prennent du lundi au vendredi, au moins une demi-journee (12 h) a l'avance.\n"
    . "IMPORTANT : ne calcule JAMAIS une date toi-meme (tu te trompes souvent). Recopie EXACTEMENT la date ISO (AAAA-MM-JJ) depuis cette liste des 14 prochains jours :\n"
    . $daysList
    . "Ainsi 'lundi prochain' = le prochain lundi de la liste, 'demain' = la ligne marquee (demain). Verifie le nom du jour ET la date dans la liste, et quand tu confirmes, ecris-les tels qu'ils y figurent.\n"
    . "Quand le visiteur veut concretement prendre rendez-vous :\n"
    . "- sans date precise : termine ton message par une derniere ligne contenant uniquement [BOOK]\n"
    . "- avec une date (et eventuellement une heure) : termine par [BOOK AAAA-MM-JJ] ou [BOOK AAAA-MM-JJ HH:MM] en recopiant l'ISO depuis la liste\n"
    . "Ce marqueur ouvre le calendrier de reservation (pre-rempli si tu donnes la date). N'emets [BOOK] que lorsque le visiteur veut vraiment reserver, et toujours seul sur la derniere ligne. Tu ne connais pas les creneaux deja pris : si l'horaire n'est pas libre, le calendrier proposera automatiquement les autres horaires du jour.\n"
    . "Tu peux aussi, plutot que d'ouvrir le calendrier tout de suite, demander d'abord quel jour et quelle heure arrangeraient le visiteur, avec des [OPTIONS] (ex: Cette semaine | La semaine prochaine | Plutot le matin | Plutot l'apres-midi), puis emettre [BOOK AAAA-MM-JJ HH:MM] une fois la date connue.";

// Ressources du site : l'agent renvoie vers le bon contenu (lien markdown)
$system .= "\n\nRESSOURCES DU SITE (quand un sujet est couvert, ajoute le lien markdown correspondant a ta reponse, sans en mettre plusieurs inutilement) :\n"
    . "Articles de blog :\n"
    . "- API, webhook, integration technique : [API et webhook](/blog/api-webhook-difference-pme.html)\n"
    . "- Qu'est-ce qu'un agent IA : [Definition d'un agent IA](/blog/quest-ce-quun-agent-ia-definition-decideurs.html)\n"
    . "- Chatbot vs agent IA : [Chatbot ou agent IA](/blog/chatbot-ou-agent-ia-difference-pme.html)\n"
    . "- Choisir un modele (GPT, Claude, Gemini, Mistral) : [Comparatif des modeles IA](/blog/comparatif-modeles-ia-2026-pme.html)\n"
    . "- Mistral en local, confidentialite, nLPD : [Mistral en local](/blog/mistral-local-pme-suisse-rgpd.html)\n"
    . "- Securite, donnees, hebergement : [Securite et hebergement IA](/blog/securite-donnees-hebergement-ia-pme-suisse.html)\n"
    . "- ROI, rentabilite : [Calcul du ROI d'un agent IA](/blog/calcul-roi-agent-ia-pme.html)\n"
    . "- IA ou embauche : [IA ou embauche](/blog/ia-vs-embauche-comment-choisir-pme.html)\n"
    . "- Microsoft 365, Copilot : [IA et Microsoft 365](/blog/ia-microsoft-365-automatiser-pme.html)\n"
    . "- n8n, Make, automatisation : [n8n, Make ou sur-mesure](/blog/n8n-make-ou-sur-mesure-automatisation-pme.html)\n"
    . "- Automatiser les e-mails : [Automatiser ses e-mails](/blog/automatiser-emails-professionnels-pme-suisse.html)\n"
    . "- Integrer l'IA dans ses outils existants : [L'IA dans vos outils](/blog/ia-dans-vos-outils-existants-pme.html)\n"
    . "- Pourquoi / quand se lancer : [Pourquoi integrer l'IA](/blog/pourquoi-integrer-ia-pme-suisse.html)\n"
    . "- Choisir une agence IA : [Choisir une agence IA en Suisse romande](/blog/agence-ia-suisse-romande-comment-choisir.html)\n"
    . "Pages : [Agents IA](/services/agents-ia.html), [Automatisation](/services/automatisation.html), [Integrations](/services/integrations.html), [Audit](/services/audit.html), [Formation](/services/formation.html), [Sur mesure](/services/personnalisation.html), [tout le blog](/blog/)\n"
    . "Sections de l'accueil : [Services](#services), [Cas d'usage](#cas-usage), [Outils compatibles](#stack), [Realisations](#realisations), [Securite](#securite), [FAQ](#faq).";

// Rendez-vous existant de ce visiteur (transmis par le site depuis le navigateur)
if (isset($body['booking']) && is_array($body['booking'])) {
    $bk = $body['booking'];
    $bd = (isset($bk['date']) && is_string($bk['date'])) ? preg_replace('/[^0-9-]/', '', substr($bk['date'], 0, 10)) : '';
    $bt = (isset($bk['time']) && is_string($bk['time'])) ? preg_replace('/[^0-9:]/', '', substr($bk['time'], 0, 5)) : '';
    $bc = (isset($bk['cancel']) && is_string($bk['cancel']) && strpos($bk['cancel'], 'https://finalyn.ch/ia/api/cancel.php') === 0) ? $bk['cancel'] : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd)) {
        $system .= "\n\nRENDEZ-VOUS EXISTANT DE CE VISITEUR :\n"
            . "Ce visiteur a deja un rendez-vous prevu le " . $bd . ($bt !== '' ? (' a ' . $bt) : '') . ".\n"
            . "Ne lui propose donc PAS d'en reprendre un (n'emets pas [BOOK] de toi-meme). A la place : reponds a ses questions, et propose de preparer le rendez-vous, par exemple en lui demandant quelques precisions sur son besoin pour que l'echange soit plus utile.\n"
            . "Ne propose l'annulation ou le report QUE s'il le demande lui-meme. Dans ce cas, " . ($bc !== '' ? ("donne-lui ce lien : " . $bc) : "invite-le a utiliser le lien d'annulation recu par e-mail") . ".";
    }
}

// ----- Appel a l'API Claude -----
$payload = json_encode([
    'model'      => FINALYN_MODEL,
    'max_tokens' => FINALYN_MAX_TOKENS,
    'system'     => $system,
    'messages'   => $clean,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => FINALYN_TIMEOUT,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log('finalyn chat: curl error: ' . $curlErr);
    finalyn_fail(502, "Le service est momentanement indisponible. Reessayez dans un instant, ou reservez l'audit gratuit.");
}

$data = json_decode($response, true);

if ($httpCode !== 200 || !is_array($data)) {
    // On journalise le detail cote serveur, mais on ne le renvoie pas au client.
    error_log('finalyn chat: API ' . $httpCode . ' : ' . $response);
    finalyn_fail(502, "Je n'arrive pas a repondre pour l'instant. Vous pouvez reessayer, ou reserver l'audit gratuit.");
}

// Extraction du texte
$reply = '';
if (isset($data['content']) && is_array($data['content'])) {
    foreach ($data['content'] as $block) {
        if (isset($block['type'], $block['text']) && $block['type'] === 'text') {
            $reply .= $block['text'];
        }
    }
}
$reply = trim($reply);
if ($reply === '') {
    finalyn_fail(502, "Je n'ai pas de reponse pour le moment. Reservez l'audit gratuit et on en parle.");
}

// ----- Journalisation de la conversation (best effort, ne bloque jamais la reponse) -----
try {
    $convKey = (isset($body['conv']) && is_string($body['conv']))
        ? preg_replace('/[^a-zA-Z0-9_-]/', '', substr($body['conv'], 0, 64)) : '';
    if ($convKey !== '') {
        require_once __DIR__ . '/db.php';
        $pdo = finalyn_db();
        $now = gmdate('Y-m-d H:i:s');
        $sel = $pdo->prepare('SELECT id FROM conversations WHERE conv_key = ?');
        $sel->execute([$convKey]);
        $cid = $sel->fetchColumn();
        if ($cid) {
            $pdo->prepare('UPDATE conversations SET last_at = ? WHERE id = ?')->execute([$now, $cid]);
        } else {
            $pdo->prepare('INSERT INTO conversations (conv_key, started_at, last_at, ip_hash, user_agent, msg_count) VALUES (?,?,?,?,?,0)')
                ->execute([$convKey, $now, $now, finalyn_ip_hash(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)]);
            $cid = $pdo->lastInsertId();
        }
        // Dernier message utilisateur de cet echange
        $lastUser = '';
        for ($i = count($clean) - 1; $i >= 0; $i--) {
            if ($clean[$i]['role'] === 'user') { $lastUser = $clean[$i]['content']; break; }
        }
        $mins = $pdo->prepare('INSERT INTO messages (conversation_id, role, content, created_at) VALUES (?,?,?,?)');
        $added = 0;
        if ($lastUser !== '') { $mins->execute([$cid, 'user', $lastUser, $now]); $added++; }
        $mins->execute([$cid, 'assistant', $reply, $now]); $added++;
        $pdo->prepare('UPDATE conversations SET msg_count = msg_count + ? WHERE id = ?')->execute([$added, $cid]);
    }
} catch (Throwable $e) {
    error_log('finalyn chat log: ' . $e->getMessage());
}

echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);
