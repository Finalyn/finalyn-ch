<?php
/** Chargement de la configuration (api/config.php) + repli variables d'env. */
function finalyn_config() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [];
    if (is_file(__DIR__ . '/config.php')) {
        $c = require __DIR__ . '/config.php';
        if (is_array($c)) $cfg = $c;
    }
    if (empty($cfg['api_key'])) {
        $k = getenv('ANTHROPIC_API_KEY');
        if ($k) $cfg['api_key'] = $k;
    }
    return $cfg;
}
