/* Bandeau de consentement cookies / tracking, finalyn.ia (nLPD + RGPD)
   Aucun cookie de mesure/tracking n'est posé tant que l'utilisateur n'a pas accepté.
   Le choix est mémorisé dans localStorage et exposé via window.finalynConsent
   ('all' = mesure acceptée, 'essential' = strictement nécessaire uniquement). */
(function () {
  var KEY = 'finalyn-consent';
  var saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) {}
  window.finalynConsent = saved;

  // Mesure d'audience maison (anonyme, sans cookie tiers), uniquement si accepté.
  var tracked = false;
  function trackPageview() {
    if (tracked) return;
    tracked = true;
    try {
      fetch('/ia/api/track.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        keepalive: true,
        body: JSON.stringify({ path: location.pathname, ref: document.referrer || '' })
      }).catch(function () {});
    } catch (e) {}
  }

  function apply(v) {
    window.finalynConsent = v;
    try { document.dispatchEvent(new CustomEvent('finalyn:consent', { detail: v })); } catch (e) {}
    if (v === 'all') trackPageview();
  }

  // Choix déjà fait : on applique et on n'affiche rien.
  if (saved === 'all' || saved === 'essential') { apply(saved); return; }

  var css = ''
    + '.fcc{position:fixed;left:1rem;right:1rem;bottom:1rem;z-index:300;max-width:560px;margin:0 auto;'
    + 'background:#FAF8F4;border:1px solid #DCD6CB;border-radius:16px;padding:1.15rem 1.25rem;'
    + 'box-shadow:0 24px 60px -24px rgba(20,15,30,.4);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;'
    + 'opacity:0;transform:translateY(14px);transition:opacity .35s ease,transform .35s ease;}'
    + '.fcc.show{opacity:1;transform:none;}'
    + '.fcc p{margin:0 0 .9rem;font-size:.88rem;line-height:1.55;color:#2A2A2A;}'
    + '.fcc a{color:#8B5CF6;text-decoration:underline;text-underline-offset:2px;}'
    + '.fcc-row{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;}'
    + '.fcc-btn{font-family:inherit;font-size:.85rem;font-weight:500;cursor:pointer;border-radius:999px;padding:.6rem 1.1rem;border:1px solid transparent;transition:background .2s ease,color .2s ease,border-color .2s ease;}'
    + '.fcc-accept{background:#0E0E0E;color:#fff;}'
    + '.fcc-accept:hover{background:#8B5CF6;}'
    + '.fcc-refuse{background:transparent;color:#2A2A2A;border-color:#DCD6CB;}'
    + '.fcc-refuse:hover{border-color:#8B5CF6;color:#8B5CF6;}'
    + '@media (max-width:540px){.fcc-row{flex-direction:column;align-items:stretch;}.fcc-btn{width:100%;}}';
  var style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  var bar = document.createElement('div');
  bar.className = 'fcc';
  bar.setAttribute('role', 'dialog');
  bar.setAttribute('aria-live', 'polite');
  bar.setAttribute('aria-label', 'Consentement aux cookies');
  bar.innerHTML =
    '<p>On utilise uniquement des cookies nécessaires au fonctionnement du site. '
    + 'Avec votre accord, on pourrait aussi mesurer l’audience de façon anonyme pour l’améliorer. '
    + 'Aucune donnée n’est partagée à des fins publicitaires. '
    + '<a href="/confidentialite.html">En savoir plus</a>.</p>'
    + '<div class="fcc-row">'
    + '<button type="button" class="fcc-btn fcc-accept" data-consent="all">Tout accepter</button>'
    + '<button type="button" class="fcc-btn fcc-refuse" data-consent="essential">Nécessaires uniquement</button>'
    + '</div>';

  function mount() {
    document.body.appendChild(bar);
    requestAnimationFrame(function () { bar.classList.add('show'); });
  }
  if (document.body) mount();
  else document.addEventListener('DOMContentLoaded', mount);

  bar.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-consent]');
    if (!btn) return;
    var v = btn.getAttribute('data-consent');
    try { localStorage.setItem(KEY, v); } catch (e) {}
    apply(v);
    bar.classList.remove('show');
    setTimeout(function () { if (bar.parentNode) bar.parentNode.removeChild(bar); }, 350);
  });
})();
