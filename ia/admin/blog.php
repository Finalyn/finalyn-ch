<?php
require __DIR__ . '/boot.php';

$ROOT     = realpath(__DIR__ . '/..');
$BLOG_DIR = $ROOT . '/blog';
$BLOG_IDX = $BLOG_DIR . '/index.html';
$SITEMAP  = $ROOT . '/sitemap.xml';
$frMonths = ['', 'janvier','fevrier','mars','avril','mai','juin','juillet','aout','septembre','octobre','novembre','decembre'];

function slugify($s) {
    $s = function_exists('mb_strtolower') ? mb_strtolower(trim($s)) : strtolower(trim($s));
    $map = ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','œ'=>'oe','’'=>' ', "'"=>' '];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

function md_to_html($text) {
    $text = str_replace("\r\n", "\n", (string)$text);
    $out = [];
    foreach (preg_split("/\n{2,}/", trim($text)) as $b) {
        $b = trim($b);
        if ($b === '') continue;
        if (preg_match('/^###\s+(.+)/s', $b, $m)) { $out[] = '<h3>' . h(trim($m[1])) . '</h3>'; continue; }
        if (preg_match('/^##\s+(.+)/s', $b, $m))  { $out[] = '<h2>' . h(trim($m[1])) . '</h2>'; continue; }
        $lines = explode("\n", $b);
        $isList = true;
        foreach ($lines as $l) { if (!preg_match('/^[-*]\s+/', trim($l))) { $isList = false; break; } }
        if ($isList) {
            $items = '';
            foreach ($lines as $l) { $items .= '<li>' . h(preg_replace('/^[-*]\s+/', '', trim($l))) . '</li>'; }
            $out[] = '<ul>' . $items . '</ul>';
            continue;
        }
        $out[] = '<p>' . nl2br(h($b)) . '</p>';
    }
    return implode("\n", $out);
}

function build_article($slug, $title, $tag, $excerpt, $cover, $readMin, $dateIso, $dateFr, $bodyHtml) {
    $url = 'https://finalyn.ch/ia/blog/' . $slug . '.html';
    $T = h($title); $TAG = h($tag); $EXC = h($excerpt);
    $jsonld = json_encode([
        '@context' => 'https://schema.org', '@type' => 'BlogPosting',
        'headline' => $title, 'description' => $excerpt, 'url' => $url,
        'datePublished' => $dateIso, 'inLanguage' => 'fr-CH',
        'image' => $cover ?: 'https://finalyn.ch/ia/og-cover.jpg',
        'author' => ['@id' => 'https://finalyn.ch/ia/#organization'],
        'publisher' => ['@id' => 'https://finalyn.ch/ia/#organization'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $coverHtml = $cover ? '<section class="article-cover"><img src="' . h($cover) . '" alt="' . $T . '" /></section>' : '';
    $ogImg = $cover ? h($cover) : 'https://finalyn.ch/ia/og-cover.jpg';

    return <<<HTML
<!DOCTYPE html>
<html lang="fr-CH">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>{$T} · finalyn.ia</title>
<meta name="description" content="{$EXC}" />
<meta name="author" content="finalyn.ia" />
<meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large" />
<link rel="canonical" href="{$url}" />
<meta name="geo.region" content="CH-VD" />
<meta name="geo.placename" content="Rolle, Suisse" />
<meta property="og:type" content="article" />
<meta property="og:locale" content="fr_CH" />
<meta property="og:site_name" content="finalyn.ia" />
<meta property="og:title" content="{$T}" />
<meta property="og:description" content="{$EXC}" />
<meta property="og:url" content="{$url}" />
<meta property="og:image" content="{$ogImg}" />
<meta name="theme-color" content="#FAF8F4" />
<link rel="icon" type="image/png" href="/favicon.png" />
<link rel="icon" type="image/svg+xml" href="/favicon.svg" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />
<script type="application/ld+json">{$jsonld}</script>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="../assets/service-page.css" />
<link rel="stylesheet" href="../assets/blog.css" />
</head>
<body>

<nav class="nav">
  <a href="../index.html" class="brand">finalyn<span class="ia">.ia</span></a>
  <nav class="nav-links" aria-label="Navigation principale">
    <a href="../index.html#services">Services</a>
    <a href="../index.html#cas-usage">Cas d'usage</a>
    <a href="../index.html#stack">Outils</a>
    <a href="index.html">Blog</a>
    <a href="../index.html#audit">Audit gratuit</a>
  </nav>
</nav>

<section class="article-hero">
  <p class="article-breadcrumb"><a href="../index.html">Accueil</a> / <a href="index.html">Blog</a> / {$T}</p>
  <span class="article-tag">{$TAG}</span>
  <h1>{$T}</h1>
  <div class="article-meta"><time datetime="{$dateIso}">{$dateFr}</time><span>{$readMin} min de lecture</span></div>
</section>
{$coverHtml}

<article class="article-body">
{$bodyHtml}

  <div class="article-cta">
    <h3>Envie d'aller plus loin&nbsp;?</h3>
    <p>On regarde ensemble, lors d'un audit gratuit de 30 min, comment l'IA peut vous faire gagner du temps.</p>
    <a class="realisations-cta-link" href="../index.html#audit" style="color:#7C4DEF;font-weight:500;text-decoration:none;">Reserver l'audit gratuit &rarr;</a>
  </div>
</article>

<footer class="site-footer" role="contentinfo">
 <div class="footer-container">
  <div class="footer-bottom">
    <p>© <time datetime="2026">2026</time> Finalyn, tous droits réservés.</p>
    <ul class="footer-legal">
      <li><a href="index.html">Blog</a></li>
      <li><a href="../mentions-legales.html">Mentions légales</a></li>
      <li><a href="../confidentialite.html">Confidentialité</a></li>
    </ul>
  </div>
 </div>
</footer>

<script src="/assets/consent.js" defer></script>
</body>
</html>
HTML;
}

function build_card($slug, $title, $tag, $excerpt, $cover, $dateFr, $readMin) {
    $bg = $cover !== '' ? ' style="background-image: url(\'' . h($cover) . '\');"' : '';
    return "\n    <a href=\"" . $slug . ".html\" class=\"blog-card\">\n"
        . "      <div class=\"blog-card-image\"" . $bg . " aria-hidden=\"true\"></div>\n"
        . "      <div class=\"blog-card-content\">\n"
        . "        <span class=\"blog-card-tag\">" . h($tag) . "</span>\n"
        . "        <h2>" . h($title) . "</h2>\n"
        . "        <p>" . h($excerpt) . "</p>\n"
        . "        <div class=\"blog-card-meta\"><span>" . $dateFr . " &middot; " . $readMin . " min</span><strong>Lire &rarr;</strong></div>\n"
        . "      </div>\n    </a>";
}

// ---------- Actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!finalyn_csrf_ok($_POST['csrf'] ?? '')) { admin_flash_set('Jeton invalide.', true); admin_redirect('blog.php'); }
    $a = $_POST['action'] ?? '';

    if ($a === 'delete_post' && !empty($_POST['id'])) {
        $row = $pdo->prepare('SELECT * FROM posts WHERE id=?'); $row->execute([(int)$_POST['id']]); $row = $row->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $slug = $row['slug'];
            @unlink($BLOG_DIR . '/' . $slug . '.html');
            if (is_file($BLOG_IDX) && is_writable($BLOG_IDX)) {
                $html = file_get_contents($BLOG_IDX);
                $html = preg_replace('#\s*<a href="' . preg_quote($slug, '#') . '\.html" class="blog-card">.*?</a>#s', '', $html, 1);
                file_put_contents($BLOG_IDX, $html);
            }
            if (is_file($SITEMAP) && is_writable($SITEMAP)) {
                $xml = file_get_contents($SITEMAP);
                $xml = preg_replace('#\s*<url>\s*<loc>https://ia\.finalyn\.ch/blog/' . preg_quote($slug, '#') . '\.html</loc>.*?</url>#s', '', $xml, 1);
                file_put_contents($SITEMAP, $xml);
            }
            $pdo->prepare('DELETE FROM posts WHERE id=?')->execute([(int)$row['id']]);
            admin_flash_set('Article supprime.');
        }
        admin_redirect('blog.php');
    }

    if ($a === 'create_post') {
        $title = trim($_POST['title'] ?? '');
        $tag = trim($_POST['tag'] ?? 'Article');
        $excerpt = trim($_POST['excerpt'] ?? '');
        $cover = trim($_POST['cover'] ?? '');
        $readMin = max(1, (int)($_POST['read_min'] ?? 5));
        $body = (string)($_POST['body'] ?? '');
        $slug = slugify($_POST['slug'] ?? '') ?: slugify($title);

        $err = '';
        if ($title === '' || $excerpt === '' || trim($body) === '') $err = 'Titre, resume et contenu sont obligatoires.';
        elseif ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) $err = 'Adresse (slug) invalide.';
        elseif (is_file($BLOG_DIR . '/' . $slug . '.html')) $err = 'Un article avec cette adresse existe deja.';
        else { $chk = $pdo->prepare('SELECT 1 FROM posts WHERE slug=?'); $chk->execute([$slug]); if ($chk->fetchColumn()) $err = 'Cette adresse est deja utilisee.'; }
        if ($cover !== '' && !preg_match('#^https?://#', $cover)) $err = $err ?: "L'image de couverture doit etre une URL (http...).";
        if (!is_dir($BLOG_DIR) || !is_writable($BLOG_DIR)) $err = $err ?: "Le dossier blog/ n'est pas accessible en ecriture sur le serveur.";

        if ($err) { admin_flash_set($err, true); admin_redirect('blog.php'); }

        $dateIso = gmdate('Y-m-d');
        $dateFr = ((int)gmdate('j')) . ' ' . $frMonths[(int)gmdate('n')] . ' ' . gmdate('Y');
        $bodyHtml = md_to_html($body);
        $articleHtml = build_article($slug, $title, $tag, $excerpt, $cover, $readMin, $dateIso, $dateFr, $bodyHtml);

        if (file_put_contents($BLOG_DIR . '/' . $slug . '.html', $articleHtml) === false) {
            admin_flash_set("Impossible d'ecrire le fichier de l'article.", true); admin_redirect('blog.php');
        }

        // Carte dans la liste du blog
        if (is_file($BLOG_IDX) && is_writable($BLOG_IDX)) {
            $card = build_card($slug, $title, $tag, $excerpt, $cover, $dateFr, $readMin);
            $html = file_get_contents($BLOG_IDX);
            $needle = '<div class="blog-list">';
            $pos = strpos($html, $needle);
            if ($pos !== false) {
                $at = $pos + strlen($needle);
                $html = substr($html, 0, $at) . $card . substr($html, $at);
                file_put_contents($BLOG_IDX, $html);
            }
        }

        // Entree sitemap
        if (is_file($SITEMAP) && is_writable($SITEMAP)) {
            $xml = file_get_contents($SITEMAP);
            $entry = "\n  <url>\n    <loc>https://finalyn.ch/ia/blog/" . $slug . ".html</loc>\n    <lastmod>" . $dateIso . "</lastmod>\n    <changefreq>monthly</changefreq>\n    <priority>0.8</priority>\n  </url>";
            if (strpos($xml, '<!-- Blog -->') !== false) {
                $xml = str_replace('<!-- Blog -->', '<!-- Blog -->' . $entry, $xml);
            } else {
                $xml = str_replace('</urlset>', $entry . "\n\n</urlset>", $xml);
            }
            file_put_contents($SITEMAP, $xml);
        }

        $pdo->prepare('INSERT INTO posts (slug, title, tag, excerpt, cover, read_min, body, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$slug, $title, $tag, $excerpt, $cover, $readMin, $body, gmdate('Y-m-d H:i:s')]);
        admin_flash_set('Article publie : /blog/' . $slug . '.html');
        admin_redirect('blog.php');
    }

    if ($a === 'edit_post' && !empty($_POST['id'])) {
        $row = $pdo->prepare('SELECT * FROM posts WHERE id=?'); $row->execute([(int)$_POST['id']]); $row = $row->fetch(PDO::FETCH_ASSOC);
        if (!$row) { admin_flash_set('Article introuvable.', true); admin_redirect('blog.php'); }
        $slug = $row['slug']; // l'adresse ne change pas (stabilite SEO)
        $title = trim($_POST['title'] ?? '');
        $tag = trim($_POST['tag'] ?? 'Article');
        $excerpt = trim($_POST['excerpt'] ?? '');
        $cover = trim($_POST['cover'] ?? '');
        $readMin = max(1, (int)($_POST['read_min'] ?? 5));
        $body = (string)($_POST['body'] ?? '');

        $err = '';
        if ($title === '' || $excerpt === '' || trim($body) === '') $err = 'Titre, resume et contenu sont obligatoires.';
        elseif ($cover !== '' && !preg_match('#^https?://#', $cover)) $err = "L'image de couverture doit etre une URL (http...).";
        elseif (!is_dir($BLOG_DIR) || !is_writable($BLOG_DIR)) $err = "Le dossier blog/ n'est pas accessible en ecriture.";
        if ($err) { admin_flash_set($err, true); admin_redirect('blog.php?edit=' . (int)$row['id']); }

        $dateIso = substr($row['created_at'], 0, 10);
        $dts = strtotime($dateIso);
        $dateFr = ((int)date('j', $dts)) . ' ' . $frMonths[(int)date('n', $dts)] . ' ' . date('Y', $dts);
        $articleHtml = build_article($slug, $title, $tag, $excerpt, $cover, $readMin, $dateIso, $dateFr, md_to_html($body));

        if (file_put_contents($BLOG_DIR . '/' . $slug . '.html', $articleHtml) === false) {
            admin_flash_set("Impossible de reecrire le fichier.", true); admin_redirect('blog.php?edit=' . (int)$row['id']);
        }
        // Remplace la carte dans la liste
        if (is_file($BLOG_IDX) && is_writable($BLOG_IDX)) {
            $html = file_get_contents($BLOG_IDX);
            $newCard = build_card($slug, $title, $tag, $excerpt, $cover, $dateFr, $readMin);
            $html = preg_replace('#\s*<a href="' . preg_quote($slug, '#') . '\.html" class="blog-card">.*?</a>#s', $newCard, $html, 1);
            file_put_contents($BLOG_IDX, $html);
        }
        // Met a jour lastmod dans le sitemap
        if (is_file($SITEMAP) && is_writable($SITEMAP)) {
            $xml = file_get_contents($SITEMAP);
            $xml = preg_replace(
                '#(<loc>https://ia\.finalyn\.ch/blog/' . preg_quote($slug, '#') . '\.html</loc>\s*<lastmod>)[^<]*(</lastmod>)#',
                '${1}' . gmdate('Y-m-d') . '${2}', $xml, 1
            );
            file_put_contents($SITEMAP, $xml);
        }
        $pdo->prepare('UPDATE posts SET title=?, tag=?, excerpt=?, cover=?, read_min=?, body=?, updated_at=? WHERE id=?')
            ->execute([$title, $tag, $excerpt, $cover, $readMin, $body, gmdate('Y-m-d H:i:s'), (int)$row['id']]);
        admin_flash_set('Article mis a jour : /blog/' . $slug . '.html');
        admin_redirect('blog.php');
    }
}

$posts = $pdo->query('SELECT * FROM posts ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

$editing = null;
if (isset($_GET['edit'])) {
    $e = $pdo->prepare('SELECT * FROM posts WHERE id=?'); $e->execute([(int)$_GET['edit']]);
    $editing = $e->fetch(PDO::FETCH_ASSOC) ?: null;
}
$fTitle   = $editing['title'] ?? '';
$fTag     = $editing['tag'] ?? 'Article';
$fRead    = $editing['read_min'] ?? 5;
$fCover   = $editing['cover'] ?? '';
$fExcerpt = $editing['excerpt'] ?? '';
$fBody    = $editing['body'] ?? '';

admin_header('blog', 'Blog');
flash_render();
?>
<div class="adm-block card" id="form">
  <h2><?= $editing ? "Modifier l'article" : 'Nouvel article' ?></h2>
  <form method="post" class="adm-form" style="margin-top:1rem">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="<?= $editing ? 'edit_post' : 'create_post' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <div>
      <label for="title">Titre</label>
      <input type="text" id="title" name="title" value="<?= h($fTitle) ?>" required>
    </div>
    <div class="adm-row2">
      <div>
        <label for="tag">Categorie</label>
        <input type="text" id="tag" name="tag" value="<?= h($fTag) ?>" placeholder="Stratégie, Technique...">
      </div>
      <div>
        <label for="read_min">Temps de lecture (min)</label>
        <input type="number" id="read_min" name="read_min" min="1" value="<?= (int)$fRead ?>">
      </div>
    </div>
    <?php if ($editing): ?>
      <div>
        <label>Adresse</label>
        <input type="text" value="/blog/<?= h($editing['slug']) ?>.html" disabled>
        <p class="field-help">L'adresse d'un article publie ne change pas (stabilite SEO).</p>
      </div>
    <?php else: ?>
      <div>
        <label for="slug">Adresse (laisser vide = auto depuis le titre)</label>
        <input type="text" id="slug" name="slug" placeholder="mon-article">
        <p class="field-help">Donnera /blog/mon-article.html. Lettres minuscules, chiffres et tirets uniquement.</p>
      </div>
    <?php endif; ?>
    <div>
      <label for="cover">Image de couverture (URL, optionnel)</label>
      <input type="text" id="cover" name="cover" value="<?= h($fCover) ?>" placeholder="https://images.unsplash.com/...">
    </div>
    <div>
      <label for="excerpt">Résumé (affiché dans la liste et le SEO)</label>
      <textarea id="excerpt" name="excerpt" style="min-height:80px" required><?= h($fExcerpt) ?></textarea>
    </div>
    <div>
      <label for="body">Contenu de l'article</label>
      <textarea id="body" name="body" required placeholder="Ecrivez ici. Mise en forme simple :
## Titre de section
### Sous-titre
- point de liste
Un paragraphe normal, separe par une ligne vide."><?= h($fBody) ?></textarea>
      <p class="field-help">Mise en forme : <code>## </code> titre, <code>### </code> sous-titre, <code>- </code> liste, ligne vide = nouveau paragraphe.</p>
    </div>
    <div>
      <button class="btn dark" type="submit"><?= $editing ? 'Enregistrer les modifications' : "Publier l'article" ?></button>
      <?php if ($editing): ?><a class="btn" href="blog.php">Annuler</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="adm-block">
  <h2>Articles publies depuis le back-office</h2>
  <?php if (!$posts): ?>
    <p class="adm-empty">Aucun article cree ici pour l'instant. (Tes articles existants restent en place, ils ne sont pas geres depuis cet ecran.)</p>
  <?php else: ?>
    <div class="post-list">
      <?php foreach ($posts as $p): ?>
        <div class="post-item">
          <div class="post-thumb" style="<?= $p['cover'] ? "background-image:url('" . h($p['cover']) . "')" : '' ?>"></div>
          <div class="post-meta">
            <h4><?= h($p['title']) ?></h4>
            <div class="pm"><?= h($p['tag']) ?> · <?= fr_d($p['created_at']) ?> · <a href="../blog/<?= h($p['slug']) ?>.html" target="_blank" rel="noopener">/blog/<?= h($p['slug']) ?>.html</a></div>
          </div>
          <div class="adm-actions">
            <a class="btn" href="blog.php?edit=<?= (int)$p['id'] ?>#form">Modifier</a>
            <form method="post" onsubmit="return confirm('Supprimer cet article (fichier + liste + sitemap) ?');">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_post"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn danger" type="submit">Supprimer</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
admin_footer();
