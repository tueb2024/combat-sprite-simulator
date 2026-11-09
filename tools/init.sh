#!/usr/bin/env bash
# The set-up wizard of a JumPHP project (bin/jumphp init, run by composer create-project):
#   1. tools: git, curl, docker (+ compose, buildx)... installed with the system's package manager when missing;
#      the `jumphp` command for the whole system offered (bin/jumphp cli:install)
#   2. the game: name, id, description, resolution, language -> games/<id> from skeleton/, .env
#   3. Docker images, the browser engine (prebuilt download or local build), a first test
#   4. deployment to a server (FrankenPHP alone, Traefik + FrankenPHP, or an existing Traefik)
#   5. the project on GitHub (gh), GitHub Pages
#   6. Claude Code, started with a ready-made prompt: only what the game should be is asked
# Options: --yes (every default, nothing installed: tests / CI), --deploy (only step 4: bin/jumphp deploy:setup),
# --github (only step 5), --claude (only step 6). Texts in French when the system language is French.
set -uo pipefail
# `gh` for GitHub and for git.jumphp.net (tools/bin/gh)
export PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/tools/bin:$PATH"
# config/project.yaml, else the older config/game.yaml (both read)
project_yaml() { if [ -f "$1/config/project.yaml" ]; then echo "$1/config/project.yaml"; else echo "$1/config/game.yaml"; fi; }

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
JUMPHP="$ROOT/bin/jumphp"
# bin/jumphp without the terminal's input: docker run -i would swallow the answers typed (or piped) after
jp() { "$JUMPHP" "$@" </dev/null; }
UPSTREAM="${JUMPHP_REPO:-https://git.jumphp.net/jumphp/jumPhp.git}"

YES=0; ONLY=""
for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --deploy) ONLY=deploy ;;
    --github) ONLY=github ;;
    --claude) ONLY=claude ;;
  esac
done

case "${JUMPHP_LANG:-${LC_ALL:-${LC_MESSAGES:-${LANG:-}}}}" in fr*|FR*) FR=1 ;; *) FR=0 ;; esac

# ---------------------------------------------------------------------------------------------------------
# terminal helpers

t() { if [ "$FR" = 1 ]; then printf '%s' "$1"; else printf '%s' "$2"; fi; }
step()  { printf '\n\033[1;35m━━━ %s\033[0m\n\n' "$*"; }
info()  { printf '  %s\n' "$*"; }
ok()    { printf '  \033[32m✔\033[0m %s\n' "$*"; }
warn()  { printf '  \033[33m!\033[0m %s\n' "$*"; }
fail()  { printf '  \033[31m✘\033[0m %s\n' "$*"; }
run()   { printf '  \033[2m$ %s\033[0m\n' "$*"; "$@"; }

# ask <variable> <question> [default]
ask() {
  local __ask_var="$1" __ask_q="$2" __ask_def="${3:-}" __ask_ans=""
  if [ "$YES" = 1 ]; then
    __ask_ans="$__ask_def"
  else
    if [ -n "$__ask_def" ]; then printf '  \033[1m%s\033[0m \033[2m[%s]\033[0m ' "$__ask_q" "$__ask_def"
    else printf '  \033[1m%s\033[0m ' "$__ask_q"; fi
    IFS= read -r __ask_ans || __ask_ans=""
    [ -n "$__ask_ans" ] || __ask_ans="$__ask_def"
  fi
  printf -v "$__ask_var" '%s' "$__ask_ans"
}

# confirm <question> <y|n default>: 0 = yes
confirm() {
  local q="$1" def="${2:-y}" ans hint
  if [ "$def" = y ]; then hint="$(t 'O/n' 'Y/n')"; else hint="$(t 'o/N' 'y/N')"; fi
  if [ "$YES" = 1 ]; then [ "$def" = y ]; return; fi
  while true; do
    printf '  \033[1m%s\033[0m \033[2m[%s]\033[0m ' "$q" "$hint"
    IFS= read -r ans || ans=""
    case "$ans" in
      "") [ "$def" = y ]; return ;;
      [oOyY]|[oO][uU][iI]|[yY][eE][sS]) return 0 ;;
      [nN]|[nN][oO][nN]|[nN][oO]) return 1 ;;
    esac
  done
}

# choose <variable> <question> <default number> <option>...: the variable gets the number
choose() {
  local __choose_var="$1" __choose_q="$2" __choose_def="$3" __choose_i=1 __choice __choose_opt
  shift 3
  printf '  \033[1m%s\033[0m\n' "$__choose_q"
  for __choose_opt in "$@"; do printf '    %s) %s\n' "$__choose_i" "$__choose_opt"; __choose_i=$((__choose_i + 1)); done
  while true; do
    ask __choice "$(t 'Choix :' 'Choice:')" "$__choose_def"
    if [[ "$__choice" =~ ^[0-9]+$ ]] && [ "$__choice" -ge 1 ] && [ "$__choice" -le $# ]; then break; fi
    [ "$YES" = 1 ] && { __choice="$__choose_def"; break; }
  done
  printf -v "$__choose_var" '%s' "$__choice"
}

has() { command -v "$1" >/dev/null 2>&1; }

# .env (read by bin/jumphp and docker compose): env_set KEY value / env_get KEY
env_file_init() { [ -f .env ] || { if [ -f .env.dist ]; then cp .env.dist .env; else : > .env; fi; }; }
env_set() {
  env_file_init
  local key="$1" value="$2" line tmp
  value="${value//\\/\\\\}"; value="${value//\"/\\\"}"
  line="$key=\"$value\""
  tmp="$(mktemp)"
  if grep -qE "^$key=" .env; then
    awk -v k="$key" -v l="$line" 'index($0, k "=") == 1 { print l; next } { print }' .env > "$tmp"
  else
    cat .env > "$tmp"; printf '%s\n' "$line" >> "$tmp"
  fi
  cat "$tmp" > .env; rm -f "$tmp"
}
# read the way bin/jumphp reads it (sourced: quotes and comments handled)
env_get() { [ -f .env ] && ( set -a; . ./.env >/dev/null 2>&1; eval "printf '%s' \"\${$1:-}\"" ); }

# ---------------------------------------------------------------------------------------------------------
# operating system, package manager

OS="$(uname -s)"
PM=""
if [ "$OS" = Darwin ]; then
  PM=brew
elif [ -f /etc/os-release ]; then
  PM="$( . /etc/os-release
    case " ${ID:-} ${ID_LIKE:-} " in
      *" debian "*|*" ubuntu "*) echo apt ;;
      *" fedora "*|*" rhel "*|*" centos "*) echo dnf ;;
      *" arch "*) echo pacman ;;
      *" suse "*|*" opensuse"*) echo zypper ;;
      *" alpine "*) echo apk ;;
    esac )"
fi
SUDO=""
[ "$(id -u)" = 0 ] || { has sudo && SUDO="sudo"; }
APT_UPDATED=0

# pkg_install <apt> <dnf> <pacman> <brew> <zypper> <apk> ("-" = not available there)
pkg_install() {
  local p=""
  case "$PM" in apt) p="$1" ;; dnf) p="$2" ;; pacman) p="$3" ;; brew) p="$4" ;; zypper) p="$5" ;; apk) p="$6" ;; esac
  if [ -z "$p" ] || [ "$p" = - ]; then
    fail "$(t "je ne sais pas installer ça ici ($OS ${PM:-?}) : installe-le à la main" "I do not know how to install it here ($OS ${PM:-?}): install it by hand")"
    return 1
  fi
  # shellcheck disable=SC2086
  case "$PM" in
    apt) { [ "$APT_UPDATED" = 1 ] || { run $SUDO apt-get update -qq && APT_UPDATED=1; }; } && run $SUDO apt-get install -y $p ;;
    dnf) run $SUDO dnf install -y $p ;;
    pacman) run $SUDO pacman -S --needed --noconfirm $p ;;
    brew) has brew || { fail "Homebrew: https://brew.sh"; return 1; }; run brew install $p ;;
    zypper) run $SUDO zypper install -y $p ;;
    apk) run $SUDO apk add $p ;;
  esac
}

# need <command> <label> <apt> <dnf> <pacman> <brew> <zypper> <apk>
need() {
  local cmd="$1" label="$2"; shift 2
  if has "$cmd"; then ok "$label"; return 0; fi
  warn "$label: $(t 'absent' 'missing')"
  if [ "$YES" = 0 ] && confirm "$(t "Installer $label ?" "Install $label?")" y; then
    pkg_install "$@" && has "$cmd" && { ok "$label"; return 0; }
  fi
  return 1
}

# ---------------------------------------------------------------------------------------------------------
# 1. tools

docker_ready() { docker info >/dev/null 2>&1; }

install_docker() {
  case "$PM" in
    brew)
      run brew install --cask docker && run open -a Docker ;;
    apt|dnf)
      # the official script of Docker: engine + compose and buildx plugins from Docker's own repository
      local script; script="$(mktemp)"
      run curl -fsSL https://get.docker.com -o "$script" && run $SUDO sh "$script"
      rm -f "$script" ;;
    pacman) pkg_install - - "docker docker-compose docker-buildx" - - - ;;
    zypper) pkg_install - - - - "docker docker-compose docker-buildx" - ;;
    apk) pkg_install - - - - - "docker docker-cli-compose docker-cli-buildx" && run $SUDO rc-update add docker default ;;
    *) fail "Docker: https://docs.docker.com/engine/install/"; return 1 ;;
  esac
  if [ "$OS" = Linux ]; then
    if has systemctl; then run $SUDO systemctl enable --now docker; elif has service; then run $SUDO service docker start; fi
    [ "$(id -u)" = 0 ] || run $SUDO usermod -aG docker "$(id -un)"
  fi
}

check_docker() {
  if ! has docker; then
    warn "Docker: $(t 'absent' 'missing')"
    [ "$YES" = 0 ] && confirm "$(t 'Installer Docker ?' 'Install Docker?')" y && install_docker
    has docker || { fail "$(t 'Docker est indispensable : https://docs.docker.com/get-docker/' 'Docker is required: https://docs.docker.com/get-docker/')"; return 1; }
  fi
  if ! docker_ready; then
    if [ "$OS" = Darwin ]; then
      info "$(t 'démarrage de Docker Desktop...' 'starting Docker Desktop...')"
      open -a Docker 2>/dev/null
      for _ in $(seq 1 60); do docker_ready && break; sleep 2; done
    elif docker info 2>&1 | grep -qi 'permission denied'; then
      # the user is not in the docker group yet (or this shell started before): add, then carry on in a shell that has it
      id -nG | grep -qw docker || { [ "$YES" = 0 ] && confirm "$(t "Ajouter $(id -un) au groupe docker ?" "Add $(id -un) to the docker group?")" y && run $SUDO usermod -aG docker "$(id -un)"; }
      if has sg && [ -z "${JUMPHP_INIT_SG:-}" ] && getent group docker | grep -qw "$(id -un)"; then
        info "$(t 'reprise dans un shell avec le groupe docker...' 'resuming in a shell with the docker group...')"
        export JUMPHP_INIT_SG=1
        exec sg docker -c "bash '$ROOT/tools/init.sh' $*"
      fi
    else
      info "$(t 'démarrage du service docker...' 'starting the docker service...')"
      if has systemctl; then run $SUDO systemctl start docker; elif has service; then run $SUDO service docker start; fi
    fi
  fi
  if ! docker_ready; then
    fail "$(t "Docker ne répond pas (docker info). Démarre-le, ou reconnecte-toi si tu viens d'entrer dans le groupe docker, puis relance bin/jumphp init" \
               "Docker does not answer (docker info). Start it, or log in again if you just joined the docker group, then run bin/jumphp init again")"
    return 1
  fi
  ok "Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null)"
  if docker compose version >/dev/null 2>&1; then ok "Docker Compose"; else
    warn "Docker Compose: $(t 'absent' 'missing')"
    [ "$YES" = 0 ] && confirm "$(t 'Installer Docker Compose ?' 'Install Docker Compose?')" y \
      && { pkg_install docker-compose-plugin docker-compose-plugin docker-compose - docker-compose docker-cli-compose || pkg_install docker-compose-v2 - - - - -; }
  fi
  if docker buildx version >/dev/null 2>&1; then ok "Docker Buildx"; else
    warn "Docker Buildx: $(t 'absent (utile seulement pour construire le moteur navigateur soi-même)' 'missing (only needed to build the browser engine yourself)')"
    [ "$YES" = 0 ] && confirm "$(t 'Installer Docker Buildx ?' 'Install Docker Buildx?')" y \
      && { pkg_install docker-buildx-plugin docker-buildx-plugin docker-buildx - docker-buildx docker-cli-buildx || pkg_install docker-buildx - - - - -; }
  fi
  return 0
}

step_tools() {
  step "1. $(t 'Les outils' 'Tools')"
  info "$(t "Système : $OS${PM:+ ($PM)}" "System: $OS${PM:+ ($PM)}")"
  need git Git git git git git git git || { fail "$(t 'git est indispensable' 'git is required')"; return 1; }
  need curl curl curl curl curl curl curl curl || { fail "$(t 'curl est indispensable' 'curl is required')"; return 1; }
  check_docker "$@" || return 1
  info "$(t "Le reste (PHP, composer, emscripten, serveur web...) tourne dans Docker : rien d'autre à installer." \
            "Everything else (PHP, composer, emscripten, web server...) runs in Docker: nothing else to install.")"
}

# the `jumphp` command for the whole system (bin/jumphp cli:install): offered, never forced (not with --yes)
step_cli() {
  local installed="${JUMPHP_BIN_DIR:-$HOME/.local/bin}/jumphp"
  if [ -f "$installed" ] && cmp -s "$installed" "$ROOT/tools/jumphp-cli"; then
    ok "$(t 'la commande jumphp est installée' 'the jumphp command is installed')"; return 0
  fi
  [ "$YES" = 1 ] && return 0
  info "$(t "La commande \`jumphp\` pour tout le système : \`jumphp test mon-jeu\` depuis n'importe quel dossier du projet," \
            "The \`jumphp\` command for the whole system: \`jumphp test my-game\` from any folder of the project,")"
  info "$(t "\`jumphp new <dossier>\` pour un autre projet, et une question quand une nouvelle version de JumPHP sort (Packagist)." \
            "\`jumphp new <folder>\` for another project, and a question when a new JumPHP is out (Packagist).")"
  if confirm "$(t "Installer la commande jumphp (dans ~/.local/bin) ?" "Install the jumphp command (in ~/.local/bin)?")" y; then
    "$JUMPHP" cli:install && ok "jumphp"
  else
    info "$(t 'Plus tard : bin/jumphp cli:install' 'Later: bin/jumphp cli:install')"
  fi
}

# ---------------------------------------------------------------------------------------------------------
# 2. the game

slug() {
  printf '%s' "$1" | iconv -f utf-8 -t ascii//TRANSLIT 2>/dev/null | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//; s/^([0-9])/game-\1/' | cut -c1-40
}

GAME_ID=""; GAME_TITLE=""
step_game() {
  step "2. $(t 'Ton jeu' 'Your game')"
  local existing; existing="$(env_get JUMPHP_GAME)"
  if [ -n "$existing" ] && [ -d "games/$existing" ]; then
    if confirm "$(t "Le projet a déjà un jeu : games/$existing. Le garder ?" "The project already has a game: games/$existing. Keep it?")" y; then
      GAME_ID="$existing"
      GAME_TITLE="$(sed -nE 's/^[[:space:]]*title:[[:space:]]*"?([^"]*)"?.*/\1/p' "$(project_yaml "games/$existing")" | head -1)"
      return 0
    fi
  fi
  local def_title; def_title="$(basename "$ROOT" | tr '_-' '  ')"
  ask GAME_TITLE "$(t 'Nom du jeu (affiché en titre) :' 'Game name (shown as the title):')" "$def_title"
  local def_id; def_id="$(slug "$GAME_TITLE")"; [ -n "$def_id" ] || def_id=my-game
  while [ -d "games/$def_id" ]; do def_id="$def_id-2"; done
  while true; do
    ask GAME_ID "$(t 'Identifiant (dossier games/<id>, adresse web) :' 'Id (folder games/<id>, web address):')" "$def_id"
    if [[ ! "$GAME_ID" =~ ^[a-z][a-z0-9-]*$ ]]; then warn "$(t 'minuscules, chiffres et tirets, en commençant par une lettre' 'lower case letters, digits and dashes, starting with a letter')"
    elif [ -e "games/$GAME_ID" ]; then warn "$(t "games/$GAME_ID existe déjà" "games/$GAME_ID already exists")"
    else break; fi
    [ "$YES" = 1 ] && return 1
  done
  local description res lang
  ask description "$(t 'Description en une phrase (page web, portail) :' 'One-line description (web page, portal):')" ""
  choose res "$(t 'Résolution virtuelle (les pixels du jeu, agrandis à la fenêtre) :' 'Virtual resolution (the game pixels, scaled to the window):')" 1 \
    "$(t '320 x 180  (16:9, rétro)' '320 x 180  (16:9, retro)')" \
    "$(t '256 x 224  (console 16 bits)' '256 x 224  (16-bit console)')" \
    "$(t '320 x 240  (4:3, ordinateur des années 90)' '320 x 240  (4:3, 90s computer)')" \
    "$(t '480 x 270  (16:9, plus de détails)' '480 x 270  (16:9, more detail)')"
  local w=320 h=180
  case "$res" in 2) w=256; h=224 ;; 3) w=320; h=240 ;; 4) w=480; h=270 ;; esac
  ask lang "$(t 'Langue par défaut du jeu (fr, en...) :' 'Default language of the game (en, fr...):')" "$(t fr en)"

  info "$(t 'Image Docker du runtime (PHP + SDL + OpenGL, ~5 min la première fois)...' 'Docker image of the runtime (PHP + SDL + OpenGL, ~5 min the first time)...')"
  jp setup || { fail "bin/jumphp setup"; return 1; }
  jp new "$GAME_ID" "$GAME_TITLE" || return 1
  local yaml; yaml="$(project_yaml "games/$GAME_ID")" esc
  esc="$(printf '%s' "$description" | sed -e 's/[\\"]/\\&/g' -e 's/[&|]/\\&/g')"
  sed -i.bak -E \
    -e "s|^([[:space:]]*description:).*|\\1 \"$esc\"|" \
    -e "s|^([[:space:]]*width:)[[:space:]]*[0-9]+|\\1 $w|" \
    -e "s|^([[:space:]]*height:)[[:space:]]*[0-9]+|\\1 $h|" \
    -e "s|^([[:space:]]*lang:)[[:space:]]*[a-z]+|\\1 $lang|" "$yaml" && rm -f "$yaml.bak"
  env_set JUMPHP_GAME "$GAME_ID"
  jp install "$GAME_ID" || return 1
  ok "$(t "games/$GAME_ID est prêt (copie du squelette : un petit jeu de plateforme à remplacer)" "games/$GAME_ID is ready (a copy of the skeleton: a tiny platformer to replace)")"

  if [ "$YES" = 0 ] && ! confirm "$(t "Garder les jeux d'exemple (pong, jumpball...) ? Ce sont des modèles utiles pour Claude." "Keep the example games (pong, jumpball...)? They are useful models for Claude.")" y; then
    local d
    for d in games/*/; do
      d="$(basename "$d")"
      [ "$d" = "$GAME_ID" ] || [ "$d" = pong ] || run rm -rf "games/$d"
    done
    info "$(t "pong est gardé : c'est le jeu de référence d'AGENTS.md" "pong is kept: it is the reference game of AGENTS.md")"
  fi
}

# ---------------------------------------------------------------------------------------------------------
# 3. engine, first test

step_build() {
  step "3. $(t 'Moteur navigateur et premier test' 'Browser engine and a first test')"
  if [ -f build/engine/php-web.wasm ]; then
    ok "$(t 'moteur navigateur : déjà là (build/engine)' 'browser engine: already there (build/engine)')"
  else
    local how
    choose how "$(t 'Le moteur navigateur (PHP compilé en WebAssembly) :' 'The browser engine (PHP compiled to WebAssembly):')" 1 \
      "$(t 'le télécharger, déjà compilé (~10 Mo, recommandé)' 'download it, prebuilt (~10 MB, recommended)')" \
      "$(t 'le compiler ici (~25 min)' 'build it here (~25 min)')" \
      "$(t 'plus tard (bin/jumphp engine:download)' 'later (bin/jumphp engine:download)')"
    case "$how" in
      1) jp engine:download || { warn "$(t 'téléchargement impossible : compilation locale' 'download failed: local build')"; jp engine:build; } ;;
      2) jp engine:build ;;
    esac
  fi
  if [ -n "$GAME_ID" ] && confirm "$(t "Lancer le test de $GAME_ID (~1 min) ?" "Run the test of $GAME_ID (~1 min)?")" y; then
    jp test "$GAME_ID" && ok "$(t 'test OK' 'test OK')" || warn "$(t 'le test a échoué (voir ci-dessus)' 'the test failed (see above)')"
  fi
}

# ---------------------------------------------------------------------------------------------------------
# 4. deployment to a server

step_deploy() {
  step "4. $(t 'Déploiement sur un serveur' 'Deployment to a server')"
  info "$(t 'Le jeu est un site statique (PHP tourne dans le navigateur) : un petit serveur suffit.' 'The game is a static site (PHP runs in the browser): a small server is enough.')"
  if [ -z "$ONLY" ] && ! confirm "$(t 'Configurer le déploiement maintenant ? (plus tard : bin/jumphp deploy:setup)' 'Set up the deployment now? (later: bin/jumphp deploy:setup)')" n; then
    return 0
  fi
  local target domain mode email dir network entry resolver
  ask target "$(t 'Accès SSH au serveur (utilisateur@machine) :' 'SSH access to the server (user@host):')" "$(env_get JUMPHP_SERVER)"
  [ -n "$target" ] || { warn "$(t 'pas de serveur : étape passée' 'no server: step skipped')"; return 0; }
  need ssh "ssh" openssh-client openssh-clients openssh - openssh openssh-client || return 1
  need rsync rsync rsync rsync rsync rsync rsync rsync || return 1
  if ssh -n -o BatchMode=yes -o ConnectTimeout=8 "$target" true 2>/dev/null; then
    ok "SSH $target"
  else
    warn "$(t "connexion SSH sans mot de passe impossible vers $target" "no password-less SSH connection to $target")"
    if has ssh-copy-id && confirm "$(t "Installer ta clé SSH sur le serveur (ssh-copy-id, le mot de passe du serveur sera demandé) ?" "Install your SSH key on the server (ssh-copy-id, the server password is asked)?")" y; then
      [ -f "$HOME/.ssh/id_ed25519.pub" ] || [ -f "$HOME/.ssh/id_rsa.pub" ] || run ssh-keygen -t ed25519 -N "" -f "$HOME/.ssh/id_ed25519"
      run ssh-copy-id "$target"
    fi
  fi
  ask domain "$(t 'Nom de domaine du jeu (sa ligne DNS A/AAAA doit viser le serveur) :' 'Domain name of the game (its DNS A/AAAA record must point to the server):')" "$(env_get JUMPHP_DOMAIN)"
  local def_mode=1
  case "$(env_get JUMPHP_SERVER_MODE)" in traefik) def_mode=2 ;; traefik-external|traefik-passthrough) def_mode=3 ;; esac
  # a Traefik already on the server: the wizard reads its settings rather than guessing them
  local found="" found_mode="" http_entry=""
  found="$("$JUMPHP" server:traefik "$target" 2>/dev/null </dev/null || true)"
  found_get() { printf '%s\n' "$found" | sed -n "s/^$1=//p"; }
  if [ -n "$(found_get traefik)" ]; then
    def_mode=3
    found_mode="$(found_get mode)"
    ok "$(t "Traefik trouvé sur le serveur ($(found_get traefik), réseau $(found_get network))" "Traefik found on the server ($(found_get traefik), network $(found_get network))")"
  fi
  choose mode "$(t 'Serveur web :' 'Web server:')" "$def_mode" \
    "$(t "FrankenPHP seul (HTTPS Let's Encrypt automatique ; ports 80 et 443 libres sur le serveur)" "FrankenPHP alone (automatic Let's Encrypt HTTPS; ports 80 and 443 free on the server)")" \
    "$(t "Traefik + FrankenPHP (Traefik installé avec le jeu : pratique pour héberger d'autres sites ensuite)" "Traefik + FrankenPHP (Traefik installed with the game: handy to host other sites later)")" \
    "$(t 'derrière un Traefik déjà installé sur le serveur' 'behind a Traefik already running on the server')"
  case "$mode" in
    1) env_set JUMPHP_SERVER_MODE frankenphp ;;
    2) env_set JUMPHP_SERVER_MODE traefik ;;
    3) local sub=1
       [ "${found_mode:-$(env_get JUMPHP_SERVER_MODE)}" = traefik-passthrough ] && sub=2
       [ -z "$found" ] || [ -n "$(found_get resolver)" ] || info "$(t "aucun site de ce Traefik n'utilise de résolveur de certificats : FrankenPHP prendra lui-même son certificat" "no site of that Traefik uses a certificate resolver: FrankenPHP will get its own certificate")"
       choose sub "$(t 'Certificats HTTPS :' 'HTTPS certificates:')" "$sub" \
         "$(t 'fournis par Traefik (son résolveur de certificats)' 'issued by Traefik (its certificate resolver)')" \
         "$(t 'Traefik laisse passer le TLS, FrankenPHP prend son certificat (traefik-passthrough)' 'Traefik passes TLS through, FrankenPHP gets its certificate (traefik-passthrough)')"
       ask network "$(t 'Réseau Docker de ce Traefik :' 'Docker network of that Traefik:')" "$(found_get network)"
       [ -n "$network" ] || network="$(env_get JUMPHP_TRAEFIK_NETWORK || true)"
       [ -n "$network" ] || network=traefik
       ask entry "$(t "Point d'entrée HTTPS (:443) de Traefik :" "HTTPS (:443) entry point of Traefik:")" "$(found_get https | grep . || echo websecure)"
       env_set JUMPHP_TRAEFIK_NETWORK "$network"; env_set JUMPHP_TRAEFIK_ENTRYPOINT "$entry"
       if [ "$sub" = 1 ]; then
         env_set JUMPHP_SERVER_MODE traefik-external
         ask resolver "$(t 'Résolveur de certificats de Traefik :' 'Certificate resolver of Traefik:')" "$(found_get resolver | grep . || echo letsencrypt)"
         env_set JUMPHP_TRAEFIK_RESOLVER "$resolver"
       else
         env_set JUMPHP_SERVER_MODE traefik-passthrough
         ask http_entry "$(t "Point d'entrée HTTP (:80) de Traefik :" "HTTP (:80) entry point of Traefik:")" "$(found_get http | grep . || echo web)"
         env_set JUMPHP_TRAEFIK_HTTP_ENTRYPOINT "$http_entry"
       fi ;;
  esac
  if [ "$(env_get JUMPHP_SERVER_MODE)" != traefik-external ]; then
    ask email "$(t "E-mail pour Let's Encrypt (avis d'expiration des certificats, facultatif) :" "E-mail for Let's Encrypt (certificate expiry notices, optional):")" "$(env_get JUMPHP_ACME_EMAIL)"
    env_set JUMPHP_ACME_EMAIL "$email"
  fi
  local game; game="$(env_get JUMPHP_GAME)"
  ask dir "$(t 'Dossier sur le serveur :' 'Folder on the server:')" "$(env_get JUMPHP_SERVER_DIR || true)"
  [ -n "$dir" ] || dir="/opt/jumphp/${game:-site}"
  env_set JUMPHP_SERVER "$target"; env_set JUMPHP_DOMAIN "$domain"; env_set JUMPHP_SERVER_DIR "$dir"
  ok "$(t 'déploiement configuré dans .env' 'deployment set up in .env')"

  if ssh -n -o BatchMode=yes -o ConnectTimeout=8 "$target" 'docker compose version' >/dev/null 2>&1; then
    ok "$(t 'Docker est installé sur le serveur' 'Docker is installed on the server')"
  elif confirm "$(t "Docker manque sur le serveur : l'installer (bin/jumphp server:setup) ?" "Docker is missing on the server: install it (bin/jumphp server:setup)?")" y; then
    "$JUMPHP" server:setup || warn "bin/jumphp server:setup"
  fi
  if [ -f build/engine/php-web.wasm ] && [ -n "$game" ] && confirm "$(t "Déployer $game maintenant (bin/jumphp deploy) ?" "Deploy $game now (bin/jumphp deploy)?")" y; then
    jp deploy || warn "bin/jumphp deploy"
  else
    info "$(t 'Plus tard : bin/jumphp deploy' 'Later: bin/jumphp deploy')"
  fi
}

# ---------------------------------------------------------------------------------------------------------
# 5. GitHub

step_github() {
  step "5. GitHub"
  [ "$YES" = 1 ] && [ -z "$ONLY" ] && { info "--yes: $(t 'étape passée' 'step skipped')"; return 0; }
  if [ -z "$ONLY" ] && ! confirm "$(t 'Envoyer le projet sur GitHub ?' 'Push the project to GitHub?')" y; then
    return 0
  fi
  has git || return 1
  [ -d .git ] || { git init -q -b main 2>/dev/null || git init -q; }
  # a direct `git clone` of JumPHP: origin is the framework; it becomes "jumphp" (bin/jumphp update)
  local origin; origin="$(git remote get-url origin 2>/dev/null || true)"
  if [ -n "$origin" ] && [[ "$origin" =~ (davidnurdin|jumphp)/jumPhp ]] && ! git remote get-url jumphp >/dev/null 2>&1; then
    run git remote rename origin jumphp
    origin=""
  fi
  git remote get-url jumphp >/dev/null 2>&1 || git remote add jumphp "$UPSTREAM"
  # the framework only gives updates: nothing of the project can be pushed there
  git remote set-url --push jumphp no-push://the-jumphp-framework-only-gives-updates
  if [ -n "$origin" ]; then
    ok "$(t "le projet a déjà un dépôt : $origin" "the project already has a repository: $origin")"
  fi
  if [ -z "$(git config user.name)" ] || [ -z "$(git config user.email)" ]; then
    local name mail
    info "$(t "git a besoin d'un nom et d'un e-mail pour les commits (enregistrés dans ce projet seulement)" "git needs a name and an e-mail for the commits (stored in this project only)")"
    ask name "$(t 'Nom :' 'Name:')" "$(git config user.name)"
    ask mail "E-mail :" "$(git config user.email)"
    git config user.name "$name"; git config user.email "$mail"
  fi
  git add -A
  git diff --cached --quiet || run git commit -q -m "${GAME_TITLE:-$(env_get JUMPHP_GAME)}: JumPHP project"
  [ -n "$origin" ] && { run git push -u origin HEAD; return; }

  need gh "GitHub CLI (gh)" gh gh github-cli gh gh github-cli || { info "$(t 'sans gh : crée le dépôt sur github.com puis git remote add origin <url> && git push -u origin main' 'without gh: create the repository on github.com, then git remote add origin <url> && git push -u origin main')"; return 0; }
  gh auth status >/dev/null 2>&1 || run gh auth login || return 1
  local repo vis
  ask repo "$(t 'Nom du dépôt GitHub :' 'GitHub repository name:')" "$(env_get JUMPHP_GAME || basename "$ROOT")"
  choose vis "$(t 'Visibilité :' 'Visibility:')" 1 "$(t 'privé' 'private')" "$(t 'public (nécessaire pour GitHub Pages gratuit)' 'public (needed for free GitHub Pages)')"
  local flag=--private; [ "$vis" = 2 ] && flag=--public
  run gh repo create "$repo" "$flag" --source . --remote origin --push || return 1
  ok "$(gh repo view --json url -q .url 2>/dev/null)"
  local game; game="$(env_get JUMPHP_GAME)"
  if [ "$vis" = 2 ] && [ -n "$game" ] && [ -f build/engine/php-web.wasm ] \
     && confirm "$(t "Publier $game sur GitHub Pages (bin/jumphp pages $game) ?" "Publish $game on GitHub Pages (bin/jumphp pages $game)?")" n; then
    jp pages "$game"
  fi
}

# ---------------------------------------------------------------------------------------------------------
# 6. Claude Code

step_claude() {
  step "6. Claude Code"
  local game; game="${GAME_ID:-$(env_get JUMPHP_GAME)}"
  [ -n "$game" ] && [ -d "games/$game" ] || { warn "$(t 'pas de jeu : bin/jumphp init' 'no game: bin/jumphp init')"; return 0; }
  local title; title="$(sed -nE 's/^[[:space:]]*title:[[:space:]]*"?([^"]*)"?.*/\1/p' "$(project_yaml "games/$game")" | head -1)"
  local brief="games/$game/BRIEF.md" wish="" line
  info "$(t "Claude Code va écrire le jeu. Le reste du prompt est prêt (règles JumPHP, tests, captures) :" \
            "Claude Code will write the game. The rest of the prompt is ready (JumPHP rules, tests, screenshots):")"
  info "$(t 'décris seulement ce que doit faire ton jeu (plusieurs lignes possibles, ligne vide pour finir).' \
            'only describe what your game should do (several lines allowed, an empty line to finish).')"
  if [ "$YES" = 0 ]; then
    while printf '  > ' && IFS= read -r line && [ -n "$line" ]; do wish="$wish$line"$'\n'; done
  fi
  [ -n "$wish" ] || wish="$(t '(à compléter : décris le jeu ici)' '(to fill in: describe the game here)')"$'\n'
  cat > "$brief" <<EOF
# Brief: $title

You are working in a JumPHP project: retro 2D / 3D games in PHP that run on the desktop and in the browser.
Read AGENTS.md first (the framework contract: rules, commands, API), then games/$game/AGENTS.md.

The game is **games/$game** ("$title"). It was created from the skeleton (a tiny platformer): replace its
scenes, entities, texts and sounds with this game:

$wish
How to work:
- Everything runs through bin/jumphp (Docker). Never install PHP or run php / composer on the host.
- Look at games/pong (menus, AI, local and online play) and games/jumpball (3D with WebGL) for models.
- Iterate with bin/jumphp test $game and screenshots (bin/jumphp shot $game ...): open the PNGs and check
  that what you see is what you meant.
- Done means AGENTS.md section 10: tests pass, screenshots look right, menus / pause / game over work,
  bin/jumphp lint passes, bin/jumphp build $game + bin/jumphp webshot $game pass, and games/$game/AGENTS.md +
  README.md describe the game.
- Ask me before publishing anything (git push, bin/jumphp deploy, bin/jumphp pages).
EOF
  ok "$(t "prompt écrit dans $brief" "prompt written to $brief")"
  if [ -d .git ] && has git; then git add "$brief" && git commit -q -m "$title: brief" -- "$brief" 2>/dev/null; fi
  if ! has claude; then
    warn "Claude Code: $(t 'absent' 'missing')"
    if [ "$YES" = 0 ] && confirm "$(t 'Installer Claude Code (installateur officiel, claude.ai/install.sh) ?' 'Install Claude Code (official installer, claude.ai/install.sh)?')" y; then
      local script; script="$(mktemp)"
      run curl -fsSL https://claude.ai/install.sh -o "$script" && run bash "$script"
      rm -f "$script"
      export PATH="$HOME/.local/bin:$PATH"
    fi
  fi
  if has claude && [ "$YES" = 0 ] && [ -t 0 ] && [ -t 1 ] && confirm "$(t 'Lancer Claude Code maintenant avec ce prompt ?' 'Start Claude Code now with this prompt?')" y; then
    info "$(t 'bon jeu !' 'have fun!')"
    exec claude "$(cat "$brief")"
  fi
  info "$(t 'Plus tard :' 'Later:') claude \"\$(cat $brief)\""
}

summary() {
  local game; game="$(env_get JUMPHP_GAME)"
  step "$(t 'Prêt' 'Ready')"
  info "bin/jumphp run $game        $(t '# jouer sur le bureau' '# play on the desktop')"
  info "bin/jumphp build $game && bin/jumphp serve $game   $(t '# dans le navigateur : http://localhost:8080' '# in the browser: http://localhost:8080')"
  info "bin/jumphp test $game       $(t '# tests' '# tests')"
  info "bin/jumphp deploy           $(t '# sur ton serveur (bin/jumphp deploy:setup)' '# to your server (bin/jumphp deploy:setup)')"
  info "bin/jumphp update           $(t '# nouvelle version de JumPHP' '# a new version of JumPHP')"
  info "bin/jumphp cli:install      $(t '# la commande jumphp pour tout le système' '# the jumphp command for the whole system')"
  info "bin/jumphp help"
}

# ---------------------------------------------------------------------------------------------------------

case "$ONLY" in
  deploy) step_deploy; exit ;;
  github) step_github; exit ;;
  claude) step_claude; exit ;;
esac

printf '\n\033[1;36m  JumPHP\033[0m — %s\n' "$(t 'des jeux rétro en PHP, sur le bureau et dans le navigateur' 'retro games in PHP, on the desktop and in the browser')"
info "$(t "Cet assistant prépare le projet ; chaque étape demande avant d'agir. Ctrl+C pour arrêter, bin/jumphp init pour reprendre." \
          "This wizard sets up the project; each step asks before acting. Ctrl+C to stop, bin/jumphp init to resume.")"

step_tools "$@" || exit 1
step_cli
step_game || exit 1
step_build
step_deploy
step_github
summary
step_claude
