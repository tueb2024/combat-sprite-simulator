#!/usr/bin/env bash
# A program as a service of this computer (docs/services.md): systemd (Linux), launchd (macOS), WinSW (Windows).
# Plain bash, no Docker: bin/jumphp service:* runs it, and it can be given with an exported executable.
#
#   tools/service.sh install|uninstall|status|logs|start|stop|print --name N --exec FILE [--arg A]...
#        [--description D] [--restart on-failure|always|no] [--system] [--os linux|macos|windows] [--follow]
#
# install copies FILE to a folder of its own (user: ~/.local/lib/jumphp-services/N, macOS ~/Library/Application
# Support/jumphp-services/N; --system: /opt/jumphp-services/N, Windows: %ProgramData%\jumphp-services\N), so the
# project's dist/ can change or go. A user service (the default) runs while its user is logged in (Linux: always, with
# `loginctl enable-linger <user>`); --system runs without any session, at boot (root / administrator needed).
set -euo pipefail

say() { printf '\033[1;36m==> %s\033[0m\n' "$*" >&2; }
die() { printf '\033[1;31m[x] %s\033[0m\n' "$*" >&2; exit 1; }

action="${1:-}"; shift || true
name="" exe="" description="" restart="on-failure" system=0 follow=0 args=()
case "$(uname -s)" in Linux) os=linux ;; Darwin) os=macos ;; MINGW*|MSYS*|CYGWIN*) os=windows ;; *) os=linux ;; esac
while [ $# -gt 0 ]; do
  case "$1" in
    --name) name="$2"; shift ;;
    --exec) exe="$2"; shift ;;
    --arg) args+=("$2"); shift ;;
    --description) description="$2"; shift ;;
    --restart) restart="$2"; shift ;;
    --system) system=1 ;;
    --os) os="$2"; shift ;;
    --follow|-f) follow=1 ;;
    *) die "unknown option $1" ;;
  esac
  shift
done
[[ "$name" =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]*$ ]] || die "--name: letters, digits, . _ - (got '$name')"
[ -n "$description" ] || description="$name"
case "$restart" in on-failure|always|no) ;; *) die "--restart: on-failure, always or no" ;; esac

# where the executable is installed
home="${HOME:-/root}"
case "$os" in
  linux) dir=$([ $system = 1 ] && echo "/opt/jumphp-services/$name" || echo "$home/.local/lib/jumphp-services/$name") ;;
  macos) dir=$([ $system = 1 ] && echo "/Library/Application Support/jumphp-services/$name" || echo "$home/Library/Application Support/jumphp-services/$name") ;;
  windows) dir="${PROGRAMDATA:-C:/ProgramData}/jumphp-services/$name" ;;
  *) die "--os: linux, macos or windows" ;;
esac
installed="$dir/$(basename "${exe:-$name}")"

# root (or sudo) for system services; a user's own folders otherwise
as_admin() {
  if [ $system = 0 ] || [ "$(id -u)" = 0 ] || [ "$os" = windows ]; then "$@"; else sudo "$@"; fi
}

quote() { local a out=""; for a in "$@"; do out+=" $(printf '%q' "$a")"; done; echo "${out# }"; }
xml() { local s="$1"; s="${s//&/&amp;}"; s="${s//</&lt;}"; s="${s//>/&gt;}"; s="${s//\"/&quot;}"; echo "$s"; }

# --- Linux: a systemd unit ------------------------------------------------------------------------------------
linux_unit_path() { [ $system = 1 ] && echo "/etc/systemd/system/$name.service" || echo "${XDG_CONFIG_HOME:-$home/.config}/systemd/user/$name.service"; }
linux_ctl() { if [ $system = 1 ]; then as_admin systemctl "$@"; else systemctl --user "$@"; fi; }
linux_unit() {
  cat <<EOF
# made by JumPHP (bin/jumphp service:install): systemctl$([ $system = 1 ] || echo ' --user') status $name
[Unit]
Description=$description
$([ $system = 1 ] && printf 'After=network-online.target\nWants=network-online.target')

[Service]
Type=simple
ExecStart=$(quote "$installed" "${args[@]}")
WorkingDirectory=$dir
$([ $system = 1 ] && printf 'StateDirectory=jumphp-services/%s\nEnvironment=HOME=/var/lib/jumphp-services/%s' "$name" "$name")
Restart=$restart
RestartSec=2
KillSignal=SIGTERM
TimeoutStopSec=15

[Install]
WantedBy=$([ $system = 1 ] && echo multi-user.target || echo default.target)
EOF
}

# --- macOS: a launchd property list ---------------------------------------------------------------------------
macos_label() { echo "net.jumphp.$name"; }
macos_plist_path() { [ $system = 1 ] && echo "/Library/LaunchDaemons/$(macos_label).plist" || echo "$home/Library/LaunchAgents/$(macos_label).plist"; }
macos_log() { [ $system = 1 ] && echo "/Library/Logs/jumphp-$name.log" || echo "$home/Library/Logs/jumphp-$name.log"; }
macos_domain() { [ $system = 1 ] && echo system || echo "gui/$(id -u)"; }
macos_plist() {
  local a keep
  case "$restart" in always) keep='<true/>' ;; no) keep='<false/>' ;; *) keep='<dict><key>SuccessfulExit</key><false/></dict>' ;; esac
  cat <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$(macos_label)</string>
  <key>ProgramArguments</key>
  <array>
    <string>$(xml "$installed")</string>
$(for a in "${args[@]}"; do echo "    <string>$(xml "$a")</string>"; done)
  </array>
  <key>WorkingDirectory</key><string>$(xml "$dir")</string>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key>$keep
  <key>ThrottleInterval</key><integer>2</integer>
  <key>StandardOutPath</key><string>$(xml "$(macos_log)")</string>
  <key>StandardErrorPath</key><string>$(xml "$(macos_log)")</string>
</dict>
</plist>
EOF
}

# --- Windows: WinSW around the executable (PHP cannot answer the service manager itself) ----------------------
WINSW_URL="${JUMPHP_WINSW_URL:-https://github.com/winsw/winsw/releases/download/v2.12.0/WinSW-x64.exe}"
windows_xml() {
  local a
  cat <<EOF
<service>
  <id>$(xml "$name")</id>
  <name>$(xml "$name")</name>
  <description>$(xml "$description")</description>
  <executable>$(xml "$installed")</executable>
$(for a in "${args[@]}"; do echo "  <argument>$(xml "$a")</argument>"; done)
  <workingdirectory>$(xml "$dir")</workingdirectory>
  <startmode>Automatic</startmode>
  <stoptimeout>15 sec</stoptimeout>
$([ "$restart" = no ] || echo '  <onfailure action="restart" delay="2 sec"/>')
  <log mode="roll-by-size"><sizeThreshold>10240</sizeThreshold><keepFiles>4</keepFiles></log>
</service>
EOF
}

print() {
  case "$os" in
    linux) echo "# $(linux_unit_path)"; linux_unit ;;
    macos) echo "# $(macos_plist_path)"; macos_plist ;;
    windows) echo "# $dir/$name-service.xml (WinSW: $dir/$name-service.exe)"; windows_xml ;;
  esac
}

install() {
  [ -f "$exe" ] || die "no executable: --exec <file> (bin/jumphp export:standalone first)"
  as_admin mkdir -p "$dir"
  as_admin cp "$exe" "$installed"
  as_admin chmod 755 "$installed" 2>/dev/null || true
  case "$os" in
    linux)
      local unit; unit="$(linux_unit_path)"
      as_admin mkdir -p "$(dirname "$unit")"
      linux_unit | as_admin tee "$unit" >/dev/null
      linux_ctl daemon-reload
      linux_ctl enable --now "$name.service"
      say "$name: installed and started ($unit)"
      if [ $system = 0 ] && command -v loginctl >/dev/null && [ "$(loginctl show-user "$(id -un)" -p Linger --value 2>/dev/null)" != yes ]; then
        say "it runs while you are logged in; to keep it running without a session: loginctl enable-linger $(id -un)"
      fi ;;
    macos)
      local plist; plist="$(macos_plist_path)"
      as_admin mkdir -p "$(dirname "$plist")" "$(dirname "$(macos_log)")"
      macos_plist | as_admin tee "$plist" >/dev/null
      as_admin launchctl bootout "$(macos_domain)/$(macos_label)" 2>/dev/null || true
      as_admin launchctl bootstrap "$(macos_domain)" "$plist"
      say "$name: installed and started ($plist, log $(macos_log))" ;;
    windows)
      local wrapper="$dir/$name-service.exe"
      [ -f "$wrapper" ] || { say "downloading WinSW ($WINSW_URL)"; curl -fsSL -o "$wrapper" "$WINSW_URL"; }
      windows_xml > "$dir/$name-service.xml"
      "$wrapper" install
      "$wrapper" start
      say "$name: installed and started (Windows service; logs in $dir)" ;;
  esac
}

uninstall() {
  case "$os" in
    linux)
      linux_ctl disable --now "$name.service" 2>/dev/null || true
      as_admin rm -f "$(linux_unit_path)"
      linux_ctl daemon-reload ;;
    macos)
      as_admin launchctl bootout "$(macos_domain)/$(macos_label)" 2>/dev/null || true
      as_admin rm -f "$(macos_plist_path)" ;;
    windows)
      "$dir/$name-service.exe" stop 2>/dev/null || true
      "$dir/$name-service.exe" uninstall 2>/dev/null || true ;;
  esac
  case "$dir" in */jumphp-services/"$name") as_admin rm -rf "$dir" ;; esac
  say "$name: stopped and removed"
}

status() {
  case "$os" in
    linux) linux_ctl status --no-pager "$name.service" || true ;;
    macos) as_admin launchctl print "$(macos_domain)/$(macos_label)" | head -30 || true ;;
    windows) sc.exe query "$name" || true ;;
  esac
}

logs() {
  case "$os" in
    linux)
      local j=(journalctl --no-pager -n 100); [ $follow = 1 ] && j=(journalctl -f -n 100)
      if [ $system = 1 ]; then as_admin "${j[@]}" -u "$name.service"; else "${j[@]}" --user -u "$name.service"; fi ;;
    macos) if [ $follow = 1 ]; then tail -n 100 -f "$(macos_log)"; else tail -n 100 "$(macos_log)"; fi ;;
    windows) tail -n 100 "$dir/$name-service.err.log" "$dir/$name-service.out.log" 2>/dev/null || true ;;
  esac
}

start_stop() {
  case "$os" in
    linux) linux_ctl "$1" "$name.service" ;;
    macos) if [ "$1" = start ]; then as_admin launchctl kickstart "$(macos_domain)/$(macos_label)"; else as_admin launchctl kill TERM "$(macos_domain)/$(macos_label)"; fi ;;
    windows) "$dir/$name-service.exe" "$1" ;;
  esac
}

case "$action" in
  install) install ;;
  uninstall) uninstall ;;
  status) status ;;
  logs) logs ;;
  start|stop) start_stop "$action" ;;
  print) print ;;
  *) die "usage: tools/service.sh install|uninstall|status|logs|start|stop|print --name N --exec FILE [--arg A]... [--system]" ;;
esac
