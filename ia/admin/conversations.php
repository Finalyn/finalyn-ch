<?php
require __DIR__ . '/boot.php';

$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 25;
$off = ($page - 1) * $per;

if ($q !== '') {
    $like = '%' . $q . '%';
    $total = scalar($pdo, "SELECT COUNT(*) FROM conversations WHERE id IN (SELECT conversation_id FROM messages WHERE content LIKE ?)", [$like]);
    $stmt = $pdo->prepare("SELECT * FROM conversations WHERE id IN (SELECT conversation_id FROM messages WHERE content LIKE ?) ORDER BY last_at DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $like); $stmt->bindValue(2, $per, PDO::PARAM_INT); $stmt->bindValue(3, $off, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $total = scalar($pdo, "SELECT COUNT(*) FROM conversations");
    $stmt = $pdo->prepare("SELECT * FROM conversations ORDER BY last_at DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $per, PDO::PARAM_INT); $stmt->bindValue(2, $off, PDO::PARAM_INT);
    $stmt->execute();
}
$convs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pages = max(1, (int)ceil($total / $per));
$msgStmt = $pdo->prepare('SELECT role, content, created_at FROM messages WHERE conversation_id=? ORDER BY id');

admin_header('conversations', 'Conversations');
flash_render();
?>
<div class="adm-block">
  <form method="get" class="adm-inline-form" style="margin-bottom:1rem">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Rechercher dans les conversations..." style="flex:1;min-width:220px">
    <button class="btn dark" type="submit">Rechercher</button>
    <?php if ($q !== ''): ?><a class="btn" href="conversations.php">Effacer</a><?php endif; ?>
  </form>

  <p class="muted" style="font-size:.85rem"><?= $total ?> conversation(s)<?= $q !== '' ? ' pour « ' . h($q) . ' »' : '' ?>.</p>

  <?php if (!$convs): ?>
    <p class="adm-empty">Aucune conversation<?= $q !== '' ? ' ne correspond' : ' enregistree pour l\'instant' ?>.</p>
  <?php else: foreach ($convs as $c): ?>
    <?php $msgStmt->execute([$c['id']]); $msgs = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
          $first = ''; foreach ($msgs as $m) { if ($m['role'] === 'user') { $first = $m['content']; break; } } ?>
    <details class="adm-conv">
      <summary>
        <span class="conv-when"><?= fr_dt($c['last_at']) ?></span>
        <span class="conv-count"><?= (int)$c['msg_count'] ?> msg</span>
        <span class="conv-preview"><?= h(mb_substr($first, 0, 100)) ?: '(sans message)' ?></span>
      </summary>
      <div class="conv-thread">
        <?php foreach ($msgs as $mm): ?>
          <div class="conv-msg conv-<?= h($mm['role']) ?>">
            <span class="conv-role"><?= $mm['role'] === 'user' ? 'Visiteur' : 'Assistant' ?></span>
            <p><?= nl2br(h($mm['content'])) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endforeach; endif; ?>

  <?php if ($pages > 1): ?>
    <div class="adm-inline-form" style="margin-top:1.2rem;justify-content:center">
      <?php $qs = $q !== '' ? '&q=' . urlencode($q) : ''; ?>
      <?php if ($page > 1): ?><a class="btn" href="?p=<?= $page - 1 ?><?= $qs ?>">&larr; Precedent</a><?php endif; ?>
      <span class="muted" style="font-size:.85rem">Page <?= $page ?> / <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn" href="?p=<?= $page + 1 ?><?= $qs ?>">Suivant &rarr;</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php
admin_footer();
