#!/usr/bin/env bash

set -u
umask 022

_start_ts=$SECONDS
_cleaned=0
_cleanup() {
    [ "$_cleaned" -eq 1 ] && return; _cleaned=1
    wait 2>/dev/null || true
    rm -f "$PREFIX/tmp/zenpanel_admin_seed.php" "$PREFIX/tmp/fb.tgz" "$PREFIX/tmp/acme.sh" 2>/dev/null
    rm -rf "$PREFIX/tmp/acme.sh-src" 2>/dev/null
}
trap '_cleanup' EXIT
trap 'printf "\n\n  %s\n\n" "${c_yellow}⚠ Installation interrupted.${c_reset}"; _cleanup; exit 130' INT TERM

PREFIX="${PREFIX:-/data/data/com.termux/files/usr}"
HOME_DIR="${HOME:-$PREFIX/home}"
SOURCE_DIR="$HOME_DIR/storage/downloads"
PANEL_DIR="$SOURCE_DIR/hosting"
SOCK="$PREFIX/var/run/mysqld/mysqld.sock"
DBNAME="panel"
LOG_DIR="$PREFIX/var/panel"
PORT="${ZENPANEL_PORT:-8080}"

GIT_URL="${ZENPANEL_REPO_URL:-https://github.com/zarifsikder/zenpanel.git}"
FEATURES_USER="${ZENPANEL_FEATURES_USER:-user}"

c_green=$'\033[32m'; c_yellow=$'\033[33m'; c_red=$'\033[31m'; c_cyan=$'\033[36m'
c_magenta=$'\033[35m'; c_bold=$'\033[1m'; c_dim=$'\033[2m'; c_reset=$'\033[0m'

ok()   { printf '%s\n' "  ${c_green}✔${c_reset}  $*"; }
info() { printf '%s\n' "    ${c_dim}$*${c_reset}"; }
warn() { printf '%s\n' "  ${c_yellow}⚠${c_reset}  $*"; }
die()  { printf '\n  %s\n\n' "${c_red}✖ ERROR: $*${c_reset}" >&2; exit 1; }

banner() {
    printf '\n'
    printf '%s\n' "${c_bold}${c_magenta}   ⚡${c_reset}  ${c_bold}ZRPanel  ${c_green}installer${c_reset}"
    printf '%s\n' "${c_dim}   A cPanel/WHM-style hosting panel · Android · Termux${c_reset}"
}

section() {
    printf '\n'
    printf '%s\n' "  ${c_bold}Step ${c_cyan}$1${c_reset}${c_bold} · $2${c_reset}"
    printf '%s\n' "  ${c_dim}$(printf '%*s' 52 '' | tr ' ' '─')${c_reset}"
}

_spinner=('⠋' '⠙' '⠹' '⠸' '⠼' '⠴' '⠦' '⠧' '⠇' '⠏')

spin_run() {
    local label="$1"; shift
    local sp n=0
    "$@" >/dev/null 2>&1 &
    local pid=$!
    printf '\r  %s %s' "${c_cyan}⠋${c_reset}" "$label"
    while kill -0 "$pid" 2>/dev/null; do
        printf '\r  %s %s' "${c_cyan}${_spinner[n]}${c_reset}" "$label"
        n=$(( (n + 1) % ${#_spinner[@]} ))
        sleep 0.1
    done
    wait "$pid"; local rc=$?
    if [ "$rc" -eq 0 ]; then
        printf '\r  %s %s\n' "${c_green}✔${c_reset}" "$label"
    else
        printf '\r  %s %s\n' "${c_red}✖${c_reset}" "$label"
    fi
    return "$rc"
}

wait_db() {
    local n=0 i=0
    printf '\r  %s %s' "${c_cyan}⠋${c_reset}" "Waiting for MariaDB…"
    while ! "$MYSQL_CMD" --socket="$SOCK" -u root -e "SELECT 1" >/dev/null 2>&1; do
        if [ "$n" -ge 15 ]; then
            printf '\r  %s %s\n' "${c_red}✖${c_reset}" "MariaDB did not respond"
            return 1
        fi
        n=$((n + 1)); i=$(( (i + 1) % ${#_spinner[@]} ))
        printf '\r  %s %s' "${c_cyan}${_spinner[i]}${c_reset}" "Waiting for MariaDB…"
        sleep 1
    done
    printf '\r  %s %s\n' "${c_green}✔${c_reset}" "MariaDB is up"
}

prompt() {
    if [ -t 0 ] && [ -r /dev/tty ]; then
        printf "%s" "$*" > /dev/tty
        local ans; IFS= read -r ans < /dev/tty || true
        printf "%s" "$ans"
    fi
}

php_q() { printf "%s" "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/\\\\'/g"; }

gen_secret() { head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n'; }

if command -v mariadb >/dev/null 2>&1; then
    MYSQL_CMD="mariadb"
elif command -v mysql >/dev/null 2>&1; then
    MYSQL_CMD="mysql"
else
    MYSQL_CMD="mysql"
fi

get_cfg() {
    [ -f config.local.php ] || return 0
    php -r '$c=@require "config.local.php"; echo is_array($c)&&isset($c[$argv[1]])&&is_scalar($c[$argv[1]])?$c[$argv[1]]:"";' "$1" 2>/dev/null
}

banner
section 0 "Environment & prerequisites"

[ -x "$PREFIX/bin/bash" ] || die "This script must run inside Termux."
if [ ! -d "$SOURCE_DIR" ]; then
    ok "Setting up shared storage (accept the Android permission prompt)…"
    termux-setup-storage >/dev/null 2>&1 || true
    for _ in $(seq 1 30); do
        [ -d "$SOURCE_DIR" ] && break
        sleep 1
    done
fi
[ -d "$SOURCE_DIR" ] || die "Could not access ~/storage/downloads. Run 'termux-setup-storage' and grant access, then retry."

section 1 "Runtimes & packages"

# --- System update: refresh & upgrade BOTH package front-ends. -------------
# `pkg` handles termux-main; the direct `apt` pass covers the extra GLIBC /
# x11 / void repos used by this setup. Neither step is fatal — the installer
# keeps going even if a repo is unreachable or a package cannot be upgraded.
info "Refreshing & upgrading packages (pkg)…"
spin_run "pkg update && pkg upgrade" bash -c "pkg update -y && pkg upgrade -y" \
    || warn "pkg update/upgrade had issues — installing required packages anyway."

info "Refreshing & upgrading packages (apt)…"
spin_run "apt update && apt upgrade" bash -c "apt update -y && apt upgrade -y" \
    || warn "apt update/upgrade had issues — continuing with the package install."

NODEJS_PKG="${ZENPANEL_NODEJS_PKG:-nodejs}"
spin_run "Installing PHP · MariaDB · Cloudflare · Node.js · Python · TLS tooling" \
    pkg install -y php mariadb cloudflared git curl wget unzip procps openssl-tool \
        "$NODEJS_PKG" npm python stunnel

command -v php     >/dev/null 2>&1 || die "php was not installed."
command -v mariadbd >/dev/null 2>&1 || die "mariadb was not installed."

_php_ver="$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo '—')"
_maria_ver="$(mariadbd --version 2>/dev/null | awk '{print $3}' || echo '—')"
_arch="$(uname -m)"
_cf_status="missing";  command -v cloudflared >/dev/null 2>&1 && _cf_status="installed"

_node_ver="—"; command -v node >/dev/null 2>&1 && _node_ver="$(node -v 2>/dev/null | sed 's/^v//' || true)"
_npm_ver="—";  command -v npm >/dev/null 2>&1 && _npm_ver="$(npm -v 2>/dev/null || true)"
_py_ver="—";   command -v python3 >/dev/null 2>&1 && _py_ver="$(python3 --version 2>/dev/null | sed -E 's/Python //' || true)"

# --- Optional runtimes & tooling (Web Apps, WordPress Toolkit, local TLS) ----
# None of these are fatal if they fail — each feature degrades gracefully:

# 1) Node.js process managers + package managers used by Web Apps (pm2 runs the
#    apps; pnpm/yarn are auto-detected from lockfiles by webapps.php).
if command -v npm >/dev/null 2>&1 && [ "${ZENPANEL_SKIP_PM2:-0}" != "1" ] && ! command -v pm2 >/dev/null 2>&1; then
    spin_run "Installing pm2 · pnpm · yarn (Node.js runners)" \
        npm install -g pm2 pnpm yarn \
        || warn "pm2/pnpm/yarn install failed — Node Web Apps need a process manager."
    # Termux ships env(1) under $PREFIX/bin, but npm's global wrappers use the
    # /usr/bin/env shebang — patch them so pm2/pnpm/yarn actually run.
    if command -v termux-fix-shebang >/dev/null 2>&1; then
        info "Fixing executable shebangs (Termux)…"
        for _bin in pm2 pm2-dev pm2-docker pnpm yarn; do
            _p="$(command -v "$_bin" 2>/dev/null || true)"
            [ -n "$_p" ] && termux-fix-shebang "$_p" 2>/dev/null || true
        done
    fi
fi

# 2) WordPress Toolkit (WP-CLI). The toolkit also fetches it per-account on
#    demand; a system copy here makes first installs instant.
if [ "${ZENPANEL_SKIP_WPCLI:-0}" != "1" ] && [ ! -s "$PREFIX/bin/wp-cli.phar" ]; then
    spin_run "Downloading WP-CLI (WordPress Toolkit)…" \
        curl -fsSL -o "$PREFIX/bin/wp-cli.phar" \
            https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
    if [ -s "$PREFIX/bin/wp-cli.phar" ] && php "$PREFIX/bin/wp-cli.phar" --version >/dev/null 2>&1; then
        chmod +x "$PREFIX/bin/wp-cli.phar"
        ok "WordPress Toolkit CLI installed ($PREFIX/bin/wp-cli.phar)"
    else
        rm -f "$PREFIX/bin/wp-cli.phar"
        warn "WP-CLI download failed — the WordPress Toolkit fetches it automatically per account."
    fi
fi

# 3) acme.sh — Let's Encrypt client behind the optional local TLS terminator
#    (tls.sh / WHM → Hostname). Only needed when HTTPS termination is used.
#    N.B. this is not packaged for Termux; cloning + fixing the shebang + the
#    bundled --nocron install is the reliable path here.
ACME_HOME="$HOME_DIR/.acme.sh"
if [ "${ZENPANEL_SKIP_ACME:-0}" != "1" ] && [ ! -x "$ACME_HOME/acme.sh" ]; then
    info "Installing acme.sh (Let's Encrypt client for the local TLS terminator)…"
    if git clone --depth 1 https://github.com/acmesh-official/acme.sh.git "$PREFIX/tmp/acme.sh-src" >/dev/null 2>&1 \
        && termux-fix-shebang "$PREFIX/tmp/acme.sh-src/acme.sh" >/dev/null 2>&1 \
        && (cd "$PREFIX/tmp/acme.sh-src" && ./acme.sh --install --nocron --home "$ACME_HOME") >/dev/null 2>&1 \
        && [ -x "$ACME_HOME/acme.sh" ]; then
        ok "acme.sh installed ($("$ACME_HOME/acme.sh" --version 2>/dev/null | head -1))"
    else
        warn "acme.sh install failed — tls.sh needs it if you enable HTTPS termination."
    fi
    rm -rf "$PREFIX/tmp/acme.sh-src"
fi

# 4) nsd — authoritative DNS for the built-in DNS sync (dns_sync.php). It is
#    only shipped for Linux/Docker; on Termux/Android this is a soft-skip.
if command -v pkg >/dev/null 2>&1 && [ "${ZENPANEL_SKIP_NSD:-0}" != "1" ]; then
    spin_run "Installing nsd (authoritative DNS)…" pkg install -y nsd \
        || warn "nsd is not packaged for this platform — DNS sync needs the Linux/Docker flavour."
fi

_pm2_status="missing"; command -v pm2 >/dev/null 2>&1 && _pm2_status="installed"
_wpcli_status="missing"; [ -x "$PREFIX/bin/wp-cli.phar" ] && _wpcli_status="installed"
_stunnel_status="missing"; command -v stunnel >/dev/null 2>&1 && _stunnel_status="installed"

printf '\n'
printf '  %s\n' "${c_bold}System${c_reset}"
printf '  %s\n' "${c_dim}────────────────────────────────${c_reset}"
printf '  %-14s  %s\n' "Architecture" "$_arch"
printf '  %-14s  %s\n' "PHP"          "$_php_ver"
printf '  %-14s  %s\n' "MariaDB"      "$_maria_ver"
printf '  %-14s  %s\n' "Node.js"      "$_node_ver"
printf '  %-14s  %s\n' "npm"          "$_npm_ver"
printf '  %-14s  %s\n' "Python"       "$_py_ver"
printf '  %-14s  %s\n' "pm2"          "$_pm2_status"
printf '  %-14s  %s\n' "WP-CLI"       "$_wpcli_status"
printf '  %-14s  %s\n' "stunnel"      "$_stunnel_status"
printf '  %-14s  %s\n' "Cloudflared"  "$_cf_status"
printf '\n'

section 2 "Repository"
if [[ "$GIT_URL" == *USERNAME/zenpanel* ]]; then
    die "You are running the example installer. Set ZENPANEL_REPO_URL=https://github.com/<you>/<repo>.git, or edit the default at the top of install.sh, then re-run."
fi

if [ -d "$PANEL_DIR/.git" ]; then
    ok "Updating existing installation…"
    spin_run "git pull" git -C "$PANEL_DIR" pull --ff-only --autostash || git -C "$PANEL_DIR" pull >/dev/null
elif [ -d "$PANEL_DIR" ]; then
    warn "Non-git directory exists at $PANEL_DIR — backing it up and re-installing."
    mv "$PANEL_DIR" "$SOURCE_DIR/hosting-backup-$(date +%s)"
    spin_run "Cloning repository…" git clone --depth 1 "$GIT_URL" "$PANEL_DIR" \
        || die "git clone failed ($GIT_URL)."
else
    ok "Cloning repository into $PANEL_DIR…"
    spin_run "Cloning repository…" git clone --depth 1 "$GIT_URL" "$PANEL_DIR" \
        || die "git clone failed ($GIT_URL)."
fi

ok "Removing installer files from the device…"
rm -f "$PANEL_DIR/install.sh" "$PANEL_DIR/README.md" 2>/dev/null

[ -f "$PANEL_DIR/config.php" ] || die "Clone/update finished but config.php is missing."

section 3 "MariaDB + panel database"
ok "Starting MariaDB…"
mkdir -p "$PREFIX/var/run/mysqld" "$LOG_DIR"
if ! "$MYSQL_CMD" --socket="$SOCK" -u root -e "SELECT 1" >/dev/null 2>&1; then
    if [ ! -d "$PREFIX/var/lib/mysql/mysql" ]; then
        info "Initializing database files…"
        spin_run "mariadb-install-db" \
            mariadb-install-db --datadir="$PREFIX/var/lib/mysql" --auth-root-authentication-method=normal \
            || spin_run "mariadb-install-db (fallback)" \
                   mariadb-install-db --datadir="$PREFIX/var/lib/mysql" \
                   || warn "mariadb-install-db failed; falling back to automatic initialization."
    fi
    setsid nohup mariadbd --datadir="$PREFIX/var/lib/mysql" --socket="$SOCK" \
        --pid-file="$LOG_DIR/mariadb.pid" --log-error="$LOG_DIR/mariadb.log" >/dev/null 2>&1 </dev/null &
    wait_db || :
fi
"$MYSQL_CMD" --socket="$SOCK" -u root -e "SELECT 1" >/dev/null 2>&1 \
    || die "MariaDB did not come up (log: $LOG_DIR/mariadb.log)."

ok "Creating the '$DBNAME' database for the panel…"
"$MYSQL_CMD" --socket="$SOCK" -u root -e "CREATE DATABASE IF NOT EXISTS \`$DBNAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

ok "Connecting the panel to '$DBNAME' and building the schema…"
cd "$PANEL_DIR" || die "Cannot cd $PANEL_DIR"
PHP_CONNECT='
require "config.php";
init_db();
echo "tables_ok\n";
'
php -r "$PHP_CONNECT" 2>/dev/null && info "Database schema created." \
    || warn "Schema init printed a warning; it will also run automatically on first page load."

section 4 "WHM admin account"
ADMIN_USER="${ZENPANEL_ADMIN_USER:-admin}"
ADMIN_PASS="${ZENPANEL_ADMIN_PASS:-}"
SITE_DOMAIN="$(get_cfg SITE_DOMAIN)"
[ -n "$SITE_DOMAIN" ] || SITE_DOMAIN="${ZENPANEL_SITE_DOMAIN:-}"
ok "Ensuring the WHM admin account…"

cat > "$PREFIX/tmp/zenpanel_admin_seed.php" <<'PHPEOF'
<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
require $argv[1] . '/config.php';
init_db();

$pdo = db();
$user = strtolower(trim((string)$argv[2]));
$pass = (string)$argv[3];
$new  = '';

$domain = trim((string)($argv[4] ?? ''));
if ($domain !== '' && $domain !== 'localhost') {
    $h = function_exists('valid_hostname') ? valid_hostname($domain) : null;
    if ($h !== null) {
        $pdo->prepare("REPLACE INTO config (key_name, value) VALUES ('global_domain', ?)")->execute([$h]);
        echo "DOMAIN:$h\n";
    }
}

if (!preg_match('/^[a-z0-9_]{3,32}$/', $user)) {
    $user = 'admin';
}

$st = $pdo->prepare("SELECT id FROM users WHERE role = 'whm' AND username = ?");
$st->execute([$user]);
$existing = $st->fetchColumn();

if (strlen($pass) >= 6) {
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    if ($existing) {
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $existing]);
    } else {
        $pdo->prepare("INSERT INTO users (username, password, email, role, status) VALUES (?, ?, NULL, 'whm', 'active')")
            ->execute([$user, $hash]);
    }
    $new = $pass;
} elseif ($existing) {
    echo "ADMIN_EXISTS\n";
} else {
    $pass = substr(strtr(base64_encode(random_bytes(12)), '+/', '-_'), 0, 16);
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password, email, role, status) VALUES (?, ?, NULL, 'whm', 'active')")
        ->execute([$user, $hash]);
    $new = $pass;
}

echo "ADMIN_USER:$user\n";
if ($new !== '') {
    echo "ADMIN_PASS:$new\n";
}
PHPEOF

ADMIN_OUT="$(php "$PREFIX/tmp/zenpanel_admin_seed.php" "$PANEL_DIR" "$ADMIN_USER" "$ADMIN_PASS" "$SITE_DOMAIN" 2>/dev/null)"
rm -f "$PREFIX/tmp/zenpanel_admin_seed.php"

ADMIN_USER="$(printf '%s' "$ADMIN_OUT" | sed -n 's/^ADMIN_USER://p')"
[ -n "$ADMIN_USER" ] || ADMIN_USER="${ZENPANEL_ADMIN_USER:-admin}"
NEW_ADMIN_PASS="$(printf '%s' "$ADMIN_OUT" | sed -n 's/^ADMIN_PASS://p')"
if printf '%s' "$ADMIN_OUT" | grep -q '^ADMIN_EXISTS$'; then
    info "Existing WHM admin kept (password unchanged)."
elif [ -n "$NEW_ADMIN_PASS" ]; then
    info "WHM admin '$ADMIN_USER' ready."
fi

section 5 "Secrets → config.local.php"
cd "$PANEL_DIR"

CF_TOKEN="$(get_cfg CF_API_TOKEN)";    [ -n "$CF_TOKEN" ] || CF_TOKEN="${ZENPANEL_CF_TOKEN:-}"
CF_ZONE="$(get_cfg CF_ZONE_ID)";       [ -n "$CF_ZONE" ] || CF_ZONE="${ZENPANEL_CF_ZONE:-}"
CF_TUNNEL="$(get_cfg CF_TUNNEL_ID)";   [ -n "$CF_TUNNEL" ] || CF_TUNNEL="${ZENPANEL_CF_TUNNEL_ID:-}"
SERVER_IP="$(get_cfg SERVER_IP)";      [ -n "$SERVER_IP" ] || SERVER_IP="${ZENPANEL_SERVER_IP:-}"
SITE_DOMAIN="$(get_cfg SITE_DOMAIN)";  [ -n "$SITE_DOMAIN" ] || SITE_DOMAIN="${ZENPANEL_SITE_DOMAIN:-localhost}"
FEAT_USER="$(get_cfg FEATURES_USERNAME)"; [ -n "$FEAT_USER" ] || FEAT_USER="$FEATURES_USER"
FEAT_HASH="$(get_cfg FEATURES_PASSWORD_HASH)"
SHELL_KEY="$(get_cfg SECRET_SHELL_KEY)"

[ -n "$SERVER_IP" ] || SERVER_IP="$(curl -fsSL --max-time 5 https://ifconfig.me 2>/dev/null || true)"

FEAT_PASS=""
if [ -z "$FEAT_HASH" ]; then
    FEAT_PASS="$(head -c 9 /dev/urandom | base64 | tr -d '/+=')"
    FEAT_HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT);' "$FEAT_PASS")"
fi
[ -n "$SHELL_KEY" ] || SHELL_KEY="z3n_$(gen_secret)"

if [ -f config.local.php ]; then
    ok "Keeping existing config.local.php (re-encoding with current values)."
fi
cat > config.local.php <<CFG
<?php
return [
    'CF_API_TOKEN'          => '$(php_q "$CF_TOKEN")',
    'CF_ZONE_ID'            => '$(php_q "$CF_ZONE")',
    'CF_TUNNEL_ID'          => '$(php_q "$CF_TUNNEL")',
    'SERVER_IP'             => '$(php_q "$SERVER_IP")',
    'SITE_DOMAIN'           => '$(php_q "$SITE_DOMAIN")',
    'FEATURES_USERNAME'     => '$(php_q "$FEAT_USER")',
    'FEATURES_PASSWORD_HASH' => '$(php_q "$FEAT_HASH")',
    'SECRET_SHELL_KEY'      => '$(php_q "$SHELL_KEY")',
];
CFG
chmod 600 config.local.php
php -l config.local.php >/dev/null 2>&1 || die "Generated config.local.php failed lint."

TUNNEL_DIR="$PANEL_DIR/tunnel_data/panel"
TOKEN_FILE="$TUNNEL_DIR/connector.token"
TUN_TOKEN="${ZENPANEL_CF_TUNNEL_TOKEN:-$(cat "$TOKEN_FILE" 2>/dev/null || true)}"
if [ -z "$TUN_TOKEN" ]; then
    TUN_TOKEN="$(prompt "Paste your Cloudflare tunnel connector token (blank to skip tunnel):")"
fi
if [ -n "$TUN_TOKEN" ]; then
    mkdir -p "$TUNNEL_DIR"
    printf '%s' "$TUN_TOKEN" > "$TOKEN_FILE"
    chmod 600 "$TOKEN_FILE"
    ok "Tunnel token saved ($TOKEN_FILE)."
else
    warn "No tunnel token — cloudflared will be skipped until you add one."
fi

section 6 "Launchers (~/start, ~/off)"
cat > "$HOME_DIR/start" <<'WRAPPER'
#!/data/data/com.termux/files/usr/bin/bash
ROOT="$HOME/storage/downloads/hosting"
if [ -f "$ROOT/scripts/start" ]; then
    exec bash "$ROOT/scripts/start"
fi
echo "ZRPanel launcher not found at $ROOT/scripts/start"
echo "Re-run the installer to fix it."
WRAPPER

cat > "$HOME_DIR/off" <<'WRAPPER'
#!/data/data/com.termux/files/usr/bin/bash
ROOT="$HOME/storage/downloads/hosting"
if [ -f "$ROOT/scripts/off" ]; then
    exec bash "$ROOT/scripts/off"
fi
echo "ZRPanel stopper not found at $ROOT/scripts/off"
echo "Re-run the installer to fix it."
WRAPPER

chmod +x "$HOME_DIR/start" "$HOME_DIR/off"
ok "Launchers created:  ~/start   ~/off"

section 7 "Summary"
_elapsed=$(( SECONDS - _start_ts ))
printf '\n'
printf '  %s\n' "${c_bold}${c_green}✅  ZRPanel install complete${c_reset}  ${c_dim}(${_elapsed}s)${c_reset}"
printf '  %s\n' "${c_dim}───────────────────────────────────────────────────${c_reset}"
printf '  %-13s  %s\n' "Panel dir"  "$PANEL_DIR"
printf '  %-13s  %s\n' "Database"   "mysql://root@local/$DBNAME  ${c_dim}(tables auto-created)${c_reset}"
printf '  %-13s  %s\n' "Launchers"  "~/start  ·  ~/off"
printf '  %-13s  %s\n' "Panel"      "http://localhost:$PORT"
printf '  %-13s  %s\n' "WHM"        "http://localhost:$PORT/whm"
if [ "$SITE_DOMAIN" != "localhost" ]; then
    printf '  %-13s  %s\n' "Tunnel" "https://$SITE_DOMAIN  ${c_dim}(change in WHM → Tunnels)${c_reset}"
fi
printf '\n'
printf '  %s\n' "${c_bold}${c_yellow}┌───────────────────────────────────────────────┐${c_reset}"
printf '  %s\n' "${c_bold}${c_yellow}│${c_reset}  ${c_bold}Admin credentials${c_reset}"
printf '  %s\n' "${c_bold}${c_yellow}│${c_reset}"
printf '  %s\n' "${c_bold}${c_yellow}│${c_reset}  Username : ${c_bold}${c_green}$ADMIN_USER${c_reset}"
printf '  %s\n' "${c_bold}${c_yellow}│${c_reset}  Password : ${c_bold}${c_green}${NEW_ADMIN_PASS:-<existing — kept>}${c_reset}"
printf '  %s\n' "${c_bold}${c_yellow}│${c_reset}"
printf '  %s\n' "${c_bold}${c_yellow}│${c_reset}  ${c_dim}change/reset → WHM → Accounts → Reset Password${c_reset}"
printf '  %s\n' "${c_bold}${c_yellow}└───────────────────────────────────────────────┘${c_reset}"
if [ -n "$FEAT_PASS" ]; then
    printf '\n'
    printf '  %s\n' "${c_bold}${c_red}┌───────────────────────────────────────────────┐${c_reset}"
    printf '  %s\n' "${c_bold}${c_red}│${c_reset}  ${c_bold}/features password (save this — shown once)${c_reset}"
    printf '  %s\n' "${c_bold}${c_red}│${c_reset}  ${c_bold}${c_green}$FEAT_USER${c_reset} / ${c_bold}${c_green}$FEAT_PASS${c_reset}"
    printf '  %s\n' "${c_bold}${c_red}└───────────────────────────────────────────────┘${c_reset}"
fi
printf '\n'
printf '  %s\n' "${c_bold}Quick start${c_reset}"
printf '  %s\n' "${c_dim}───────────────────────────────────────────────────${c_reset}"
printf '  ~/start   —  start the panel'
printf '  ~/off     —  stop the panel'
printf '  Panel     —  http://localhost:$PORT'
printf '  WHM       —  http://localhost:$PORT/whm'
[ "$SITE_DOMAIN" != "localhost" ] && printf '  Public    —  https://$SITE_DOMAIN'
printf '  %s\n' "  ${c_dim}───────────────────────────────────────────────────${c_reset}"
printf '\n'

if [ "${ZENPANEL_NO_START:-0}" != "1" ]; then
    ok "Starting the panel now… (rerun anytime with: ~/start)"
    bash "$HOME_DIR/start" || warn "The launcher reported trouble — check the logs listed above."
fi
