<?php

namespace JumPHP\Net;

use JumPHP\Save\Storage;

/**
 * The SSH keys an application keeps for its user, an agent's role in the browser (which has no ssh-agent): the keys the person chose once (FilePicker)
 * are kept in the application's data (var/ssh_keyring.json: IndexedDB in the browser, the user's data folder on desktop), and every SSH connection offers
 * all of them (`'sshKeys' => $ring->all()`): ssh tries them in turn. Not encrypted: they live in the application's own storage, like a saved password.
 *
 *   $ring = $engine->sshKeyring();
 *   $ring->add('work laptop', $keyText, $passphrase);          // name, the key's text, its passphrase ('' without)
 *   $engine->ssh()->connect($host, $port, ['sshHost' => 'bastion', 'sshUser' => 'me', 'sshKeys' => $ring->all()]);
 */
final class SshKeyring
{
    private const FILE = 'ssh_keyring.json';

    public function __construct(private Storage $storage)
    {
    }

    public function add(string $name, string $key, string $passphrase = ''): void
    {
        if (!str_contains($key, 'PRIVATE KEY')) {
            throw new \InvalidArgumentException('This is not an SSH private key.');
        }
        $keys = array_values(array_filter($this->all(), fn (array $k) => $k['name'] !== $name));
        $keys[] = ['name' => $name, 'key' => $key, 'passphrase' => $passphrase];
        $this->storage->writeJson(self::FILE, $keys);
    }

    public function remove(string $name): void
    {
        $this->storage->writeJson(self::FILE, array_values(array_filter($this->all(), fn (array $k) => $k['name'] !== $name)));
    }

    /** @return list<array{name: string, key: string, passphrase: string}> */
    public function all(): array
    {
        $data = $this->storage->readJson(self::FILE, []);
        $keys = [];
        foreach (is_array($data) ? $data : [] as $k) {
            if (is_array($k) && isset($k['name'], $k['key'])) {
                $keys[] = ['name' => (string)$k['name'], 'key' => (string)$k['key'], 'passphrase' => (string)($k['passphrase'] ?? '')];
            }
        }
        return $keys;
    }

    /** @return list<string> the names (not the keys) */
    public function names(): array
    {
        return array_map(fn (array $k) => $k['name'], $this->all());
    }
}
