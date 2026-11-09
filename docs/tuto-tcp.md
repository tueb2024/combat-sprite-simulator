# Tutoriel : une connexion TCP, pas à pas

Le projet d'exemple `apps/hello-tcp` ouvre une connexion TCP vers un serveur Redis, lui envoie `PING` et affiche la réponse (`+PONG`). Le même code
marche sur un ordinateur (un socket) et dans le navigateur (le pont TCP de jumphp.net). Refaites-le vous-même en 8 étapes.

## 1. Créer l'application

```bash
bin/jumphp gui:new mon-tcp "Mon TCP"        # apps/mon-tcp : une application graphique (formulaires, pas de HTML)
bin/jumphp install mon-tcp
```

## 2. Dessiner la fenêtre

Ouvrez `apps/mon-tcp/forms/MainForm.form.yaml` (dans l'IDE : l'éditeur visuel, onglet Design) : deux champs (`host`, `port`), un bouton `ping`
(`events: {click: onPingClick}`), deux étiquettes (`status`, `result`). Copiez celui de `apps/hello-tcp/forms/MainForm.form.yaml`.

## 3. Écrire le code derrière

`apps/mon-tcp/src/Form/MainForm.php` : une classe `final class MainForm extends JumPHP\Gui\Form`. Une ligne `@property` par widget (l'éditeur le fait pour vous).

```php
public function onPingClick(mixed $sender = null): void
{
    $connection = $this->engine->tcp()->connect(trim($this->host->text), (int)$this->port->text);   // ne bloque jamais
    $connection->on('open',  fn () => $connection->write("PING\r\n"));                                  // connecté : on parle
    $connection->on('data',  function (string $bytes) use ($connection) {                               // la réponse
        $this->result->text = 'Réponse : ' . trim($bytes);
        $connection->close();
    });
    $connection->on('error', fn (string $message) => $this->result->text = $message);                   // le pourquoi d'un échec
}
```

Les règles à retenir : **ne jamais** appeler `stream_socket_client` ou `fsockopen` (ils n'existent pas dans le navigateur) ; **ne jamais attendre** : les réponses arrivent
comme événements entre deux images. Les événements : `open`, `data` (des octets), `close` (la raison, ou `null`), `error` (un message).

## 4. Un test qui joue la scène

`apps/mon-tcp/tests/MainFormTest.php` (voir `apps/hello-tcp/tests/MainFormTest.php`) : le test ouvre un vrai serveur TCP local (un faux Redis qui répond `+PONG`), clique sur le
bouton, avance les images et vérifie le texte affiché.

```bash
bin/jumphp test mon-tcp          # php -l, phpstan, phpunit (le test ci-dessus), puis 600 images de la scène
bin/jumphp shot mon-tcp          # une capture : ouvrez le PNG (apps/mon-tcp/var/screenshots/shot.png)
```

## 5. L'essayer sur votre ordinateur

```bash
bin/jumphp run mon-tcp           # une vraie fenêtre ; entrez l'adresse d'un Redis et cliquez « Send PING »
```

## 6. L'essayer dans le navigateur

```bash
bin/jumphp build mon-tcp && bin/jumphp serve mon-tcp      # http://localhost:8080
```

Dans le navigateur, une page ne peut pas ouvrir de socket : la connexion passe par le **pont TCP** de jumphp.net. Il ne laisse passer que les applications **autorisées par
l'administrateur**, pour une version précise du code, et seulement vers des ports de bases de données (3306, 5432, 6379, 27017…) d'adresses publiques. Sans autorisation, l'événement
`error` dit pourquoi (« This application has no TCP right… »). C'est le comportement attendu : passez à l'étape 7.

## 7. Publier et demander l'accès TCP

1. Poussez le projet sur Git et publiez-le sur jumphp.net (voir « Avec votre Claude » ou l'IDE : Mettre sur jumphp.net).
2. Studio → le projet → **Accès TCP** → « Demander l'accès TCP » (un message pour l'administrateur si vous voulez).
3. L'administrateur voit la demande dans sa cloche, l'**autorise** (ou la refuse) : l'autorisation vaut pour le commit publié. Un nouveau commit demande une nouvelle autorisation.

## 8. Vérifier

Ouvrez la page du projet sur jumphp.net, entrez l'adresse d'un Redis public (ou le vôtre, port 6379) et cliquez : `The server answered: +PONG`. L'administrateur voit la connexion
en direct (Administration → Pont TCP : application, cible, durée, octets) et peut la couper.

Pour aller plus loin : `docs/tcp.md` (les droits, les garde-fous, le pont sur un serveur indépendant), `docs/tuto-ssh.md` (la même chose à travers un serveur SSH), UDP
(`$this->engine->udp()`) et les pings (`$this->engine->icmp()`) dans le même document.
