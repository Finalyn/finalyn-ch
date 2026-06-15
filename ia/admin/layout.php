<?php
/** Layout partage du back-office : barre laterale + en-tete. */

function admin_nav_items() {
    return [
        ['dashboard',     'index.php',         'Vue d\'ensemble', '<path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>'],
        ['calendar',      'calendar.php',      'Calendrier',      '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'],
        ['conversations', 'conversations.php', 'Conversations',   '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
        ['stats',         'stats.php',         'Stats',           '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>'],
        ['blog',          'blog.php',          'Blog',            '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>'],
    ];
}

function admin_header($active, $title, $headExtra = '') {
    $nav = admin_nav_items();
    ?>
<!DOCTYPE html>
<html lang="fr-CH">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex, nofollow" />
<title><?= h($title) ?> · Back-office finalyn.ia</title>
<link rel="stylesheet" href="style.css" />
<?= $headExtra ?>
</head>
<body>
<input type="checkbox" id="adm-burger" class="adm-burger" hidden>
<div class="adm-shell">
  <aside class="adm-side">
    <div class="adm-logo">finalyn<span class="ia">.ia</span></div>
    <nav class="adm-menu">
      <?php foreach ($nav as $it): ?>
        <a href="<?= $it[1] ?>" class="<?= $active === $it[0] ? 'is-active' : '' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $it[3] ?></svg>
          <span><?= h($it[2]) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <a class="adm-side-logout" href="logout.php">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      <span>Deconnexion</span>
    </a>
  </aside>

  <div class="adm-content">
    <header class="adm-bar">
      <label for="adm-burger" class="adm-burger-btn" aria-label="Menu">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </label>
      <h1><?= h($title) ?></h1>
      <a class="adm-bar-site" href="../index.html" target="_blank" rel="noopener">Voir le site</a>
    </header>
    <main class="adm-page">
<?php
}

function admin_footer($scripts = '') {
    ?>
    </main>
  </div>
</div>
<?= $scripts ?>
</body>
</html>
<?php
}
