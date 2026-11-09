# JumPHP runtime : protocole et modèle de sécurité (spécification)

Le **JumPHP runtime** est un petit programme qui tourne sur l'ordinateur de la personne (icône dans la zone de notification, démarré avec
la session) et qui permet aux **applications JumPHP qui tournent dans le navigateur** (jumphp.net, `play.jumphp.net`) de se connecter en
TCP / UDP / ICMP / SSH **depuis l'adresse IP et le réseau de la personne** (réseau local, VPN, serveur qui n'ouvre qu'à son adresse), avec son
**autorisation**. Sans lui, ces connexions passent par le pont TCP du serveur jumphp.net (`docker/tcpbridge` du playground), qui ne voit que
ce que le serveur voit. Ce document est la référence commune du démon (`apps/runtime`), du client du moteur (`extensions/runtime`) et des docs.

## Vue d'ensemble

```
page de l'appli (https://play.jumphp.net/<slug>/play/)
   │ 1. demande un TICKET à jumphp.net (POST /api/tcp/ticket) : l'administrateur a approuvé cette appli, ce commit, ce droit
   │ 2. ouvre ws://127.0.0.1:47820/v1 (le runtime) et envoie  create {meta:{tcp_ticket}}
   ▼
JumPHP runtime (127.0.0.1) ── vérifie origine, ticket, autorisation de la personne ──► TCP vers host:port, depuis ce PC
```

Le ticket reste celui du serveur : **les deux** portes doivent être ouvertes (l'administrateur de jumphp.net a approuvé l'application pour cette
version exacte du code, ET la personne a autorisé l'application sur son ordinateur). Le runtime ne parle jamais à jumphp.net sauf pour lire la
clé publique de la plateforme (`GET https://jumphp.net/api/tcp/public-key`, mise en cache) et chercher ses mises à jour.

## Point d'entrée

- `ws://127.0.0.1:47820/v1` (et `ws://[::1]:47820/v1`). Jamais une autre interface. Port par défaut 47820 ; s'il est pris, 47821 à 47829 (le client
  les essaie dans l'ordre, 400 ms chacun). Réglage `~/.config/jumphp-runtime/config.json` (`port`, `origins`).
- Protocole **identique à celui du pont du serveur** (`docker/tcpbridge/bridge.php` du playground, `php-wasm-network.js`) : `hello` à l'ouverture,
  `create` avec `meta.tcp_ticket`, `room` quand la cible est jointe, trames binaires `[to] octets` dans les deux sens, `ping` / `pong`, `peer-leave`,
  `error {code, message}`, et le mode brut (`?raw=1` : les trames binaires sont les octets, pour le client SSH WebAssembly). Les clients du
  moteur (`TcpConnection`, `SshConnection`, `UdpConnection`, `Icmp`) n'ont donc que l'adresse du pont à changer.

## Sécurité (le démon n'accepte pas n'importe qui)

1. **Interface** : liaison à 127.0.0.1 / ::1 uniquement.
2. **Hôte** : l'en-tête `Host` doit être `127.0.0.1:<port>`, `[::1]:<port>` ou `localhost:<port>` (contre le DNS rebinding) ; sinon 421.
3. **Origine** : l'en-tête `Origin` est obligatoire et doit être dans la liste blanche : `https://jumphp.net`, `https://play.jumphp.net` par défaut ;
   la personne peut en ajouter (un site JumPHP à elle) dans la fenêtre du runtime, jamais `*`. Sans origine (un programme local) ou origine inconnue : 403,
   rien n'est exécuté, la tentative est journalisée (visible dans la fenêtre).
4. **Ticket** : comme le pont du serveur, `create` exige un ticket valide : signature Ed25519 de l'appli + signature de la plateforme de
   « slug|commit|clé publique » (clé de la plateforme lue sur `/api/tcp/public-key`), non expiré (120 s). Un ticket dit l'appli, la cible (hôte, port),
   le droit (`tcp`, `ssh`, `udp`, `icmp`). Le ticket ne dit pas si la personne est d'accord : c'est l'étape suivante.
5. **Autorisation de la personne** (boîte de dialogue native du système, jamais dans la page) :
   - première fois qu'une **origine + appli** demande : « Le site play.jumphp.net veut utiliser votre réseau pour l'application *jumphpsql*. Autoriser ? »
     (Autoriser toujours / Cette fois / Refuser) ;
   - première fois qu'une appli autorisée vise une **adresse privée ou locale** (10.x, 172.16-31.x, 192.168.x, 127.x, ::1, fc00::/7, fe80::/10, noms qui
     y résolvent) ou un **port qui n'est pas celui du droit** : une confirmation par cible (« jumphpsql veut joindre 192.168.1.10:3306 »).
   - Les autorisations « toujours » sont gardées dans `grants.json` (origine, slug, droits, cibles), **révocables** une par une ou toutes d'un coup
     (fenêtre du runtime, menu de l'icône). Un refus est gardé 10 minutes (pas d'harcèlement par boîtes de dialogue).
   - Pas de boîte ouverte plus d'une à la fois ; 30 s sans réponse = refus.
6. **Limites** : 32 connexions ouvertes, 10 créations par minute et par origine, 1 h de vie maximale par connexion, 15 min sans trafic ; frames de
   64 Ko. Le trafic n'est jamais enregistré, seuls l'origine, l'appli, la cible, l'heure, le volume le sont (journal tournant, fenêtre du runtime).
7. **Pas d'autre fonction** : aucune commande locale, aucun accès aux fichiers, aucune écoute (le démon ne fait que connecter vers l'extérieur, jamais
   `bind` pour les pages). Les mises à jour sont signées (comme celles de l'IDE) et jamais automatiques sans clé valide.
8. **Navigateurs** : Chrome / Edge / Firefox acceptent `ws://127.0.0.1` depuis une page https (adresse « potentiellement fiable ») ; Chrome peut
   demander à la personne l'autorisation « Accéder aux appareils du réseau local » (Local Network Access) la première fois : le guide du moteur
   l'explique. Safari bloque ce contenu mixte : le client retombe sur le pont du serveur (voir ci-dessous).

## Messages propres au runtime (en plus du protocole du pont)

| Sens | Message | Rôle |
|---|---|---|
| runtime → page | `{"t":"hello","v":1,"runtime":"0.1.0","os":"linux","granted":false}` | à l'ouverture : version, système, l'origine est-elle déjà autorisée pour au moins une appli |
| page → runtime | `{"t":"pair","app":"jumphpsql","title":"jumphpsql","scope":"tcp"}` | demande l'autorisation de la personne pour cette appli (dialogue natif) sans ouvrir de connexion |
| runtime → page | `{"t":"paired","app":"jumphpsql","granted":true,"always":true}` | la réponse (refus : `granted:false`) |
| page → runtime | `{"t":"status"}` → `{"t":"status","connections":2,"granted":["jumphpsql"]}` | pour le voyant du client |
| runtime → page | `{"t":"error","code":"origin|ticket|denied|limit|target|blocked","message":"…"}` | puis la socket est fermée |

## Côté moteur (client)

- Extension `extensions/runtime` (package `jumphp/runtime`, `$engine->runtime()`), classe `JumPHP\Runtime\RuntimeClient` :
  `state()` = `unknown` | `checking` | `missing` (rien n'écoute : pas installé ou pas lancé) | `running` (répond, pas encore autorisé) | `ready`
  (autorisé pour cette appli) | `refused` | `unsupported` (navigateur qui bloque) ; `check()` (Task), `pair()`, événement `change`.
- Mode réseau : réglage `JUMPHP_NET_MODE` / `project.yaml net_mode` = `auto` (défaut : le runtime s'il est `ready`, sinon le pont du serveur) | `server` |
  `local` (le runtime ou une erreur claire). `TcpConnection`, `SshConnection`, `UdpConnection`, `Icmp` demandent le ticket au serveur comme
  aujourd'hui, puis ouvrent `bridge` (adresse du serveur) **ou** `ws://127.0.0.1:47820/v1` selon le mode.
- Widget `RuntimeBadge` (JumPHP\Gui) : voyant **vert** (le runtime est installé et autorisé : « Réseau local actif »), **orange** (installé, il faut
  autoriser), **rouge** (pas installé / pas lancé : cliquer guide la personne : télécharger pour son système, installer, lancer, autoriser) et **gris**
  (le pont du serveur suffit). Le guide pointe vers `https://jumphp.net/runtime` et les releases de `git.jumphp.net/jumphp/jumPhp` (`runtime-v<version>`).
- Rien de tout ça n'existe hors du navigateur : sur le bureau l'appli a déjà son réseau (`Platform::isWasm()` faux, `RuntimeClient` toujours `unsupported`).

## Fichiers du démon

`~/.config/jumphp-runtime/` (Windows : `%APPDATA%\jumphp-runtime\`) : `config.json` (port, origines ajoutées, démarrage avec la session),
`grants.json` (autorisations), `log.jsonl` (journal tournant, 2 Mo). Aucun secret, aucune clé privée de la personne.
