# Tutoriel : une connexion à travers SSH, pas à pas

Le projet d'exemple `apps/hello-ssh` atteint un serveur Redis **que seul un serveur SSH (un bastion) peut joindre** : `ssh -W host:port`, le flux de la connexion est le
flux vers Redis. Sur l'ordinateur, c'est le `ssh` du système ; dans le navigateur, **le vrai client OpenSSH compilé en WebAssembly**, relié à votre PHP par un tuyau.

## 1. Créer l'application

```bash
bin/jumphp gui:new mon-ssh "Mon SSH" && bin/jumphp install mon-ssh
```

## 2. La fenêtre

`forms/MainForm.form.yaml` (copiez `apps/hello-ssh/forms/MainForm.form.yaml`) : le serveur SSH (`sshHost`, `sshPort`, `sshUser`), un bouton « Choose a key file… » (`chooseKey`), la phrase
secrète de la clé (`passphrase`), la cible vue du bastion (`host`, `port`), un bouton `ping`, `status`, `result`.

## 3. La clé : le sélecteur de fichier

Un navigateur n'a pas de dossier `~/.ssh` : on demande le fichier à la personne. `FilePicker::open()` marche sur l'ordinateur ET dans le navigateur (appelez-le depuis un clic) ; le fichier
choisi arrive à un chemin que l'on lit puis efface. On le garde dans le **trousseau** de l'application (`$this->engine->sshKeyring()` : le rôle d'un agent, que le navigateur n'a pas).

```php
public function onChooseKey(Button $sender): void
{
    FilePicker::open(['title' => 'SSH private key'])->then(function (?string $file): void {
        if ($file === null) { return; }                                   // annulé
        $text = (string)file_get_contents($file);
        unlink($file);
        $this->engine->sshKeyring()->add(basename($file), $text, $this->passphrase->text);   // nom, clé, phrase secrète
        $this->keyState->text = 'Keys: ' . implode(', ', $this->engine->sshKeyring()->names());
    });
}
```

La clé reste dans le stockage de l'application (`var/ssh_keyring.json` : IndexedDB dans le navigateur, le dossier de données sur l'ordinateur) et n'est **jamais envoyée au serveur** : elle va dans la
mémoire du client SSH. Chaque connexion propose toutes les clés du trousseau, comme un agent.

## 4. Se connecter

```php
$options = ['sshHost' => 'bastion.exemple.com', 'sshPort' => 22, 'sshUser' => 'moi', 'sshKeys' => $this->engine->sshKeyring()->all()];
$connection = $this->engine->ssh()->connect('127.0.0.1', 6379, $options);      // 127.0.0.1:6379 vu depuis le bastion
$connection->on('open', fn () => $connection->write("PING\r\n"));
$connection->on('data', fn (string $bytes) => $this->result->text = trim($bytes));
$connection->on('error', fn (string $m) => $this->result->text = $m);          // « Permission denied (publickey) », « Could not resolve hostname »...
$connection->on('knownHosts', fn (string $text) => $this->engine->storage->writeJson('known_hosts.json', ['text' => $text]));   // l'empreinte du serveur, gardée
```

Mêmes événements qu'avec `tcp()`. Pour du code qui prend un `Tcp` (comme le client MySQL de `apps/jumphpsql`) : `$this->engine->ssh()->via($options)` a le même `connect($host, $port, $timeout)`.
Les options : `docs/ssh-wasm.md`.

## 5. Tester

```bash
bin/jumphp test mon-ssh      # le trousseau, les champs manquants, un ssh qui n'atteint pas son serveur (le message dit pourquoi)
bin/jumphp shot mon-ssh
bin/jumphp run mon-ssh       # sur l'ordinateur : le ssh du système (votre agent et ~/.ssh marchent aussi)
```

## 6. Le navigateur : deux droits

```bash
bin/jumphp build mon-ssh && bin/jumphp serve mon-ssh
```

Dans le navigateur, deux étages passent par le **pont TCP** de jumphp.net, qui n'accepte que les applications autorisées : le client SSH (WebAssembly) se connecte au bastion (port 22 ou un autre) → il faut
le droit **SSH**. (Un client SQL qui parle à une base directement a besoin du droit **TCP**.) Studio → le projet → **Accès SSH** → « Demander l'accès SSH » ; l'administrateur l'autorise pour le commit publié.

Le droit SSH est plus large (n'importe quel port d'une adresse publique, jamais une adresse privée) : l'administrateur ne l'accorde qu'à du code qu'il a regardé. Statistiques, journal, coupure et blocage :
Administration → Pont TCP.

## 7. Vérifier dans le navigateur

Page du projet sur jumphp.net : choisissez votre clé (le sélecteur de fichier de votre ordinateur s'ouvre), indiquez le bastion et la cible, cliquez « Send PING through SSH » : `The server answered: +PONG`.
Les premières secondes montrent « The SSH session is starting… » : le client SSH démarre dans un Web Worker (un fichier de 3,8 Mo chargé à la demande).

Comment ça marche (deux wasm reliés par un tuyau), la sécurité et la compilation d'OpenSSH : `docs/ssh-wasm.md`. Le premier tutoriel : `docs/tuto-tcp.md`.
