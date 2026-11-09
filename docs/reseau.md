# Réseau (multijoueur en ligne)

Basé sur [php-wasm-network](https://git.jumphp.net/jumphp/php-wasm-network) : même API en natif
(WebSocket en PHP pur) et dans le navigateur (extension `wasmnet` du moteur + `php-wasm-network.js` :
WebSocket, et WebRTC direct entre navigateurs quand c'est possible). Rien ne bloque la frame (sauf la
toute première connexion sur le bureau, 5 s maximum).

## Serveur

```bash
bin/jumphp relay up      # ws://localhost:8090/ws  (logs : bin/jumphp relay logs)
```

Image `docker/relay` (le serveur `server/bin/server.php` du même commit que le moteur). En production :
le dossier `server/` de php-wasm-network (Caddy TLS + relais + coturn pour WebRTC), puis `net_url:
wss://domaine/ws` dans `project.yaml` ou `?net=wss://domaine/ws` dans l'URL du jeu. Une page en HTTPS exige
`wss://`.

## Côté jeu

1. **Salon** : `LobbyScene` (créer une salle → code de 4 lettres, rejoindre avec un code, partie rapide) ;
   paramètres `next`, `nextParams`, `back`, `players`, `auto` (`quick` / `host` / code). `?room=ABCD` dans
   l'URL rejoint directement la salle.
2. **Partie** : la scène reçoit `['online' => true, 'net' => NetSession, 'host' => bool, 'peer' => int]`.
3. **Messages** : un octet de type + données. JSON pour le rare (`sendJson`), `pack()` pour l'état fréquent
   (`send($type, pack('gg', $x, $y), reliable: false)`). Lecture : événement `message` (`$from`, `$type`,
   `$payload`), `MessageCodec::unpack()` / `unjson()`.
4. **Autorité** : l'hôte simule et envoie des instantanés (~20/s), les autres envoient leurs entrées / état
   (~30/s), affichés avec `SnapshotBuffer` (100-120 ms dans le passé, interpolés).

Exemple complet : `games/pong/src/Scene/PlayScene.php` (+ `src/Net/PongProtocol.php`).

Événements de `NetSession` : `open`, `close`, `error` (`no-room`, `room-full`...), `room`, `peerJoin`,
`peerLeave`, `host` (l'hôte est parti : un autre le devient), `message`, `pong` (après `ping()`).
Limites : 16 joueurs par salle, 64 Ko par message, `reliable: false` n'a d'effet qu'en WebRTC.

## Tester sans écran

Deux processus en temps réel (`JUMPHP_REALTIME=1`), appariés par « partie rapide » :

```bash
bin/jumphp relay up
P='{"next":"App\\Scene\\PlayScene","nextParams":{"mode":"online"},"back":"App\\Scene\\TitleScene","auto":"quick"}'
for n in a b; do
  docker run --rm --network host --user "$(id -u):$(id -g)" -v "$PWD:/workspace" -w /workspace/games/pong \
    -e HOME=/tmp -e JUMPHP_SMOKE=1 -e JUMPHP_REALTIME=1 -e JUMPHP_MAX_FRAMES=600 \
    -e JUMPHP_SCREENSHOT=var/screenshots/net-$n.png -e 'JUMPHP_SCENE=JumPHP\Net\LobbyScene' \
    -e "JUMPHP_SCENE_PARAMS=$P" -e JUMPHP_NET_URL=ws://127.0.0.1:8090/ws jumphp/desktop:dev php app.php &
  sleep 1
done; wait
```

Les deux captures montrent la même partie (hôte à gauche, invité à droite). Navigateur contre bureau :
lancer un des deux clients ci-dessus puis `bin/jumphp webshot pong 14 '[[1.5,"ArrowDown"],[2,"ArrowDown"],[2.5,"Enter"],[4,"ArrowDown"],[4.5,"ArrowDown"],[5,"Enter"]]'`
(ONLINE puis QUICK MATCH).
