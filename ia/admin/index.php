<?php
require __DIR__ . '/boot.php';

$today = gmdate('Y-m-d');
$d7  = gmdate('Y-m-d H:i:s', strtotime('-7 days'));

$kpi = [
    'book_up'     => scalar($pdo, "SELECT COUNT(*) FROM bookings WHERE status='confirmed' AND slot_date >= ?", [$today]),
    'conv_7'      => scalar($pdo, 'SELECT COUNT(*) FROM conversations WHERE started_at >= ?', [$d7]),
    'conv_total'  => scalar($pdo, 'SELECT COUNT(*) FROM conversations'),
    'msg_total'   => scalar($pdo, 'SELECT COUNT(*) FROM messages'),
    'views_today' => scalar($pdo, 'SELECT COUNT(*) FROM pageviews WHERE created_at >= ?', [$today . ' 00:00:00']),
    'views_7'     => scalar($pdo, 'SELECT COUNT(*) FROM pageviews WHERE created_at >= ?', [$d7]),
    'book_total'  => scalar($pdo, "SELECT COUNT(*) FROM bookings WHERE status!='cancelled'"),
    'posts'       => scalar($pdo, 'SELECT COUNT(*) FROM posts'),
];

// Series 14 jours
$labels = []; $views = []; $convs = [];
$pv = $pdo->prepare("SELECT substr(created_at,1,10) d, COUNT(*) c FROM pageviews WHERE created_at >= ? GROUP BY d");
$pv->execute([gmdate('Y-m-d', strtotime('-13 days')) . ' 00:00:00']);
$pvMap = $pv->fetchAll(PDO::FETCH_KEY_PAIR);
$cv = $pdo->prepare("SELECT substr(started_at,1,10) d, COUNT(*) c FROM conversations WHERE started_at >= ? GROUP BY d");
$cv->execute([gmdate('Y-m-d', strtotime('-13 days')) . ' 00:00:00']);
$cvMap = $cv->fetchAll(PDO::FETCH_KEY_PAIR);
for ($i = 13; $i >= 0; $i--) {
    $day = gmdate('Y-m-d', strtotime("-$i days"));
    $labels[] = substr($day, 8, 2) . '.' . substr($day, 5, 2);
    $views[] = (int)($pvMap[$day] ?? 0);
    $convs[] = (int)($cvMap[$day] ?? 0);
}

$upcoming = $pdo->prepare("SELECT * FROM bookings WHERE status='confirmed' AND slot_date >= ? ORDER BY slot_date, slot_time LIMIT 5");
$upcoming->execute([$today]);
$upcoming = $upcoming->fetchAll(PDO::FETCH_ASSOC);

$lastConvs = $pdo->query("SELECT * FROM conversations ORDER BY last_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$firstMsg = $pdo->prepare("SELECT content FROM messages WHERE conversation_id=? AND role='user' ORDER BY id LIMIT 1");

function kico($p) { return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>'; }

admin_header('dashboard', "Vue d'ensemble");
flash_render();
?>
<div class="adm-kpis">
  <div class="kpi"><span class="kpi-ico"><?= kico('<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>') ?></span><span class="kpi-num"><?= $kpi['book_up'] ?></span><span class="kpi-lbl">RDV a venir</span></div>
  <div class="kpi"><span class="kpi-ico"><?= kico('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>') ?></span><span class="kpi-num"><?= $kpi['conv_7'] ?></span><span class="kpi-lbl">Conversations (7 j)</span></div>
  <div class="kpi"><span class="kpi-ico"><?= kico('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>') ?></span><span class="kpi-num"><?= $kpi['conv_total'] ?></span><span class="kpi-lbl">Conversations (total)</span></div>
  <div class="kpi"><span class="kpi-ico"><?= kico('<path d="M4 4h16v12H5.17L4 17.17z"/>') ?></span><span class="kpi-num"><?= $kpi['msg_total'] ?></span><span class="kpi-lbl">Messages</span></div>
  <div class="kpi"><span class="kpi-ico"><?= kico('<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>') ?></span><span class="kpi-num"><?= $kpi['views_today'] ?></span><span class="kpi-lbl">Pages vues (auj.)</span></div>
  <div class="kpi"><span class="kpi-ico"><?= kico('<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>') ?></span><span class="kpi-num"><?= $kpi['views_7'] ?></span><span class="kpi-lbl">Pages vues (7 j)</span></div>
  <div class="kpi"><span class="kpi-ico"><?= kico('<path d="M20 6 9 17l-5-5"/>') ?></span><span class="kpi-num"><?= $kpi['book_total'] ?></span><span class="kpi-lbl">RDV (total)</span></div>
  <div class="kpi"><span class="kpi-ico"><?= kico('<path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>') ?></span><span class="kpi-num"><?= $kpi['posts'] ?></span><span class="kpi-lbl">Articles publies</span></div>
</div>

<div class="adm-block">
  <h2>Activite (14 derniers jours)</h2>
  <div class="chart-wrap"><canvas id="chartMain"></canvas></div>
</div>

<div class="adm-block adm-cols">
  <div>
    <h2>Prochains rendez-vous</h2>
    <?php if (!$upcoming): ?><p class="adm-empty">Aucun rendez-vous a venir. <a href="calendar.php">Voir le calendrier</a></p>
    <?php else: ?>
    <table class="adm-table">
      <tbody>
      <?php foreach ($upcoming as $b): ?>
        <tr><td><?= fr_d($b['slot_date']) ?> · <?= h($b['slot_time']) ?></td><td><?= h($b['firstname'].' '.$b['lastname']) ?></td><td><?= h($b['company']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin:.6rem 0 0"><a class="btn" href="calendar.php">Gerer le calendrier</a></p>
    <?php endif; ?>
  </div>
  <div>
    <h2>Dernieres conversations</h2>
    <?php if (!$lastConvs): ?><p class="adm-empty">Aucune conversation pour l'instant.</p>
    <?php else: ?>
    <ul class="adm-rank">
      <?php foreach ($lastConvs as $c): $firstMsg->execute([$c['id']]); $fm = (string)$firstMsg->fetchColumn(); ?>
        <li><span class="rk-path"><?= h(mb_substr($fm, 0, 60)) ?: '(sans message)' ?></span><span class="rk-c"><?= (int)$c['msg_count'] ?></span></li>
      <?php endforeach; ?>
    </ul>
    <p style="margin:.6rem 0 0"><a class="btn" href="conversations.php">Voir les conversations</a></p>
    <?php endif; ?>
  </div>
</div>
<?php
$scripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>'
. '<script>(function(){var ctx=document.getElementById("chartMain");if(!ctx||!window.Chart)return;'
. 'new Chart(ctx,{type:"line",data:{labels:' . json_encode($labels) . ','
. 'datasets:[{label:"Pages vues",data:' . json_encode($views) . ',borderColor:"#8b5cf6",backgroundColor:"rgba(139,92,246,.12)",fill:true,tension:.35,borderWidth:2,pointRadius:2},'
. '{label:"Conversations",data:' . json_encode($convs) . ',borderColor:"#d97706",backgroundColor:"rgba(217,119,6,.10)",fill:true,tension:.35,borderWidth:2,pointRadius:2}]},'
. 'options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{boxWidth:12,font:{family:"Inter"}}}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});})();</script>';
admin_footer($scripts);
