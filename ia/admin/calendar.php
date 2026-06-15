<?php
require __DIR__ . '/boot.php';
require_once __DIR__ . '/../api/mail.php';

// ---------- Actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'calendar.php' . (!empty($_POST['m']) ? '?m=' . urlencode($_POST['m']) : '');
    if (!finalyn_csrf_ok($_POST['csrf'] ?? '')) { admin_flash_set('Jeton invalide.', true); admin_redirect($back); }
    $a = $_POST['action'] ?? '';
    if ($a === 'cancel_booking' && !empty($_POST['id'])) {
        $row = $pdo->prepare('SELECT * FROM bookings WHERE id=?'); $row->execute([(int)$_POST['id']]); $row = $row->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("UPDATE bookings SET status='cancelled' WHERE id=?")->execute([(int)$_POST['id']]);
        if ($row) finalyn_booking_notify('cancel_admin', $row);
        admin_flash_set('Reservation annulee, e-mail envoye au client.');
    } elseif ($a === 'done_booking' && !empty($_POST['id'])) {
        $row = $pdo->prepare('SELECT * FROM bookings WHERE id=?'); $row->execute([(int)$_POST['id']]); $row = $row->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("UPDATE bookings SET status='done' WHERE id=?")->execute([(int)$_POST['id']]);
        if ($row) finalyn_booking_notify('done', $row);
        admin_flash_set('Reservation marquee comme faite, e-mail envoye au client.');
    } elseif ($a === 'block_date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'] ?? '')) {
        $pdo->prepare('INSERT OR IGNORE INTO blocked_dates (slot_date, created_at) VALUES (?,?)')->execute([$_POST['date'], gmdate('Y-m-d H:i:s')]);
        admin_flash_set('Jour bloque.');
    } elseif ($a === 'unblock_date' && !empty($_POST['date'])) {
        $pdo->prepare('DELETE FROM blocked_dates WHERE slot_date=?')->execute([$_POST['date']]);
        admin_flash_set('Jour debloque.');
    } elseif ($a === 'block_slot' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'] ?? '') && preg_match('/^\d{2}:\d{2}$/', $_POST['time'] ?? '')) {
        $pdo->prepare('INSERT OR IGNORE INTO blocked_slots (slot_date, slot_time, created_at) VALUES (?,?,?)')->execute([$_POST['date'], $_POST['time'], gmdate('Y-m-d H:i:s')]);
        admin_flash_set('Creneau bloque (' . $_POST['date'] . ' a ' . $_POST['time'] . ').');
    } elseif ($a === 'unblock_slot' && !empty($_POST['date']) && !empty($_POST['time'])) {
        $pdo->prepare('DELETE FROM blocked_slots WHERE slot_date=? AND slot_time=?')->execute([$_POST['date'], $_POST['time']]);
        admin_flash_set('Creneau debloque.');
    } elseif ($a === 'save_avail') {
        $days = isset($_POST['days']) && is_array($_POST['days']) ? array_values(array_map('intval', $_POST['days'])) : [];
        $slots = [];
        foreach (preg_split('/[\s,]+/', (string)($_POST['slots'] ?? '')) as $t) {
            $t = trim($t);
            if (preg_match('/^\d{1,2}:\d{2}$/', $t)) { $slots[] = sprintf('%02d:%s', (int)substr($t, 0, strpos($t, ':')), substr($t, strpos($t, ':') + 1)); }
        }
        $dur = max(5, (int)($_POST['duration'] ?? 30));
        if (!$days) $days = [1,2,3,4,5];
        if (!$slots) $slots = finalyn_avail_default()['slots'];
        finalyn_avail_save(['days' => $days, 'slots' => $slots, 'duration' => $dur]);
        admin_flash_set('Disponibilites enregistrees.');
    }
    admin_redirect($back);
}

// ---------- Mois affiche ----------
$m = $_GET['m'] ?? gmdate('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $m)) $m = gmdate('Y-m');
$year = (int)substr($m, 0, 4); $month = (int)substr($m, 5, 2);
$firstTs = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = (int)date('t', $firstTs);
$startCol = ((int)date('N', $firstTs)) - 1; // 0=lundi
$prevM = date('Y-m', mktime(0, 0, 0, $month - 1, 1, $year));
$nextM = date('Y-m', mktime(0, 0, 0, $month + 1, 1, $year));
$frMonths = ['', 'janvier','fevrier','mars','avril','mai','juin','juillet','aout','septembre','octobre','novembre','decembre'];
$todayStr = gmdate('Y-m-d');

$avail = finalyn_avail();
$slotCount = max(1, count($avail['slots']));

// Donnees du mois
$like = sprintf('%04d-%02d-%%', $year, $month);
$counts = $pdo->prepare("SELECT slot_date, COUNT(*) c FROM bookings WHERE status!='cancelled' AND slot_date LIKE ? GROUP BY slot_date");
$counts->execute([$like]); $counts = $counts->fetchAll(PDO::FETCH_KEY_PAIR);
$blk = $pdo->prepare("SELECT slot_date FROM blocked_dates WHERE slot_date LIKE ?");
$blk->execute([$like]); $blocked = array_flip($blk->fetchAll(PDO::FETCH_COLUMN));

// Reservations a venir (table sous le calendrier)
$bk = $pdo->prepare("SELECT * FROM bookings WHERE status='confirmed' AND slot_date >= ? ORDER BY slot_date, slot_time");
$bk->execute([$todayStr]); $upcoming = $bk->fetchAll(PDO::FETCH_ASSOC);

$bs = $pdo->prepare("SELECT slot_date, slot_time FROM blocked_slots WHERE slot_date >= ? ORDER BY slot_date, slot_time");
$bs->execute([$todayStr]); $blockedSlots = $bs->fetchAll(PDO::FETCH_ASSOC);

admin_header('calendar', 'Calendrier');
flash_render();
?>
<div class="adm-block card">
  <div class="cal-admin-head">
    <a class="btn" href="?m=<?= $prevM ?>">&larr;</a>
    <h2><?= $frMonths[$month] . ' ' . $year ?></h2>
    <a class="btn" href="?m=<?= $nextM ?>">&rarr;</a>
  </div>
  <div class="cal-admin-grid-head"><span>Lun</span><span>Mar</span><span>Mer</span><span>Jeu</span><span>Ven</span><span>Sam</span><span>Dim</span></div>
  <div class="cal-admin-grid">
    <?php for ($i = 0; $i < $startCol; $i++): ?><span class="cal-cell blank"></span><?php endfor; ?>
    <?php for ($d = 1; $d <= $daysInMonth; $d++):
      $ds = sprintf('%04d-%02d-%02d', $year, $month, $d);
      $iso = (int)date('N', mktime(0,0,0,$month,$d,$year));
      $isOff = !in_array($iso, $avail['days'], true);
      $isBlocked = isset($blocked[$ds]);
      $n = (int)($counts[$ds] ?? 0);
      $cls = 'cal-cell'; if ($isOff) $cls .= ' off'; if ($isBlocked) $cls .= ' blocked'; if ($ds === $todayStr) $cls .= ' today';
    ?>
      <div class="<?= $cls ?>">
        <span class="cdate"><?= $d ?></span>
        <?php if ($n > 0): ?><span class="cbook"><?= $n ?> RDV</span><?php endif; ?>
        <?php if (!$isOff && $ds >= $todayStr): ?>
          <span class="cblock-btn">
            <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="<?= h($m) ?>"><input type="hidden" name="date" value="<?= $ds ?>">
              <?php if ($isBlocked): ?>
                <input type="hidden" name="action" value="unblock_date"><button type="submit">debloquer</button>
              <?php else: ?>
                <input type="hidden" name="action" value="block_date"><button type="submit">bloquer</button>
              <?php endif; ?>
            </form>
          </span>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
  <p class="field-help">Gris = jour non ouvre · rose = jour bloque · pastille = nombre de RDV. Un jour se bloque/debloque directement sur sa case.</p>
</div>

<div class="adm-block">
  <h2>Rendez-vous a venir</h2>
  <?php if (!$upcoming): ?><p class="adm-empty">Aucun rendez-vous a venir.</p>
  <?php else: ?>
  <table class="adm-table">
    <thead><tr><th>Date</th><th>Heure</th><th>Personne</th><th>Entreprise</th><th>Contact</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($upcoming as $b): ?>
      <tr>
        <td><?= fr_d($b['slot_date']) ?></td><td><?= h($b['slot_time']) ?></td>
        <td><?= h($b['firstname'].' '.$b['lastname']) ?></td><td><?= h($b['company']) ?></td>
        <td><a href="mailto:<?= h($b['email']) ?>"><?= h($b['email']) ?></a></td>
        <td class="adm-actions">
          <form method="post" onsubmit="return confirm('Marquer comme fait ?');"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="<?= h($m) ?>"><input type="hidden" name="action" value="done_booking"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn ghost" type="submit">Fait</button></form>
          <form method="post" onsubmit="return confirm('Annuler ?');"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="<?= h($m) ?>"><input type="hidden" name="action" value="cancel_booking"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn danger" type="submit">Annuler</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="adm-block card">
  <h2>Bloquer un creneau precis</h2>
  <p class="field-help">Pour les cas « ce jour-la, je ne suis pas dispo a telle heure ». Le creneau disparait alors du calendrier public (les RDV deja pris sont aussi grises automatiquement).</p>
  <form method="post" class="adm-inline-form" style="margin-top:.8rem">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="<?= h($m) ?>"><input type="hidden" name="action" value="block_slot">
    <input type="date" name="date" required>
    <select name="time" required style="padding:.5rem .7rem;border:1px solid var(--line);border-radius:10px;font-family:inherit">
      <?php foreach ($avail['slots'] as $s): ?><option value="<?= h($s) ?>"><?= h($s) ?></option><?php endforeach; ?>
    </select>
    <button class="btn dark" type="submit">Bloquer ce creneau</button>
  </form>
  <?php if ($blockedSlots): ?>
    <div class="adm-chips" style="margin-top:1rem">
      <?php foreach ($blockedSlots as $bsr): ?>
        <span class="adm-chip"><?= fr_d($bsr['slot_date']) ?> &middot; <?= h($bsr['slot_time']) ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="<?= h($m) ?>"><input type="hidden" name="action" value="unblock_slot"><input type="hidden" name="date" value="<?= h($bsr['slot_date']) ?>"><input type="hidden" name="time" value="<?= h($bsr['slot_time']) ?>"><button type="submit" aria-label="Debloquer">&times;</button></form>
        </span>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="adm-empty" style="margin-top:.8rem">Aucun creneau bloque pour l'instant.</p>
  <?php endif; ?>
</div>

<div class="adm-block card">
  <h2>Disponibilites (comme Calendly)</h2>
  <p class="field-help">Ces reglages pilotent le calendrier public : jours ouverts, creneaux proposes et duree du rendez-vous.</p>
  <form method="post" class="adm-form" style="margin-top:1rem">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_avail"><input type="hidden" name="m" value="<?= h($m) ?>">
    <div>
      <label>Jours ouverts</label>
      <div class="days-pick">
        <?php $dn = [1=>'Lun',2=>'Mar',3=>'Mer',4=>'Jeu',5=>'Ven',6=>'Sam',7=>'Dim'];
        foreach ($dn as $iso => $lbl): ?>
          <label><input type="checkbox" name="days[]" value="<?= $iso ?>" <?= in_array($iso, $avail['days'], true) ? 'checked' : '' ?>> <?= $lbl ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div>
      <label for="slots">Creneaux horaires</label>
      <input type="text" id="slots" name="slots" value="<?= h(implode(', ', $avail['slots'])) ?>">
      <p class="field-help">Heures separees par des virgules (format 24 h), ex : 08:00, 09:00, 14:00, 15:00.</p>
    </div>
    <div style="max-width:200px">
      <label for="duration">Duree (minutes)</label>
      <input type="number" id="duration" name="duration" min="5" step="5" value="<?= (int)$avail['duration'] ?>">
    </div>
    <div><button class="btn dark" type="submit">Enregistrer les disponibilites</button></div>
  </form>
</div>
<?php
admin_footer();
