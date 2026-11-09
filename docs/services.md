# Services: a JumPHP program in the background

A project (most often a CLI app, also a desktop or graphic app) can run as a **service** of a computer: started at boot
or at login, restarted when it fails, stopped cleanly, with its logs kept by the system. JumPHP installs the exported
executable (`bin/jumphp export:standalone`) as:

| System | How | Where |
|---|---|---|
| Linux | a systemd unit | user: `~/.config/systemd/user/<name>.service`; `--system`: `/etc/systemd/system/<name>.service` |
| macOS | a launchd property list | user: `~/Library/LaunchAgents/net.jumphp.<name>.plist`; `--system`: `/Library/LaunchDaemons/` |
| Windows | [WinSW](https://github.com/winsw/winsw) around the executable | `%ProgramData%\jumphp-services\<name>\` (administrator) |

## The settings: `config/project.yaml`

```yaml
project:
  id: monitor
  kind: cli
  service:
    name: monitor              # the service's name (default: the id)
    description: My monitor    # default: the title
    args: [serve]              # the command and its arguments (here: `monitor serve`)
    restart: on-failure        # on-failure (default), always, no
    user: true                 # true (default): a service of the user; false: of the system (root / administrator)
```

## The commands

```bash
bin/jumphp export:standalone monitor          # dist/standalone/monitor-linux-x86_64 (--windows, --macos)
bin/jumphp service:print monitor              # the unit / plist / WinSW file it would write
bin/jumphp service:install monitor            # copies the executable, writes the file, enables and starts it
bin/jumphp service:status monitor
bin/jumphp service:logs monitor [-f]          # journalctl, the launchd log file, WinSW's logs
bin/jumphp service:stop monitor / service:start monitor
bin/jumphp service:uninstall monitor          # stops it, removes the file and the copied executable
```

`--system` makes a system service (also `user: false`), `--exe <file>` installs another executable. The executable
is **copied** to a folder of its own (user: `~/.local/lib/jumphp-services/<name>`, macOS `~/Library/Application
Support/jumphp-services/<name>`; system: `/opt/jumphp-services/<name>`), so `dist/` can change. A system unit on Linux
gets `/var/lib/jumphp-services/<name>` as its home (the executable unpacks there, its data folder is there).

The commands run `tools/service.sh`: plain bash, no Docker. It can be given with the executable to install it on
another computer: `tools/service.sh install --name monitor --exec ./monitor-linux-x86_64 --arg serve [--system]`.

A Linux user service runs while its user is logged in; to keep it without a session (a server):
`loginctl enable-linger <user>` (`service:install` says so).

## The program: `JumPHP\Service\Daemon`

```php
use JumPHP\Service\Daemon;

#[AsCommand('serve')]
final class ServeCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Daemon::log('started');
        return Daemon::loop(fn () => $this->check(), 1.0, fn () => $this->save());   // every second, until stopped
    }
}
```

`Daemon::loop($tick, $every, $stop)` calls `$tick` every `$every` seconds until SIGTERM / SIGINT / SIGHUP (systemd,
launchd) or a console event (Windows: WinSW sends Ctrl+C), then `$stop` once, and returns 0. An exception in `$tick` is
logged and returns 1: with `restart: on-failure` the system starts it again. `Daemon::log()` writes to stderr with the
time (the system keeps it). `Daemon::stop()` ends the loop from the program itself. The class has no dependency on the
rest of the engine: a CLI app (which does not require `jumphp/engine`) copies `engine/src/Service/Daemon.php` into its
`src/` and changes the namespace.

## What was tried

- Linux, in a container running systemd: a user service and a system service installed, heartbeats every second,
  `kill -9` restarted by systemd, `service:stop` ending cleanly (SIGTERM, the stop callback ran), logs, uninstall.
- macOS (launchd) and Windows (WinSW): written, **not tried yet**.
