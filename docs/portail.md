# Les portails de jeux et le playground

**Le playground JumPHP** ([jumphp.net](https://jumphp.net), code : https://git.jumphp.net/jumphp/jumphp-playground) est un portail complet : annuaire
par catégorie, une page par jeu (son site `games/<id>/site/`), les jeux joués en mode distant
([remote.md](remote.md) : le moteur partagé téléchargé une fois, les fichiers du jeu un par un), les sources
navigables, un éditeur en ligne (brouillons, aperçu, push Git, fork). On s'y connecte avec Git ; un
projet est un dépôt Git public de l'utilisateur, publié à chaque push. Son serveur MCP laisse le Claude
local de l'utilisateur créer et publier un jeu (AGENTS.md §14).

Un portail plus simple peut aussi regrouper des builds classiques, comme ci-dessous.


Chaque build navigateur est un dossier statique autonome (`games/<jeu>/dist/web/`) avec un manifeste
`game.json` pour qu'un site puisse regrouper les jeux :

```json
{
  "id": "pong", "title": "PONG", "version": "1.0.0", "description": "...",
  "width": 320, "height": 180, "online": true, "lang": "en",
  "net_url": "ws://localhost:8090/ws", "touch": {"a": "Return", "b": "Escape", "start": "Return"},
  "thumbnail": "thumbnail.png", "controls": "Player 1: W / S...",
  "engine": {"name": "JumPHP", "php": "8.3", "network": "<commit>"}, "built": "2026-10-27T16:34:00+00:00"
}
```

`thumbnail.png` / `icon.png` viennent de `assets/` du jeu s'ils existent.

## Intégration

- **Hébergement** : `bin/jumphp deploy <jeu>` vers `<racine>/<id>/` ; le portail lit `<id>/game.json`.
- **iframe** : `<iframe src="/jeux/pong/?net=wss://domaine/ws&lang=fr&back=https://portail/">`.
  Paramètres d'URL : `net`, `room` (rejoindre une salle), `lang`, `debug`, `back` (où va « quitter »).
- **Messages vers le parent** (`window.parent.postMessage`) : `{type: 'jumphp:ready', game}` (le jeu
  tourne), `{type: 'jumphp:quit', game}`, `{type: 'jumphp:bridge', game, message}` (messages personnalisés
  du jeu : `$engine->bridge->send('score', ['value' => 1200])`).
- Le moteur (`php-web.wasm`) est identique pour tous les jeux : un portail peut le servir une fois, avec
  un cache long (son empreinte est dans `version.json`).
- Salons en ligne : le relais expose `GET /health?rooms` (salles publiques) pour afficher « qui joue ».
