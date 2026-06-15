<?php
/** Bootstrap commun a toutes les pages du back-office. */
require_once __DIR__ . '/auth.php';
finalyn_require_login();
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../api/settings.php';

$pdo = finalyn_db();
$csrf = finalyn_csrf();

function admin_flash_set($msg, $err = false) { $_SESSION['flash'] = ['msg' => $msg, 'err' => $err]; }
function admin_redirect($to) { header('Location: ' . $to); exit; }
function scalar($pdo, $sql, $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); }
function fr_dt($s) { $t = strtotime($s . ' UTC'); return $t ? date('d.m.Y H:i', $t) : h($s); }
function fr_d($s)  { $t = strtotime($s); return $t ? date('d.m.Y', $t) : h($s); }
function flash_render() {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash']; unset($_SESSION['flash']);
        echo '<div class="adm-flash' . (!empty($f['err']) ? ' err' : '') . '">' . h($f['msg']) . '</div>';
    }
}
