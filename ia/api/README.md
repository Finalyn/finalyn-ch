# Assistant finalyn.ia · proxy Claude (Haiku)

Le chatbot du site parle à Claude **Haiku** via ce petit proxy PHP. La clef API
reste **côté serveur** : elle n'apparaît jamais dans le navigateur.

```
Navigateur (chat)  ->  POST /api/chat.php  ->  API Claude (Anthropic)
                         (détient la clef,        claude-haiku-4-5
                          garde-fous, prompt)
```

## Mise en route (Infomaniak ou tout hébergement PHP 8+)

1. **Récupérer une clef API** sur console.anthropic.com (commence par `sk-ant-...`).
2. **Déposer la clef**, au choix :
   - copier `config.example.php` en `config.php` et y coller la clef ; **ou**
   - définir la variable d'environnement `ANTHROPIC_API_KEY` (recommandé si dispo).
3. **Mettre les fichiers en ligne** : tout le dossier `api/` doit être servi par PHP.
   Sur Infomaniak, PHP est actif par défaut.
4. **Tester** : ouvrir le site, cliquer sur la bulle de chat, poser une question.

> `config.php` et `api/.cache/` sont dans `.gitignore` : ils ne sont jamais
> committés. Ne partagez jamais la clef.

## Important

- **Ne fonctionne pas avec `python -m http.server`** (Python n'exécute pas le PHP).
  En local, il faut `php -S localhost:8000` à la racine du site, ou tester
  directement sur l'hébergement.
- Prérequis : **PHP 8.0+** (utilise `str_ends_with`) avec l'extension `curl`.

## Garde-fous déjà en place (dans `chat.php`)

- **Clef jamais exposée** au navigateur.
- **Jamais de tarif** et **jamais de tiret cadratin** (règles éditoriales, dans le prompt système).
- **Limitation de débit** : 25 messages / 10 min par IP.
- **Longueur** : 1000 caractères max par message, 12 messages d'historique max.
- **Réponses courtes** : `max_tokens = 600`.
- **Origine** : seules les requêtes depuis finalyn.com / finalyn.ch (et localhost) sont acceptées.
- **Sujet** : l'assistant reste sur finalyn.ia, l'IA/automatisation et la prise de rendez-vous ; il invite à l'audit gratuit.
- **Confidentialité** : ne demande pas de données sensibles ; mention ajoutée dans la politique de confidentialité.

## Réglages rapides

Tout est en haut de `chat.php` (constantes `FINALYN_*`) : modèle, longueur,
limites de débit. Le **prompt système** (identité, faits, garde-fous) est le
bloc `$system` dans le même fichier.

## Back-office (dossier `admin/`)

Tableau de bord relié à la même base SQLite (`api/.data/finalyn.sqlite`) :
réservations, 30 dernières conversations, stats site & blog.

- **Accès** : `https://finalyn.ch/ia/admin/` puis connexion.
- **Mot de passe** : champ `admin_password` (ou `admin_password_hash`) dans `api/config.php`.
- **E-mail des réservations** : champ `notify_email` dans `api/config.php`.

Modules :
- **Réservations** : le calendrier du site enregistre les RDV (via `api/book.php`) et envoie
  **deux e-mails** : une notification à `notify_email` (vous) et une **confirmation au client**.
  L'expéditeur est `from_email` (doit appartenir à votre domaine). L'admin peut annuler/marquer
  fait + bloquer des jours/créneaux.
  Les jours bloqués/complets sont grisés sur le calendrier public (via `api/availability.php`).
- **Conversations** : chaque échange du chat est journalisé (`api/chat.php` -> base) et consultable.
- **Stats** : pages vues (traceur `api/track.php` déclenché par `assets/consent.js`,
  uniquement si le visiteur accepte la mesure d'audience), top pages et top articles de blog.

Tout cela nécessite **PHP 8+ avec SQLite (PDO)**, standard sur Infomaniak. Le dossier
`api/.data/` (la base) et `api/config.php` ne sont jamais committés.
