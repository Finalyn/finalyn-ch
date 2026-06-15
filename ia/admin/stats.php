<?php
require __DIR__ . '/boot.php';

$d30   = gmdate('Y-m-d H:i:s', strtotime('-29 days'));
$d7    = gmdate('Y-m-d H:i:s', strtotime('-6 days'));
$dprev = gmdate('Y-m-d H:i:s', strtotime('-13 days'));
$since = gmdate('Y-m-d', strtotime('-29 days')) . ' 00:00:00';

// ---- KPIs ----
$views30   = scalar($pdo, 'SELECT COUNT(*) FROM pageviews WHERE created_at >= ?', [$d30]);
$uniq30    = scalar($pdo, "SELECT COUNT(DISTINCT visitor_hash) FROM pageviews WHERE created_at >= ? AND visitor_hash != ''", [$d30]);
$conv30    = scalar($pdo, 'SELECT COUNT(*) FROM conversations WHERE started_at >= ?', [$d30]);
$msgTotal  = scalar($pdo, 'SELECT COUNT(*) FROM messages');
$convTotal = scalar($pdo, 'SELECT COUNT(*) FROM conversations');
$book30    = scalar($pdo, 'SELECT COUNT(*) FROM bookings WHERE created_at >= ?', [$d30]);
$views7    = scalar($pdo, 'SELECT COUNT(*) FROM pageviews WHERE created_at >= ?', [$d7]);
$viewsPrev = scalar($pdo, 'SELECT COUNT(*) FROM pageviews WHERE created_at >= ? AND created_at < ?', [$dprev, $d7]);

$perVisitor = $uniq30 > 0 ? round($views30 / $uniq30, 1) : 0;
$convRate   = $uniq30 > 0 ? round($book30 / $uniq30 * 100, 1) : 0;
$avgMsg     = $convTotal > 0 ? round($msgTotal / $convTotal, 1) : 0;
$trend7     = $viewsPrev > 0 ? round(($views7 - $viewsPrev) / $viewsPrev * 100) : ($views7 > 0 ? 100 : 0);

// ---- Series 30 jours : vues + uniques + reservations ----
$pv = $pdo->prepare("SELECT substr(created_at,1,10) d, COUNT(*) c FROM pageviews WHERE created_at >= ? GROUP BY d");
$pv->execute([$since]); $pvMap = $pv->fetchAll(PDO::FETCH_KEY_PAIR);
$uq = $pdo->prepare("SELECT substr(created_at,1,10) d, COUNT(DISTINCT visitor_hash) c FROM pageviews WHERE created_at >= ? AND visitor_hash != '' GROUP BY d");
$uq->execute([$since]); $uqMap = $uq->fetchAll(PDO::FETCH_KEY_PAIR);
$bk = $pdo->prepare("SELECT substr(created_at,1,10) d, COUNT(*) c FROM bookings WHERE created_at >= ? GROUP BY d");
$bk->execute([$since]); $bkMap = $bk->fetchAll(PDO::FETCH_KEY_PAIR);

$labels = []; $views = []; $uniques = []; $books = [];
for ($i = 29; $i >= 0; $i--) {
    $day = gmdate('Y-m-d', strtotime("-$i days"));
    $labels[]  = substr($day, 8, 2) . '.' . substr($day, 5, 2);
    $views[]   = (int)($pvMap[$day] ?? 0);
    $uniques[] = (int)($uqMap[$day] ?? 0);
    $books[]   = (int)($bkMap[$day] ?? 0);
}

// ---- Par heure (UTC) ----
$byHour = array_fill(0, 24, 0);
$h = $pdo->prepare("SELECT substr(created_at,12,2) hh, COUNT(*) c FROM pageviews WHERE created_at >= ? GROUP BY hh");
$h->execute([$d30]);
foreach ($h->fetchAll(PDO::FETCH_ASSOC) as $r) { $byHour[(int)$r['hh']] = (int)$r['c']; }
$hourLabels = []; for ($i = 0; $i < 24; $i++) { $hourLabels[] = $i . 'h'; }

// ---- Par jour de semaine ----
$dowRaw = [];
$w = $pdo->prepare("SELECT strftime('%w', created_at) w, COUNT(*) c FROM pageviews WHERE created_at >= ? GROUP BY w");
$w->execute([$d30]);
foreach ($w->fetchAll(PDO::FETCH_ASSOC) as $r) { $dowRaw[(int)$r['w']] = (int)$r['c']; }
$dowOrder = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 0 => 'Dim'];
$dowLabels = array_values($dowOrder); $dowData = [];
foreach (array_keys($dowOrder) as $k) { $dowData[] = (int)($dowRaw[$k] ?? 0); }

// ---- Statut des reservations ----
$st = $pdo->query("SELECT status, COUNT(*) c FROM bookings GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$statusData = [(int)($st['confirmed'] ?? 0), (int)($st['done'] ?? 0), (int)($st['cancelled'] ?? 0)];

// ---- Creneaux les plus demandes ----
$sl = $pdo->query("SELECT slot_time, COUNT(*) c FROM bookings WHERE status != 'cancelled' GROUP BY slot_time ORDER BY slot_time")->fetchAll(PDO::FETCH_ASSOC);
$slotLabels = array_column($sl, 'slot_time');
$slotData = array_map('intval', array_column($sl, 'c'));

// ---- Top listes ----
$topBlog = $pdo->prepare("SELECT path, COUNT(*) c FROM pageviews WHERE created_at >= ? AND path LIKE '/blog/%' GROUP BY path ORDER BY c DESC LIMIT 10");
$topBlog->execute([$d30]); $topBlog = $topBlog->fetchAll(PDO::FETCH_ASSOC);
$topPages = $pdo->prepare("SELECT path, COUNT(*) c FROM pageviews WHERE created_at >= ? GROUP BY path ORDER BY c DESC LIMIT 10");
$topPages->execute([$d30]); $topPages = $topPages->fetchAll(PDO::FETCH_ASSOC);

// Sources de trafic (referents externes)
$refRows = $pdo->prepare("SELECT referrer, COUNT(*) c FROM pageviews WHERE referrer != '' AND created_at >= ? GROUP BY referrer");
$refRows->execute([$d30]);
$hosts = [];
foreach ($refRows->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $host = parse_url($r['referrer'], PHP_URL_HOST) ?: $r['referrer'];
    if ($host === 'localhost' || strpos($host, 'finalyn') !== false) continue;
    $hosts[$host] = ($hosts[$host] ?? 0) + (int)$r['c'];
}
arsort($hosts); $topRef = array_slice($hosts, 0, 10, true);

// Entreprises ayant reserve
$comp = $pdo->query("SELECT company, COUNT(*) c FROM bookings WHERE status != 'cancelled' AND company != '' GROUP BY company ORDER BY c DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

admin_header('stats', 'Stats site & blog');
flash_render();
$trendArrow = $trend7 >= 0 ? '&uarr;' : '&darr;';
?>
<p class="muted" style="font-size:.85rem;margin-top:-.3rem">Donnees des 30 derniers jours (heures en UTC). Le suivi demarre une fois le site en ligne et le consentement accepte.</p>

<div class="adm-kpis" style="margin:1rem 0 1.4rem">
  <div class="kpi"><span class="kpi-num"><?= $views30 ?></span><span class="kpi-lbl">Pages vues</span></div>
  <div class="kpi"><span class="kpi-num"><?= $uniq30 ?></span><span class="kpi-lbl">Visiteurs uniques</span></div>
  <div class="kpi"><span class="kpi-num"><?= $perVisitor ?></span><span class="kpi-lbl">Pages / visiteur</span></div>
  <div class="kpi"><span class="kpi-num"><?= $trendArrow ?> <?= abs($trend7) ?>%</span><span class="kpi-lbl">Trafic 7 j vs 7 j</span></div>
  <div class="kpi"><span class="kpi-num"><?= $conv30 ?></span><span class="kpi-lbl">Conversations</span></div>
  <div class="kpi"><span class="kpi-num"><?= $avgMsg ?></span><span class="kpi-lbl">Msg / conversation</span></div>
  <div class="kpi"><span class="kpi-num"><?= $book30 ?></span><span class="kpi-lbl">Reservations</span></div>
  <div class="kpi"><span class="kpi-num"><?= $convRate ?>%</span><span class="kpi-lbl">Taux de conversion</span></div>
</div>

<div class="adm-block">
  <h2>Trafic (30 jours)</h2>
  <div class="chart-wrap"><canvas id="chartViews"></canvas></div>
</div>

<div class="adm-block adm-cols">
  <div><h2>Affluence par heure</h2><div class="chart-wrap"><canvas id="chartHour"></canvas></div></div>
  <div><h2>Affluence par jour</h2><div class="chart-wrap"><canvas id="chartDow"></canvas></div></div>
</div>

<div class="adm-block adm-cols">
  <div><h2>Reservations recues (30 j)</h2><div class="chart-wrap"><canvas id="chartBooks"></canvas></div></div>
  <div><h2>Statut des reservations</h2><div class="chart-wrap"><canvas id="chartStatus"></canvas></div></div>
</div>

<?php if ($slotData): ?>
<div class="adm-block">
  <h2>Creneaux les plus demandes</h2>
  <div class="chart-wrap"><canvas id="chartSlots"></canvas></div>
</div>
<?php endif; ?>

<div class="adm-block adm-cols">
  <div>
    <h2>Articles de blog les plus lus</h2>
    <?php if (!$topBlog): ?><p class="adm-empty">Aucune vue d'article (30 j).</p><?php else: ?>
    <ol class="adm-rank"><?php foreach ($topBlog as $r): ?><li><span class="rk-path"><?= h($r['path']) ?></span><span class="rk-c"><?= (int)$r['c'] ?></span></li><?php endforeach; ?></ol>
    <?php endif; ?>
  </div>
  <div>
    <h2>Pages les plus vues</h2>
    <?php if (!$topPages): ?><p class="adm-empty">Aucune vue (30 j).</p><?php else: ?>
    <ol class="adm-rank"><?php foreach ($topPages as $r): ?><li><span class="rk-path"><?= h($r['path']) ?></span><span class="rk-c"><?= (int)$r['c'] ?></span></li><?php endforeach; ?></ol>
    <?php endif; ?>
  </div>
</div>

<div class="adm-block adm-cols">
  <div>
    <h2>Sources de trafic</h2>
    <?php if (!$topRef): ?><p class="adm-empty">Pas encore de referent externe (trafic direct ou suivi non demarre).</p><?php else: ?>
    <ol class="adm-rank"><?php foreach ($topRef as $host => $c): ?><li><span class="rk-path"><?= h($host) ?></span><span class="rk-c"><?= (int)$c ?></span></li><?php endforeach; ?></ol>
    <?php endif; ?>
  </div>
  <div>
    <h2>Entreprises ayant reserve</h2>
    <?php if (!$comp): ?><p class="adm-empty">Aucune reservation pour l'instant.</p><?php else: ?>
    <ol class="adm-rank"><?php foreach ($comp as $r): ?><li><span class="rk-path"><?= h($r['company']) ?></span><span class="rk-c"><?= (int)$r['c'] ?></span></li><?php endforeach; ?></ol>
    <?php endif; ?>
  </div>
</div>
<?php
$L = json_encode($labels);
$scripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script><script>(function(){if(!window.Chart)return;'
. 'Chart.defaults.font.family="Inter";Chart.defaults.font.size=11;'
. 'var noLeg={responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{ticks:{maxTicksLimit:12,autoSkip:true}}}};'
. 'var leg={responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{boxWidth:12}}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{ticks:{maxTicksLimit:12,autoSkip:true}}}};'
. 'function mk(id,cfg){var c=document.getElementById(id);if(c)new Chart(c,cfg);}'
. 'mk("chartViews",{type:"line",data:{labels:' . $L . ',datasets:['
. '{label:"Pages vues",data:' . json_encode($views) . ',borderColor:"#8b5cf6",backgroundColor:"rgba(139,92,246,.12)",fill:true,tension:.35,borderWidth:2,pointRadius:1},'
. '{label:"Visiteurs uniques",data:' . json_encode($uniques) . ',borderColor:"#0ea5e9",backgroundColor:"rgba(14,165,233,.10)",fill:true,tension:.35,borderWidth:2,pointRadius:1}]},options:leg});'
. 'mk("chartHour",{type:"bar",data:{labels:' . json_encode($hourLabels) . ',datasets:[{data:' . json_encode(array_values($byHour)) . ',backgroundColor:"#8b5cf6",borderRadius:3}]},options:noLeg});'
. 'mk("chartDow",{type:"bar",data:{labels:' . json_encode($dowLabels) . ',datasets:[{data:' . json_encode($dowData) . ',backgroundColor:"#7c4def",borderRadius:4}]},options:noLeg});'
. 'mk("chartBooks",{type:"bar",data:{labels:' . $L . ',datasets:[{data:' . json_encode($books) . ',backgroundColor:"#d97706",borderRadius:4}]},options:noLeg});'
. 'mk("chartStatus",{type:"doughnut",data:{labels:["Confirmees","Faites","Annulees"],datasets:[{data:' . json_encode($statusData) . ',backgroundColor:["#8b5cf6","#16a34a","#cbb9b2"]}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:"bottom",labels:{boxWidth:12}}}}});'
. ($slotData ? 'mk("chartSlots",{type:"bar",data:{labels:' . json_encode($slotLabels) . ',datasets:[{data:' . json_encode($slotData) . ',backgroundColor:"#0ea5e9",borderRadius:4}]},options:noLeg});' : '')
. '})();</script>';
admin_footer($scripts);
