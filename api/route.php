<?php
/**
 * Agent d'orientation du hub finalyn.ch (Claude Haiku).
 *
 * Le visiteur decrit son besoin en langage libre (fautes comprises) ; cet agent
 * comprend, repond brievement et indique le bon pole. Il renvoie aussi une
 * reformulation du besoin ("prefill") pour pre-remplir le formulaire du pole.
 *
 * La clef API n'est JAMAIS dans le navigateur : ce proxy tourne cote serveur et
 * REUTILISE la clef du pole IA (ia/api/config.php). Aucun secret en plus.
 *
 * Deploiement : le dossier /api doit etre servi par PHP (Infomaniak : actif).
 * Le hub appelle POST /api/route.php avec { "messages": [ {role, content}, ... ] }.
 *
 * Pas de tarif, pas de tiret cadratin (regles editoriales Finalyn).
 */

// ----- Reglages (Haiku = peu cher ; reponses courtes pour limiter le cout) -----
const HUB_MODEL       = 'claude-haiku-4-5';
const HUB_MAX_TOKENS  = 260;
const HUB_MAX_HISTORY = 10;
const HUB_MAX_CHARS   = 600;
const HUB_RATE_MAX    = 30;   // requetes max par IP
const HUB_RATE_WINDOW = 600;  // sur 10 minutes
const HUB_TIMEOUT     = 20;

$ALLOWED_HOST_SUFFIXES = ['finalyn.com', 'finalyn.ch', 'localhost', '127.0.0.1'];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function hub_fail($status, $message) {
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hub_fail(405, "Methode non autorisee.");
}

// ----- Controle d'origine -----
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $host = parse_url($origin, PHP_URL_HOST) ?: '';
    $ok = false;
    foreach ($ALLOWED_HOST_SUFFIXES as $s) {
        if ($host === $s || str_ends_with($host, '.' . $s)) { $ok = true; break; }
    }
    if (!$ok) hub_fail(403, "Origine non autorisee.");
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

// ----- Clef API : env, sinon config racine, sinon config du pole IA (reuse) -----
$apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
if ($apiKey === '') {
    foreach ([__DIR__ . '/config.php', __DIR__ . '/../ia/api/config.php'] as $cf) {
        if (is_file($cf)) {
            $cfg = require $cf;
            if (is_array($cfg) && !empty($cfg['api_key'])) { $apiKey = $cfg['api_key']; break; }
        }
    }
}
if ($apiKey === '') hub_fail(500, "Service momentanement indisponible.");

// ----- Limitation de debit par IP (fichier, best effort) -----
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$cacheDir = __DIR__ . '/.cache';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0700, true); }
if (is_dir($cacheDir) && is_writable($cacheDir)) {
    $bf = $cacheDir . '/rl_' . hash('sha256', $ip) . '.json';
    $now = time();
    $b = ['count' => 0, 'reset' => $now + HUB_RATE_WINDOW];
    if (is_file($bf)) {
        $raw = json_decode((string)@file_get_contents($bf), true);
        if (is_array($raw) && isset($raw['reset']) && $raw['reset'] > $now) $b = $raw;
    }
    $b['count'] = (int)$b['count'] + 1;
    @file_put_contents($bf, json_encode($b), LOCK_EX);
    if ($b['count'] > HUB_RATE_MAX) {
        hub_fail(429, "Un instant, beaucoup de messages d'affilee. Reessayez dans quelques minutes, ou ecrivez a contact@finalyn.com.");
    }
}

// ----- Corps de la requete -----
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['messages']) || !is_array($body['messages'])) {
    hub_fail(400, "Requete invalide.");
}

// ----- Nettoyage / validation des messages -----
$clean = [];
foreach ($body['messages'] as $m) {
    if (!is_array($m) || !isset($m['role'], $m['content'])) continue;
    $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
    $content = is_string($m['content']) ? trim($m['content']) : '';
    if ($content === '') continue;
    if (mb_strlen($content) > HUB_MAX_CHARS) $content = mb_substr($content, 0, HUB_MAX_CHARS);
    $clean[] = ['role' => $role, 'content' => $content];
}
$clean = array_slice($clean, -HUB_MAX_HISTORY);
while (count($clean) > 0 && $clean[0]['role'] !== 'user') array_shift($clean);
if (count($clean) === 0) hub_fail(400, "Aucun message valide.");

// ----- Prompt systeme -----
$system = <<<'PROMPT'
Tu es l'assistant d'orientation de Finalyn, un studio suisse. Le visiteur arrive sur la page d'accueil (finalyn.ch) et decrit son besoin en langage libre. Ton seul role : comprendre ce besoin (meme avec des fautes de frappe ou des termes approximatifs) et l'orienter vers le bon pole, avec chaleur et concision.

LES POLES :
- finalyn.ia : intelligence artificielle et automatisation. Agents IA, assistants, chatbots, automatisation de taches, workflows, n8n, Make, Zapier, integrations entre outils, scraping, solutions IA sur mesure. Cible = "ia".
- finalyn.web : sites web et marketing. Site vitrine, e-commerce, application web, refonte de site, SEO et referencement, publicite en ligne, reseaux sociaux, branding, logo, identite visuelle. Ce pole ouvre bientot. Cible = "web".
- JMM Studio : jeux video et experiences ludiques (Roblox, FiveM, gamification, mini-jeux). Cible = "jmm".
- Prise de rendez-vous : la personne veut surtout echanger, un devis, un audit, parler a quelqu'un, sans pole precis. Cible = "rdv".

REGLES :
1. Reponds en francais, vouvoiement, ton chaleureux et naturel. TRES court : 1 a 2 phrases, pas de listes, pas de titres, pas d'emoji.
2. Ne donne JAMAIS de prix ni d'estimation chiffree. N'emploie JAMAIS le tiret cadratin (le caractere long) : prends une virgule, des parentheses ou deux points.
3. N'invente rien. Si le besoin n'est pas clair, pose UNE courte question pour preciser, et mets la cible "none".
4. Si le besoin couvre plusieurs poles, choisis le principal et cite l'autre en une demi-phrase.
5. Quand tu identifies le pole, dis simplement vers qui tu orientes (ex : "Pour ca, c'est notre pole IA, finalyn.ia.").

FORMAT OBLIGATOIRE :
Termine TOUJOURS ta reponse par une derniere ligne seule, exactement au format :
[GO cible | besoin]
- cible vaut : ia, web, jmm, rdv ou none.
- besoin : une reformulation courte et claire du besoin, ecrite a la premiere personne du visiteur (ex : "Je veux un agent IA qui trie mes e-mails"). Elle sert a pre-remplir le formulaire du pole. Si cible vaut none, laisse-la vide : [GO none | ].
Ne fais apparaitre ce marqueur QUE sur la toute derniere ligne, jamais ailleurs dans le texte.
PROMPT;

// ----- Appel a l'API Claude -----
$payload = json_encode([
    'model'      => HUB_MODEL,
    'max_tokens' => HUB_MAX_TOKENS,
    'system'     => $system,
    'messages'   => $clean,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => HUB_TIMEOUT,
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
    error_log('hub route curl: ' . $curlErr);
    hub_fail(502, "Service indisponible un instant. Reessayez, ou ecrivez a contact@finalyn.com.");
}
$data = json_decode($response, true);
if ($httpCode !== 200 || !is_array($data)) {
    error_log('hub route API ' . $httpCode . ' : ' . $response);
    hub_fail(502, "Je n'arrive pas a repondre la, reessayez dans un instant.");
}

// ----- Extraction du texte -----
$reply = '';
if (isset($data['content']) && is_array($data['content'])) {
    foreach ($data['content'] as $blk) {
        if (isset($blk['type'], $blk['text']) && $blk['type'] === 'text') $reply .= $blk['text'];
    }
}
$reply = trim($reply);
if ($reply === '') hub_fail(502, "Pas de reponse pour le moment, reessayez.");

// ----- Parse du marqueur [GO cible | besoin] -----
$target = 'none';
$prefill = '';
if (preg_match('/\[GO\s*([a-z]+)\s*\|?\s*([^\]]*)\]\s*$/iu', $reply, $mm)) {
    $t = strtolower(trim($mm[1]));
    if (in_array($t, ['ia', 'web', 'jmm', 'rdv', 'none'], true)) $target = $t;
    $prefill = trim($mm[2]);
    $reply = trim(preg_replace('/\[GO\s*[a-z]+\s*\|?[^\]]*\]\s*$/iu', '', $reply));
}
// Repli : si l'agent n'a pas fourni de besoin, on reprend le dernier message du visiteur.
if ($prefill === '') {
    for ($i = count($clean) - 1; $i >= 0; $i--) {
        if ($clean[$i]['role'] === 'user') { $prefill = $clean[$i]['content']; break; }
    }
}

$map = [
    'ia'   => ['label' => 'finalyn.ia',              'url' => 'ia/'],
    'web'  => ['label' => 'finalyn.web',             'url' => 'web/'],
    'jmm'  => ['label' => 'JMM Studio',              'url' => 'https://jmm.finalyn.ch'],
    'rdv'  => ['label' => 'la prise de rendez-vous', 'url' => 'ia/'],
    'none' => ['label' => '',                        'url' => ''],
];
$info = $map[$target];

echo json_encode([
    'reply'   => $reply,
    'target'  => $target,
    'label'   => $info['label'],
    'url'     => $info['url'],
    'prefill' => mb_substr($prefill, 0, 300),
], JSON_UNESCAPED_UNICODE);
