#!/usr/bin/python3 -I
"""Run PowerShell inside a Windows VM through the QEMU guest agent (no SSH, no network needed).

The VM must have the guest agent running (the virtio-win guest tools install it) and a virtio-serial port wired to a
unix socket on the host (start-vm.sh does that: <vm-dir>/qga.sock).

  guest-exec.py [options] -c 'Get-Date'              run a PowerShell command, print its output, exit with its code
  guest-exec.py [options] -f script.ps1 [args...]    copy the script into the guest, run it, delete it
  guest-exec.py [options] --put LOCAL REMOTE         copy a local file into the guest (REMOTE = a Windows path)
  guest-exec.py [options] --put-env VAR REMOTE       write the value of the environment variable VAR to a file in the
                                                     guest (a secret never goes on a command line this way)
  guest-exec.py [options] --ping                     is the agent there?

Options: --socket PATH | --vm-dir DIR (default: $QGA_SOCKET, else $VM_DIR/qga.sock, else ./qga.sock)
         --detach   start the command and return at once (prints the guest PID): for long jobs, poll a log file
         --timeout S   seconds to wait for the agent / the command (default $QGA_TIMEOUT or 120)
"""
import argparse, base64, json, os, socket, sys, time

PS = 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe'


class Agent:
    def __init__(self, path, timeout):
        self.s = socket.socket(socket.AF_UNIX)
        self.s.settimeout(timeout)
        try:
            self.s.connect(path)
        except OSError as e:
            sys.exit(f'cannot reach the guest agent socket {path}: {e} (is the VM running?)')
        self.f = self.s.makefile('rw')
        sid = int(time.time() * 1000) % 2**31
        self.f.write(json.dumps({'execute': 'guest-sync', 'arguments': {'id': sid}}) + '\n')
        self.f.flush()
        while True:                                   # drop stale answers until ours comes back
            line = self.f.readline()
            if not line:
                sys.exit('guest agent closed the connection (is Windows up, is qemu-ga running?)')
            try:
                if json.loads(line).get('return') == sid:
                    break
            except ValueError:
                pass

    def call(self, cmd, args=None):
        self.f.write(json.dumps({'execute': cmd, **({'arguments': args} if args else {})}) + '\n')
        self.f.flush()
        while True:
            line = self.f.readline()
            if not line:
                sys.exit('guest agent closed the connection')
            r = json.loads(line)
            if 'return' in r:
                return r['return']
            if 'error' in r:
                sys.exit('guest agent error: ' + str(r['error']))

    def put(self, local, remote):
        h = self.call('guest-file-open', {'path': remote, 'mode': 'wb'})
        with open(local, 'rb') as src:
            while True:
                chunk = src.read(32768)
                if not chunk:
                    break
                self.call('guest-file-write', {'handle': h, 'buf-b64': base64.b64encode(chunk).decode()})
        self.call('guest-file-close', {'handle': h})

    def exec(self, args, detach=False):
        pid = self.call('guest-exec', {'path': PS, 'arg': args, 'capture-output': True})['pid']
        if detach:
            return pid, None
        while True:
            st = self.call('guest-exec-status', {'pid': pid})
            if st.get('exited'):
                return pid, st
            time.sleep(0.5)


def show(st):
    dec = lambda b: base64.b64decode(b).decode('utf-8', 'replace') if b else ''
    sys.stdout.write(dec(st.get('out-data')))
    sys.stderr.write(dec(st.get('err-data')))
    return st.get('exitcode', 0)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--socket')
    ap.add_argument('--vm-dir')
    ap.add_argument('--timeout', type=float, default=float(os.environ.get('QGA_TIMEOUT', '120')))
    ap.add_argument('--detach', action='store_true')
    g = ap.add_mutually_exclusive_group(required=True)
    g.add_argument('-c', '--command')
    g.add_argument('-f', '--file')
    g.add_argument('--put', nargs=2, metavar=('LOCAL', 'REMOTE'))
    g.add_argument('--put-env', nargs=2, metavar=('VAR', 'REMOTE'))
    g.add_argument('--ping', action='store_true')
    ap.add_argument('rest', nargs='*', help='arguments of the script (with -f)')
    a = ap.parse_args()

    sock = a.socket or os.environ.get('QGA_SOCKET') or os.path.join(a.vm_dir or os.environ.get('VM_DIR', '.'), 'qga.sock')
    ag = Agent(sock, a.timeout)
    if a.ping:
        ag.call('guest-ping')
        print('guest agent: ok')
        return 0
    if a.put:
        ag.put(*a.put)
        return 0
    if a.put_env:
        var, remote = a.put_env
        if var not in os.environ:
            sys.exit(f'environment variable {var} is not set')
        h = ag.call('guest-file-open', {'path': remote, 'mode': 'wb'})
        ag.call('guest-file-write', {'handle': h, 'buf-b64': base64.b64encode(os.environ[var].encode()).decode()})
        ag.call('guest-file-close', {'handle': h})
        return 0
    utf8 = "[Console]::OutputEncoding=[Text.Encoding]::UTF8;$ProgressPreference='SilentlyContinue';"
    base = ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass']
    if a.command:
        pid, st = ag.exec(base + ['-Command', utf8 + a.command], a.detach)
    else:
        remote = f'C:\\Windows\\Temp\\qga-{os.getpid()}.ps1'
        ag.put(a.file, remote)
        if a.detach:
            pid, st = ag.exec(base + ['-File', remote] + a.rest, True)   # not deleted: it may still be running
        else:
            pid, st = ag.exec(base + ['-File', remote] + a.rest)
            ag.exec(base + ['-Command', f"Remove-Item '{remote}' -Force -ErrorAction SilentlyContinue"])
    if a.detach:
        print(pid)
        return 0
    return show(st)


if __name__ == '__main__':
    sys.exit(main())
