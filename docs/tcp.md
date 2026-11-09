# TCP depuis le navigateur (pont TCP)

Un navigateur ne sait pas ouvrir de socket TCP. Une application (un client SQL...) qui en a besoin utilise `$this->engine->tcp()` :
sur le bureau c'est un socket normal ; dans le navigateur c'est le **pont TCP** du playground (WebSocket vers TCP).

```php
$c = $this->engine->tcp()->connect('db.example.com', 3306);
$c->on('open', fn () => ...);
$c->on('data', fn (string $octets) => ...);
$c->on('close', fn (?string $raison) => ...);
$c->on('error', fn (string $message) => ...);   // refus, port interdit, pas d'autorisation...
$c->write($octets);
```

## Qui a le droit

- Le propriétaire de l'application demande l'accès sur la page du projet (studio, « Accès TCP »).
- L'administrateur (le compte Git `davidnurdin`) autorise ou refuse dans ses notifications / `Administration`. Il voit aussi tous les projets.
- L'autorisation vaut pour **un commit précis** : une application modifiée doit être autorisée de nouveau.
- Chaque application a une paire de clés Ed25519. La clé privée est gardée chiffrée sur le compte du propriétaire et signe les tickets ;
  la plateforme signe « slug | commit | clé publique » à l'autorisation. Le pont ne fait confiance qu'à la clé publique de la plateforme
  (`/api/tcp/public-key`) : il vérifie les deux signatures.
- Le ticket (2 minutes, une connexion) nomme l'hôte et le port. Ports permis : bases de données (3306, 3307, 33060, 5432, 1433, 1521, 6379, 27017, 11211, 8086, 9042).
  Adresses privées / réservées refusées après résolution du nom. Limites par adresse IP.

Le pont : `docker/tcpbridge` du dépôt jumphp-playground (service `tcpbridge`, réseau à part, route `/tcp` du Caddy).
`project.yaml` : `tcp_ticket_url` (défaut : l'origine de la page dans le navigateur, `https://jumphp.net/api/tcp/ticket` ailleurs ; la variable d'environnement `JUMPHP_TCP_URL` l'emporte).

## À sauvegarder (administrateur du site)

- **Le droit d'administrateur ne se perd pas avec un fichier** : il est lié à ton compte GitHub (`User::ADMIN_GITHUB_ID` = 50957749, l'id,
  pas le login : un login renommé et repris par un autre ne donne rien). Garde l'accès à ce compte GitHub (2FA, codes de secours).
- **`TOKEN_KEY` (`deploy/.env` du dossier du projet sur le serveur) : à sauvegarder absolument, hors du serveur.** Elle sert à
  chiffrer les jetons Git et les clés privées des applications, et la clé de signature de la plateforme en est dérivée. Perdue ou changée :
  les jetons ne se déchiffrent plus (tout le monde doit se reconnecter), les clés des applications sont perdues et toutes les autorisations TCP
  deviennent invalides (à redemander et réautoriser).
- **La base de données** (`db`, volume `db`, table `tcp_grant` : état des demandes, clés publiques, clés privées chiffrées) : sauvegarde
  régulière (`mysqldump`). Sans elle, les autorisations sont à refaire.
- Le reste (`APP_SECRET`, `GITHUB_CLIENT_SECRET`, `DB_PASSWORD`...) est dans le même `deploy/.env`: sauvegarde-le en entier, jamais dans git.

## Statistiques, coupure, serveur indépendant

- **Administration** (`/studio/admin`, section « Pont TCP ») : connexions ouvertes (application, cible, adresse, durée, octets, débit),
  totaux depuis le démarrage (connexions, refus, coupures, ↑ ↓), totaux par application et par adresse, journal. Boutons :
  **Couper** une connexion, **Bloquer** l'application ou l'adresse pour une heure, **Débloquer**. Les chiffres sont gardés en mémoire
  par le pont (un redémarrage repart de zéro). Un blocage définitif = refuser l'autorisation de l'application (plus de tickets).
- Le pont tient ces chiffres et répond à `/admin/stats|kill|block|unblock` (en-tête `X-Admin-Secret`, secret partagé
  `TCP_ADMIN_SECRET` dans `deploy/.env`). Caddy ne transmet au pont que `/tcp` : l'administration n'est pas publique.
- **Serveur indépendant** : le pont est un seul fichier PHP (`docker/tcpbridge/` du dépôt jumphp-playground, sans dépendance). Sur un autre
  serveur : `docker compose up -d --build` avec `docker/tcpbridge/compose.yaml` (le pont + Caddy pour le certificat `wss://`),
  `.env` : `BRIDGE_HOST`, `PLAYGROUND_URL`, `TCP_ADMIN_SECRET`. Sur le playground (`deploy/.env`) : `TCP_BRIDGE_PUBLIC_URL=wss://<hôte>/tcp`
  (l'adresse que les navigateurs reçoivent avec leur ticket), `TCP_BRIDGE_ADMIN_URL=https://<hôte>`, et le même `TCP_ADMIN_SECRET`.
  Le pont ne fait confiance qu'à la clé publique du playground (`/api/tcp/public-key`) : il n'a aucun secret. Plusieurs ponts possibles
  (un par région) avec le même `PLAYGROUND_URL`.
- À faire (TODO.md) : encapsuler aussi UDP et ICMP.

## Blocages durables, quota, alertes

- Les blocages et les totaux du pont survivent à un redémarrage (`TCP_STATE_FILE`, un volume : `/data/state.json`).
- **Quota de bande passante** par application : `TCP_QUOTA_MB_PER_HOUR` (2048 Mo par heure par défaut sur le playground, 0 = aucun). Dépassé :
  l'application est bloquée 15 minutes et l'administrateur est prévenu : le pont appelle `TCP_ALERT_URL` (`/api/tcp/alert` du playground, avec le
  secret partagé), la cloche s'allume et la page Administration liste les alertes.

## Administration en direct

La section « Pont TCP » de l'Administration se rafraîchit toute seule (2 secondes, sauf onglet caché ou champ en cours de saisie) :
connexions ouvertes, débit, demandes de connexion (**l'application qui demande**, la cible, la réponse : ticket donné ou refusé et pourquoi),
journal du pont, le plus récent en haut (chaque ligne nomme l'application, même pour un refus). UDP et ICMP y figurent avec leur portée. Un **historique par jour** (connexions, refusées, coupées, octets envoyés et reçus ; 60 jours, gardés dans `TCP_STATE_FILE`) est affiché sous les totaux.

## UDP et ICMP (pings)

Quatre droits, chacun demandé par le propriétaire et autorisé par l'administrateur pour un commit précis : **tcp** (ports de bases de données), **ssh** (le client SSH en WebAssembly), **udp**, **icmp**.

- `$this->engine->udp()->open($hôte, $port)` : les événements de `tcp()`, mais chaque `data` est **un** datagramme et chaque `write()` en envoie un (DNS, NTP, serveurs de jeu). Le pont : une trame WebSocket = un datagramme,
  n'importe quelle adresse publique et n'importe quel port, 300 datagrammes par seconde au plus (au-delà : la connexion est coupée et l'administrateur est alerté).
- `$this->engine->icmp()->ping($hôte, 4, 64)` : une Task de réponses `['seq' => 1, 'ok' => true, 'ms' => 12.3, 'ttl' => 57, 'from' => '…']`, ou `['ok' => false, 'error' => 'ttl', 'hop' => '10.0.0.1']` quand un routeur répond
  « time to live exceeded » (un traceroute demande avec des TTL 1, 2, 3…). Le pont envoie jusqu'à 4 pings à la fois, 5 par seconde, 200 par connexion. Deux façons, choisies tout seul : en **socket brut** (extension `sockets` de PHP, l'image du pont l'a) quand le processus a le droit d'en ouvrir un
  (root avec `NET_RAW` ; mesure du temps à la lecture, sans programme externe) et sinon le `ping` d'iputils de la machine (`ICMP_MODE=ping` force cette voie). **Le pont de jumphp.net tourne en root avec la seule capacité `NET_RAW`** (choix de l'administrateur, 2026-11-08), donc en socket brut : le routeur d'un « time exceeded » est connu.
  **Pourquoi root ?** Un socket brut exige `CAP_NET_RAW` *effective* ; Docker donne `cap_add: [NET_RAW]` au jeu de capacités du conteneur mais un processus non-root ne la garde pas à l'`exec`, et `no-new-privileges` interdit les capacités de fichier. Les « ping sockets » sans droit (`SOCK_DGRAM` + `IPPROTO_ICMP`) répondent aux echo, mais PHP ne sait pas lire la file d'erreurs du noyau (`IP_RECVERR`) qui seule donne le routeur.
  **Réglage de sécurité** : `user` root dans l'image mais `cap_drop: [ALL]` + `cap_add: [NET_RAW]`, `no-new-privileges:true`, système de fichiers en lecture seule, `pids_limit`, `mem_limit`, réseau `tcp` à part, sans secret ni base. Le pont n'exécute **aucun code d'utilisateur** : il relaie des octets d'après un ticket signé. **Risque résiduel** : une faille d'exécution dans le pont donnerait uid 0 dans le conteneur, avec `NET_RAW` (forger des paquets IP/ICMP, sniffer le trafic du réseau `tcp` du conteneur) mais sans pouvoir écrire hors de `/data`, monter, charger de module ni gagner de capacité. Les fichiers de `/data` appartiennent à root (sans `DAC_OVERRIDE`, root ne peut pas écrire un fichier d'un autre propriétaire : un volume créé par l'ancienne image `nobody` demande un `chown -R 0 /data` une fois). Les deux voies donnent les mêmes réponses (`ok`, `ms`, `ttl`, `from`, ou `error` : `ttl`, `unreachable`, `no answer`, avec `hop` et `ms`).
- `$this->engine->icmp()->trace($hôte, 30)` : un **traceroute** en une Task de sauts `['hop' => 1, 'ip' => '192.168.1.1', 'ms' => 0.4, 'reached' => false]` (TTL 1, 2, 3… ; `'ip' => null` pour un routeur muet ; fini quand l'hôte répond, ou après 5 sauts muets d'affilée).
  Une seule connexion (un seul ticket) en mode navigateur ; sur l'ordinateur, le `ping` du système (`-n`, jamais de résolution de noms).
- **IPv6** : un nom est résolu en IPv4 d'abord, puis en IPv6 (AAAA) ; une adresse `2606:4700:4700::1111` marche telle quelle pour `tcp()`, `udp()`, `icmp()->ping()` / `trace()` (le pont lance `ping -6`, ou un socket ICMPv6). Les adresses privées, locales au lien, uniques locales, de documentation et
  les formes IPv6 qui cachent une IPv4 (`::ffff:a.b.c.d`, NAT64, 6to4, Teredo) sont refusées. Il faut que le réseau du pont ait de l'IPv6 pour en sortir (Docker : `enable_ipv6` sur son réseau).
- **Alertes** (quota dépassé, inondation UDP) : le journal, la cloche du playground (`TCP_ALERT_URL`) et, si `TCP_ALERT_WEBHOOK` est défini sur le pont, un POST JSON `{"text": "…", "content": "…", "source": "jumphp-tcpbridge", "at": "2026-11-08T12:00:00+00:00"}` vers cette adresse
  (Slack, Mattermost, Discord et ntfy lisent `text` / `content` ; pour un courriel, pointez un petit script ou un service qui en envoie un). Pas de client SMTP dans le pont : une dépendance et un mot de passe de plus pour un service sans état.
- Les quotas de bande passante par heure : par application (`TCP_QUOTA_MB_PER_HOUR`), par adresse (`TCP_QUOTA_IP_MB_PER_HOUR`) et par compte, c'est-à-dire le propriétaire des applications (`TCP_QUOTA_ACCOUNT_MB_PER_HOUR`) :
  dépassé, l'application / l'adresse / le compte est bloqué 15 minutes et l'administrateur est prévenu. L'administration peut aussi bloquer un compte.

## Utiliser le réseau de l'utilisateur : le JumPHP runtime

Le pont du serveur ne voit que ce que le serveur voit. Pour joindre une base du réseau local, derrière un VPN, ou un serveur qui n'ouvre qu'à
l'adresse de la personne, l'application peut passer par le **JumPHP runtime**, un petit programme sur l'ordinateur de la personne
(`docs/runtime.md`, protocole : `docs/runtime-protocol.md`). Le réglage `net_mode` de project.yaml (ou `JUMPHP_NET_MODE`, page ou
environnement) choisit : `auto` (défaut : le runtime quand il est installé et que la personne a autorisé l'appli, sinon le pont du serveur),
`server` (toujours le serveur), `local` (le runtime, ou l'erreur « the JumPHP runtime is not available (state: …) »). Le ticket est toujours demandé au
serveur (l'administrateur doit avoir approuvé l'appli) : seule l'adresse ouverte ensuite change. Il faut l'extension `jumphp/runtime`
(sans elle, le serveur est toujours utilisé).

## Les crashs du moteur

Quand le moteur du navigateur s'arrête (« The engine crashed: … »), la page envoie un **rapport** à jumphp.net (`/api/crash`) : l'application, la version, l'adresse, la trace et le **journal** des 150 dernières lignes (sorties de PHP,
erreurs de la page). L'administrateur les lit dans Administration → Rapports de crash (détail : trace + journal) et par le MCP (`list_crash_reports`, `get_crash_report`, `delete_crash_report`) pour reprendre plus tard.
Exemple de cause trouvée ainsi : « missing function: getcontext » = une `Fiber` dans le navigateur (PHP en WebAssembly n'en a pas : utilisez des générateurs).

## Exemples pas à pas

- `docs/tuto-tcp.md` : `apps/hello-tcp`, un PING à un Redis par une connexion TCP.
- `docs/tuto-ssh.md` : `apps/hello-ssh`, la même chose à travers un serveur SSH (sélecteur de clé, trousseau).
