#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
#  ZRPanel Installer v2.0
#  Premium UI · Live Progress % · Line-Level Error Reporting
# ═══════════════════════════════════════════════════════════════════════════

set -u
set -o pipefail
set -E   # ERR trap inherited by functions/subshells

umask 022

# ─── Auto-yes environment ───────────────────────────────────────────────────
export DEBIAN_FRONTEND=noninteractive
export APT_LISTCHANGES_FRONTEND=none
export NEEDRESTART_MODE=a
export GIT_TERMINAL_PROMPT=0
export PIP_DISABLE_PIP_VERSION_CHECK=1
export NPM_CONFIG_UPDATE_NOTIFIER=false
export NPM_CONFIG_FUND=false
export NPM_CONFIG_AUDIT=false

# ─── Globals ────────────────────────────────────────────────────────────────
_start_ts=$SECONDS
_cleaned=0
CURRENT_STEP=0
TOTAL_STEPS=7
CURRENT_STEP_NAME="init"
STEP_RESULTS=()          # "step|status|duration"
VERBOSE="${ZENPANEL_VERBOSE:-0}"

PREFIX="${PREFIX:-/data/data/com.termux/files/usr}"
HOME_DIR="${HOME:-$PREFIX/home}"
SOURCE_DIR="$HOME_DIR/storage/downloads"
PANEL_DIR="$SOURCE_DIR/hosting"
SOCK="$PREFIX/var/run/mysqld/mysqld.sock"
DBNAME="panel"
LOG_DIR="$PREFIX/var/panel"
ERR_LOG="$LOG_DIR/install-errors.log"
PORT="${ZENPANEL_PORT:-8080}"

GIT_URL="${ZENPANEL_REPO_URL:-https://github.com/zarifsikder/zenpanel.git}"
FEATURES_USER="${ZENPANEL_FEATURES_USER:-user}"

mkdir -p "$LOG_DIR" 2>/dev/null || true

# ─── Palette ────────────────────────────────────────────────────────────────
c_green=$'\033[38;5;42m'
c_yellow=$'\033[38;5;220m'
c_red=$'\033[38;5;203m'
c_cyan=$'\033[38;5;44m'
c_magenta=$'\033[38;5;170m'
c_blue=$'\033[38;5;69m'
c_gray=$'\033[38;5;245m'
c_orange=$'\033[38;5;208m'
c_bold=$'\033[1m'
c_dim=$'\033[2m'
c_reset=$'\033[0m'

# ─── Terminal width ─────────────────────────────────────────────────────────
_TERM_W=$(tput cols 2>/dev/null || echo 64)
[ "$_TERM_W" -gt 80 ] && _TERM_W=80
[ "$_TERM_W" -lt 50 ] && _TERM_W=50
_BOX_INNER=$(( _TERM_W - 4 ))
_LINE_W=$(( _TERM_W - 6 ))

# ─── Cleanup ────────────────────────────────────────────────────────────────
_cleanup() {
    [ "$_cleaned" -eq 1 ] && return; _cleaned=1
    wait 2>/dev/null || true
    rm -f "$PREFIX/tmp/zenpanel_admin_seed.php" "$PREFIX/tmp/fb.tgz" "$PREFIX/tmp/acme.sh" 2>/dev/null
    rm -rf "$PREFIX/tmp/acme.sh-src" 2>/dev/null
}
trap '_cleanup' EXIT
trap 'printf "\n\n  %s %s\n\n" "${c_yellow}⚠${c_reset}" "Installation interrupted."; _cleanup; exit 130' INT TERM

# ─── Error Trap ─────────────────────────────────────────────────────────────
_on_err() {
    local line="$1" cmd="$2" code="$3"
    local pct; pct=$(( CURRENT_STEP * 100 / TOTAL_STEPS ))

    printf '\n'
    printf '  %s╭─ ERROR ─────────────────────────────────────%s\n' "${c_red}${c_bold}" "${c_reset}"
    printf '  %s│%s %sFile:%s      %s\n'    "${c_red}" "${c_reset}" "${c_bold}" "${c_reset}" "install.sh"
    printf '  %s│%s %sLine:%s      %s%s%s\n' "${c_red}" "${c_reset}" "${c_bold}" "${c_reset}" "${c_yellow}" "$line" "${c_reset}"
    printf '  %s│%s %sExit:%s      %s%s%s\n' "${c_red}" "${c_reset}" "${c_bold}" "${c_reset}" "${c_red}" "$code" "${c_reset}"
    printf '  %s│%s %sStep:%s      %s%d/%d%s (%s) · %d%%\n' \
        "${c_red}" "${c_reset}" "${c_bold}" "${c_reset}" \
        "${c_cyan}" "$CURRENT_STEP" "$TOTAL_STEPS" "${c_reset}" \
        "$CURRENT_STEP_NAME" "$pct"
    printf '  %s│%s %sCommand:%s   %s\n' "${c_red}" "${c_reset}" "${c_bold}" "${c_reset}" "$cmd"
    printf '  %s│%s %sLog:%s       %s\n' "${c_red}" "${c_reset}" "${c_bold}" "${c_reset}" "$ERR_LOG"
    printf '  %s╰──────────────────────────────────────────────%s\n\n' "${c_red}${c_bold}" "${c_reset}"

    {
        printf '[%s] line=%s exit=%s step=%d/%d (%s) cmd=%s\n' \
            "$(date '+%Y-%m-%d %H:%M:%S')" "$line" "$code" \
            "$CURRENT_STEP" "$TOTAL_STEPS" "$CURRENT_STEP_NAME" "$cmd"
    } >> "$ERR_LOG" 2>/dev/null || true
}
trap '_on_err "$LINENO" "$BASH_COMMAND" "$?"' ERR

# ─── Status helpers ─────────────────────────────────────────────────────────
ok()   { printf '  %s %s\n' "${c_green}✔${c_reset}" "$*"; }
info() { printf '  %s %s\n' "${c_gray}·${c_reset}" "${c_dim}$*${c_reset}"; }
warn() { printf '  %s %s\n' "${c_yellow}⚠${c_reset}" "$*"; }
die()  { printf '\n  %s %s\n\n' "${c_red}✖ ERROR:${c_reset}" "$*" >&2; exit 1; }
skip() { printf '  %s %s\n' "${c_gray}○${c_reset}" "${c_dim}$*${c_reset}"; }

# ─── Progress bar ───────────────────────────────────────────────────────────
_progress_bar() {
    local pct="$1" width=24 filled empty
    filled=$(( pct * width / 100 ))
    empty=$(( width - filled ))
    printf '%s' "${c_green}"
    printf '█%.0s' $(seq 1 "$filled" 2>/dev/null) 2>/dev/null || true
    printf '%s' "${c_gray}${c_dim}"
    printf '░%.0s' $(seq 1 "$empty" 2>/dev/null) 2>/dev/null || true
    printf '%s' "${c_reset}"
}

# ─── Section rendering ──────────────────────────────────────────────────────
section() {
    local num="$1" total="$2" title="$3"
    CURRENT_STEP="$num"
    CURRENT_STEP_NAME="$title"
    local pct=$(( num * 100 / total ))
    local bar; bar="$(_progress_bar "$pct")"
    local pad=$(( _LINE_W - ${#title} - 30 ))
    [ "$pad" -lt 1 ] && pad=1

    printf '\n'
    printf '  %s%sStep %d/%d%s  %s[%s]%s %s%3d%%%s\n' \
        "${c_bold}" "${c_cyan}" "$num" "$total" "${c_reset}" \
        "" "$bar" "" "${c_bold}" "$pct" "${c_reset}"
    printf '  %s%s%s\n' "${c_bold}${c_magenta}" "$title" "${c_reset}"
    printf '  %s' "${c_gray}"
    printf '%*s' "$_LINE_W" '' | tr ' ' '─'
    printf '%s\n' "${c_reset}"
}
section_end() {
    local num="$1" status="$2" dur="$3"
    STEP_RESULTS+=("$num|$status|$dur")
}

# ─── Banner ─────────────────────────────────────────────────────────────────
banner() {
    printf '\n'
    printf '  %s╭' "${c_magenta}"
    printf '%*s' "$_BOX_INNER" '' | tr ' ' '─'
    printf '╮%s\n' "${c_reset}"
    printf '  %s│%s  %s⚡ %sZRPanel%s %sinstaller%s %sv2.0%s' \
        "${c_magenta}" "${c_reset}" \
        "${c_yellow}" "${c_bold}" "${c_magenta}" "${c_reset}" \
        "${c_green}" "${c_reset}" "${c_dim}" "${c_reset}"
    printf '%*s' $(( _BOX_INNER - 32 )) '' | tr ' ' ' '
    printf '%s│%s\n' "${c_magenta}" "${c_reset}"
    printf '  %s│%s  %sA cPanel/WHM-style hosting panel · Android · Termux%s' \
        "${c_magenta}" "${c_reset}" "${c_dim}" "${c_reset}"
    printf '%*s' $(( _BOX_INNER - 50 )) '' | tr ' ' ' '
    printf '%s│%s\n' "${c_magenta}" "${c_reset}"
    printf '  %s╰' "${c_magenta}"
    printf '%*s' "$_BOX_INNER" '' | tr ' ' '─'
    printf '╯%s\n' "${c_reset}"
}

# ─── Spinner ────────────────────────────────────────────────────────────────
_spinner=('⠋' '⠙' '⠹' '⠸' '⠼' '⠴' '⠦' '⠧' '⠇' '⠏')

spin_run() {
    local label="$1"; shift
    local n=0 start=$SECONDS pid rc dur
    local tmp_out; tmp_out="$(mktemp 2>/dev/null || echo /tmp/spin.$$)"

    if [ "$VERBOSE" = "1" ]; then
        printf '  %s▸%s %s\n' "${c_cyan}" "${c_reset}" "$label"
        "$@" </dev/null 2>&1 | sed 's/^/      /'
        rc=${PIPESTATUS[0]}
    else
        "$@" </dev/null >"$tmp_out" 2>&1 &
        pid=$!
        printf '\r  %s %s' "${c_cyan}⠋${c_reset}" "$label"
        while kill -0 "$pid" 2>/dev/null; do
            printf '\r  %s %s' "${c_cyan}${_spinner[n]}${c_reset}" "$label"
            n=$(( (n + 1) % ${#_spinner[@]} ))
            sleep 0.08
        done
        wait "$pid"; rc=$?
    fi

    dur=$(( SECONDS - start ))
    if [ "$rc" -eq 0 ]; then
        printf '\r  %s %s %s(%ss)%s\n' "${c_green}✔${c_reset}" "$label" "${c_dim}" "$dur" "${c_reset}"
    else
        printf '\r  %s %s %s(%ss)%s\n' "${c_red}✖${c_reset}" "$label" "${c_dim}" "$dur" "${c_reset}"
        if [ "$VERBOSE" != "1" ] && [ -s "$tmp_out" ]; then
            # Show last 3 lines of output on failure
            printf '  %s│%s ' "${c_gray}" "${c_reset}"
            tail -n 3 "$tmp_out" | sed "s/^/  ${c_gray}│${c_reset} /" 2>/dev/null || true
        fi
        # Log failure
        {
            printf '[%s] FAIL step=%d/%d (%s) label=%s rc=%d\n' \
                "$(date '+%Y-%m-%d %H:%M:%S')" "$CURRENT_STEP" "$TOTAL_STEPS" \
                "$CURRENT_STEP_NAME" "$label" "$rc"
            [ -s "$tmp_out" ] && sed 's/^/    /' "$tmp_out"
        } >> "$ERR_LOG" 2>/dev/null || true
    fi
    rm -f "$tmp_out" 2>/dev/null
    return "$rc"
}

# ─── Retry wrapper ──────────────────────────────────────────────────────────
spin_retry() {
    local tries="$1" label="$2"; shift 2
    local i=1
    while [ "$i" -le "$tries" ]; do
        spin_run "$label" "$@" && return 0
        [ "$i" -lt "$tries" ] && { warn "Retry $i/$tries for: $label"; sleep 1; }
        i=$(( i + 1 ))
    done
    return 1
}

# ─── DB wait ────────────────────────────────────────────────────────────────
wait_db() {
    local n=0 i=0
    printf '\r  %s %s' "${c_cyan}⠋${c_reset}" "Waiting for MariaDB…"
    while ! "$MYSQL_CMD" --socket="$SOCK" -u root -e "SELECT 1" >/dev/null 2>&1; do
        if [ "$n" -ge 15 ]; then
            printf '\r  %s %s\n' "${c_red}✖${c_reset}" "MariaDB did not respond"
            return 1
        fi
        n=$((n + 1)); i=$(( (i + 1) % ${#_spinner[@]} ))
        printf '\r  %s %s (%ds)' "${c_cyan}${_spinner[i]}${c_reset}" "Waiting for MariaDB…" "$n"
        sleep 1
    done
    printf '\r  %s %s\n' "${c_green}✔${c_reset}" "MariaDB is up"
}

# ─── Prompt ─────────────────────────────────────────────────────────────────
prompt() {
    if [ -t 0 ] && [ -r /dev/tty ]; then
        printf "%s" "$*" > /dev/tty
        local ans; IFS= read -r ans < /dev/tty || true
        printf "%s" "$ans"
    fi
}

php_q() { printf "%s" "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/\\\\'/g"; }
gen_secret() { head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n'; }

# ─── MySQL client ───────────────────────────────────────────────────────────
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

# ═══════════════════════════════════════════════════════════════════════════
#  MAIN
# ═══════════════════════════════════════════════════════════════════════════
banner

# ─── Step 0: Environment ────────────────────────────────────────────────────
_S=$SECONDS
section 0 "$TOTAL_STEPS" "Environment & prerequisites"

[ -x "$PREFIX/bin/bash" ] || die "This script must run inside Termux."

if [ ! -d "$SOURCE_DIR" ]; then
    info "Setting up shared storage (accept the Android permission prompt)…"
    termux-setup-storage >/dev/null 2>&1 || true
    for _ in $(seq 1 30); do
        [ -d "$SOURCE_DIR" ] && break
        sleep 1
    done
fi
[ -d "$SOURCE_DIR" ] || die "Could not access ~/storage/downloads. Run 'termux-setup-storage' and grant access, then retry."

ok "Shared storage ready  ${c_dim}($SOURCE_DIR)${c_reset}"
section_end 0 "ok" "$(( SECONDS - _S ))"

# ─── Step 1: Packages ───────────────────────────────────────────────────────
_S=$SECONDS
section 1 "$TOTAL_STEPS" "Runtimes & packages"

info "Refreshing package index (pkg)…"
spin_run "pkg update" bash -c "pkg update -y </dev/null" \
    || warn "pkg update had issues — continuing anyway."

info "Refreshing package index (apt)…"
spin_run "apt update" bash -c "apt update -y </dev/null" \
    || warn "apt update had issues — continuing anyway."

NODEJS_PKG="${ZENPANEL_NODEJS_PKG:-nodejs}"
spin_run "Installing core packages (PHP · MariaDB · Cloudflared · Node · Python · TLS)" \
    pkg install -y \
        php mariadb cloudflared git curl wget unzip procps openssl-tool \
        "$NODEJS_PKG" npm python stunnel

command -v php      >/dev/null 2>&1 || die "php was not installed."
command -v mariadbd >/dev/null 2>&1 || die "mariadb was not installed."

_php_ver="$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo '—')"
_maria_ver="$(mariadbd --version 2>/dev/null | awk '{print $3}' || echo '—')"
_arch="$(uname -m)"
_cf_status="missing";  command -v cloudflared >/dev/null 2>&1 && _cf_status="installed"

_node_ver="—"; command -v node >/dev/null 2>&1 && _node_ver="$(node -v 2>/dev/null | sed 's/^v//' || true)"
_npm_ver="—";  command -v npm  >/dev/null 2>&1 && _npm_ver="$(npm -v 2>/dev/null || true)"
_py_ver="—";   command -v python3 >/dev/null 2>&1 && _py_ver="$(python3 --version 2>/dev/null | sed -E 's/Python //' || true)"

# Optional: pm2/pnpm/yarn
if command -v npm >/dev/null 2>&1 && [ "${ZENPANEL_SKIP_PM2:-0}" != "1" ] && ! command -v pm2 >/dev/null 2>&1; then
    spin_run "Installing pm2 · pnpm · yarn" \
        npm install -g --silent pm2 pnpm yarn \
        || warn "pm2/pnpm/yarn install failed — Node Web Apps need a process manager."
    if command -v termux-fix-shebang >/dev/null 2>&1; then
        info "Fixing executable shebangs (Termux)…"
        for _bin in pm2 pm2-dev pm2-docker pnpm yarn; do
            _p="$(command -v "$_bin" 2>/dev/null || true)"
            [ -n "$_p" ] && termux-fix-shebang "$_p" 2>/dev/null || true
        done
    fi
fi

# Optional: WP-CLI
if [ "${ZENPANEL_SKIP_WPCLI:-0}" != "1" ] && [ ! -s "$PREFIX/bin/wp-cli.phar" ]; then
    spin_retry 2 "Downloading WP-CLI" \
        curl -fsSL --retry 2 -o "$PREFIX/bin/wp-cli.phar" \
            https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
    if [ -s "$PREFIX/bin/wp-cli.phar" ] && php "$PREFIX/bin/wp-cli.phar" --version >/dev/null 2>&1; then
        chmod +x "$PREFIX/bin/wp-cli.phar"
        ok "WP-CLI installed"
    else
        rm -f "$PREFIX/bin/wp-cli.phar"
        skip "WP-CLI download failed — toolkit will fetch per-account."
    fi
else
    skip "WP-CLI already present"
fi

# Optional: acme.sh
ACME_HOME="$HOME_DIR/.acme.sh"
if [ "${ZENPANEL_SKIP_ACME:-0}" != "1" ] && [ ! -x "$ACME_HOME/acme.sh" ]; then
    info "Installing acme.sh (Let's Encrypt client)…"
    if git clone --depth 1 https://github.com/acmesh-official/acme.sh.git \
            "$PREFIX/tmp/acme.sh-src" >/dev/null 2>&1 \
        && termux-fix-shebang "$PREFIX/tmp/acme.sh-src/acme.sh" >/dev/null 2>&1 \
        && (cd "$PREFIX/tmp/acme.sh-src" && ./acme.sh --install --nocron --home "$ACME_HOME" </dev/null) >/dev/null 2>&1 \
        && [ -x "$ACME_HOME/acme.sh" ]; then
        ok "acme.sh installed ($("$ACME_HOME/acme.sh" --version 2>/dev/null | head -1))"
    else
        skip "acme.sh install failed — tls.sh needs it for HTTPS termination."
    fi
    rm -rf "$PREFIX/tmp/acme.sh-src"
else
    skip "acme.sh already present"
fi

# Optional: nsd
if command -v pkg >/dev/null 2>&1 && [ "${ZENPANEL_SKIP_NSD:-0}" != "1" ]; then
    spin_run "Installing nsd (authoritative DNS)" pkg install -y nsd \
        || skip "nsd not packaged here — DNS sync needs Linux/Docker."
fi

_pm2_status="missing";     command -v pm2 >/dev/null 2>&1 && _pm2_status="installed"
_wpcli_status="missing";   [ -x "$PREFIX/bin/wp-cli.phar" ] && _wpcli_status="installed"
_stunnel_status="missing"; command -v stunnel >/dev/null 2>&1 && _stunnel_status="installed"

printf '\n'
printf '  %s%sSystem Inventory%s\n' "${c_bold}" "${c_blue}" "${c_reset}"
printf '  %s' "${c_gray}"
printf '%*s' "$_LINE_W" '' | tr ' ' '─'
printf '%s\n' "${c_reset}"
printf '  %s%-14s%s %s%s%s\n' "${c_gray}" "Architecture" "${c_reset}" "${c_bold}" "$_arch" "${c_reset}"
printf '  %s%-14s%s %s%s%s\n' "${c_gray}" "PHP"          "${c_reset}" "${c_bold}" "$_php_ver" "${c_reset}"
printf '  %s%-14s%s %s%s%s\n' "${c_gray}" "MariaDB"      "${c_reset}" "${c_bold}" "$_maria_ver" "${c_reset}"
printf '  %s%-14s%s %s%s%s\n' "${c_gray}" "Node.js"      "${c_reset}" "${c_bold}" "$_node_ver" "${c_reset}"
printf '  %s%-14s%s %s%s%s\n' "${c_gray}" "npm"          "${c_reset}" "${c_bold}" "$_npm_ver" "${c_reset}"
printf '  %s%-14s%s %s%s%s\n' "${c_gray}" "Python"       "${c_reset}" "${c_bold}" "$_py_ver" "${c_reset}"

_col_status() {
    case "$1" in
        installed) printf '%s%s%s' "${c_green}" "✔ installed" "${c_reset}" ;;
        missing)   printf '%s%s%s' "${c_gray}"  "○ missing"   "${c_reset}" ;;
        *)         printf '%s'   "$1" ;;
    esac
}
printf '  %s%-14s%s %s\n' "${c_gray}" "pm2"         "${c_reset}" "$(_col_status "$_pm2_status")"
printf '  %s%-14s%s %s\n' "${c_gray}" "WP-CLI"      "${c_reset}" "$(_col_status "$_wpcli_status")"
printf '  %s%-14s%s %s\n' "${c_gray}" "stunnel"     "${c_reset}" "$(_col_status "$_stunnel_status")"
printf '  %s%-14s%s %s\n' "${c_gray}" "Cloudflared" "${c_reset}" "$(_col_status "$_cf_status")"
printf '\n'
section_end 1 "ok" "$(( SECONDS - _S ))"

# ─── Step 2: Repository ─────────────────────────────────────────────────────
_S=$SECONDS
section 2 "$TOTAL_STEPS" "Repository"

if [[ "$GIT_URL" == *USERNAME/zenpanel* ]]; then
    die "You are running the example installer. Set ZENPANEL_REPO_URL=https://github.com/<you>/<repo>.git, or edit the default at the top of install.sh, then re-run."
fi

if [ -d "$PANEL_DIR/.git" ]; then
    info "Updating existing installation…"
    spin_run "git pull" git -C "$PANEL_DIR" pull --ff-only --autostash || \
    spin_run "git pull (plain)" git -C "$PANEL_DIR" pull
    ok "Repository updated"
elif [ -d "$PANEL_DIR" ]; then
    warn "Non-git directory exists — backing up and re-installing."
    mv "$PANEL_DIR" "$SOURCE_DIR/hosting-backup-$(date +%s)"
    spin_retry 2 "Cloning repository" git clone --depth 1 "$GIT_URL" "$PANEL_DIR" \
        || die "git clone failed ($GIT_URL)."
    ok "Repository cloned"
else
    spin_retry 2 "Cloning repository" git clone --depth 1 "$GIT_URL" "$PANEL_DIR" \
        || die "git clone failed ($GIT_URL)."
    ok "Repository cloned  ${c_dim}($PANEL_DIR)${c_reset}"
fi

rm -f "$PANEL_DIR/install.sh" "$PANEL_DIR/README.md" 2>/dev/null
info "Removed installer files from device"

[ -f "$PANEL_DIR/config.php" ] || die "Clone/update finished but config.php is missing."
section_end 2 "ok" "$(( SECONDS - _S ))"

# ─── Step 3: MariaDB ────────────────────────────────────────────────────────
_S=$SECONDS
section 3 "$TOTAL_STEPS" "MariaDB + panel database"

mkdir -p "$PREFIX/var/run/mysqld" "$LOG_DIR"

if ! "$MYSQL_CMD" --socket="$SOCK" -u root -e "SELECT 1" >/dev/null 2>&1; then
    if [ ! -d "$PREFIX/var/lib/mysql/mysql" ]; then
        info "Initializing database files…"
        spin_run "mariadb-install-db" \
            mariadb-install-db --datadir="$PREFIX/var/lib/mysql" \
                --auth-root-authentication-method=normal </dev/null \
            || spin_run "mariadb-install-db (fallback)" \
                   mariadb-install-db --datadir="$PREFIX/var/lib/mysql" </dev/null \
            || warn "mariadb-install-db failed; falling back to automatic initialization."
    fi
    setsid nohup mariadbd --datadir="$PREFIX/var/lib/mysql" --socket="$SOCK" \
        --pid-file="$LOG_DIR/mariadb.pid" --log-error="$LOG_DIR/mariadb.log" \
        >/dev/null 2>&1 </dev/null &
    wait_db || :
fi

"$MYSQL_CMD" --socket="$SOCK" -u root -e "SELECT 1" >/dev/null 2>&1 \
    || die "MariaDB did not come up (log: $LOG_DIR/mariadb.log)."
ok "MariaDB is running"

"$MYSQL_CMD" --socket="$SOCK" -u root \
    -e "CREATE DATABASE IF NOT EXISTS \`$DBNAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
ok "Database '$DBNAME' ready"

cd "$PANEL_DIR" || die "Cannot cd $PANEL_DIR"
PHP_CONNECT='
require "config.php";
init_db();
echo "tables_ok\n";
'
if php -r "$PHP_CONNECT" 2>/dev/null; then
    ok "Database schema created"
else
    warn "Schema init printed a warning; will run on first page load."
fi
section_end 3 "ok" "$(( SECONDS - _S ))"

# ─── Step 4: WHM admin ──────────────────────────────────────────────────────
_S=$SECONDS
section 4 "$TOTAL_STEPS" "WHM admin account"

ADMIN_USER="${ZENPANEL_ADMIN_USER:-admin}"
ADMIN_PASS="${ZENPANEL_ADMIN_PASS:-}"
SITE_DOMAIN="$(get_cfg SITE_DOMAIN)"
[ -n "$SITE_DOMAIN" ] || SITE_DOMAIN="${ZENPANEL_SITE_DOMAIN:-}"

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
    ok "WHM admin '$ADMIN_USER' ready"
fi
section_end 4 "ok" "$(( SECONDS - _S ))"

# ─── Step 5: Secrets ────────────────────────────────────────────────────────
_S=$SECONDS
section 5 "$TOTAL_STEPS" "Secrets → config.local.php"

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
    info "Keeping existing config.local.php (re-encoding with current values)."
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
ok "config.local.php written  ${c_dim}(mode 600)${c_reset}"

TUNNEL_DIR="$PANEL_DIR/tunnel_data/panel"
TOKEN_FILE="$TUNNEL_DIR/connector.token"
TUN_TOKEN="${ZENPANEL_CF_TUNNEL_TOKEN:-$(cat "$TOKEN_FILE" 2>/dev/null || true)}"
if [ -z "$TUN_TOKEN" ]; then
    TUN_TOKEN="$(prompt "Paste your Cloudflare tunnel connector token (blank to skip tunnel): ")"
fi
if [ -n "$TUN_TOKEN" ]; then
    mkdir -p "$TUNNEL_DIR"
    printf '%s' "$TUN_TOKEN" > "$TOKEN_FILE"
    chmod 600 "$TOKEN_FILE"
    ok "Tunnel token saved  ${c_dim}($TOKEN_FILE)${c_reset}"
else
    skip "No tunnel token — cloudflared skipped until you add one."
fi
section_end 5 "ok" "$(( SECONDS - _S ))"

# ─── Step 6: Launchers ──────────────────────────────────────────────────────
_S=$SECONDS
section 6 "$TOTAL_STEPS" "Launchers (~/start, ~/off)"

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
section_end 6 "ok" "$(( SECONDS - _S ))"

# ─── Step 7: Summary ────────────────────────────────────────────────────────
_S=$SECONDS
section 7 "$TOTAL_STEPS" "Summary"
_elapsed=$(( SECONDS - _start_ts ))
section_end 7 "ok" "$(( SECONDS - _S ))"

# ─── Final Report Card ──────────────────────────────────────────────────────
printf '\n'
printf '  %s╔═══════════════════════════════════════════════╗%s\n' "${c_green}${c_bold}" "${c_reset}"
printf '  %s║%s  %s✅  INSTALL COMPLETE%s  %s(%ss total)%s' \
    "${c_green}${c_bold}" "${c_reset}" \
    "${c_bold}${c_green}" "${c_reset}" \
    "${c_dim}" "$_elapsed" "${c_reset}"
printf '%*s' $(( _BOX_INNER - 36 )) '' | tr ' ' ' '
printf '%s║%s\n' "${c_green}${c_bold}" "${c_reset}"
printf '  %s╠═══════════════════════════════════════════════╣%s\n' "${c_green}${c_bold}" "${c_reset}"

# Step-by-step result table
for row in "${STEP_RESULTS[@]}"; do
    IFS='|' read -r num status dur <<< "$row"
    local_name=""
    case "$num" in
        0) local_name="Environment" ;;
        1) local_name="Packages" ;;
        2) local_name="Repository" ;;
        3) local_name="MariaDB" ;;
        4) local_name="WHM admin" ;;
        5) local_name="Secrets" ;;
        6) local_name="Launchers" ;;
        7) local_name="Summary" ;;
    esac
    badge="${c_green}✔${c_reset}"
    [ "$status" != "ok" ] && badge="${c_red}✖${c_reset}"
    printf '  %s║%s  %s  %sStep %s%s  %-16s %s%ss%s' \
        "${c_green}${c_bold}" "${c_reset}" \
        "$badge" "${c_gray}" "$num" "${c_reset}" \
        "$local_name" "${c_dim}" "$dur" "${c_reset}"
    printf '%*s' 6 '' | tr ' ' ' '
    printf '%s║%s\n' "${c_green}${c_bold}" "${c_reset}"
done

printf '  %s╠═══════════════════════════════════════════════╣%s\n' "${c_green}${c_bold}" "${c_reset}"
printf '  %s║%s  %-13s  %s' "${c_green}${c_bold}" "${c_reset}" "Panel dir" "$PANEL_DIR"
printf '%*s' 5 '' | tr ' ' ' '
printf '%s║%s\n' "${c_green}${c_bold}" "${c_reset}"
printf '  %s║%s  %-13s  %s' "${c_green}${c_bold}" "${c_reset}" "Panel URL" "${c_cyan}http://localhost:$PORT${c_reset}"
printf '%*s' 5 '' | tr ' ' ' '
printf '%s║%s\n' "${c_green}${c_bold}" "${c_reset}"
printf '  %s║%s  %-13s  %s' "${c_green}${c_bold}" "${c_reset}" "WHM URL" "${c_cyan}http://localhost:$PORT/whm${c_reset}"
printf '%*s' 5 '' | tr ' ' ' '
printf '%s║%s\n' "${c_green}${c_bold}" "${c_reset}"
printf '  %s╚═══════════════════════════════════════════════╝%s\n' "${c_green}${c_bold}" "${c_reset}"

# ─── Credentials card ───────────────────────────────────────────────────────
printf '\n'
printf '  %s╭───────────────────────────────────────────────╮%s\n' "${c_yellow}${c_bold}" "${c_reset}"
printf '  %s│%s  %s🔐  Admin Credentials%s' \
    "${c_yellow}${c_bold}" "${c_reset}" "${c_bold}${c_yellow}" "${c_reset}"
printf '%*s' 21 '' | tr ' ' ' '
printf '%s│%s\n' "${c_yellow}${c_bold}" "${c_reset}"
printf '  %s│%s\n' "${c_yellow}${c_bold}" "${c_reset}"
printf '  %s│%s  Username  :  %s%s%s' "${c_yellow}${c_bold}" "${c_reset}" "${c_bold}${c_green}" "$ADMIN_USER" "${c_reset}"
printf '%*s' $(( 34 - ${#ADMIN_USER} )) '' | tr ' ' ' '
printf '%s│%s\n' "${c_yellow}${c_bold}" "${c_reset}"
_pass_display="${NEW_ADMIN_PASS:-<existing — kept>}"
printf '  %s│%s  Password  :  %s%s%s' "${c_yellow}${c_bold}" "${c_reset}" "${c_bold}${c_green}" "$_pass_display" "${c_reset}"
printf '%*s' $(( 34 - ${#_pass_display} )) '' | tr ' ' ' '
printf '%s│%s\n' "${c_yellow}${c_bold}" "${c_reset}"
printf '  %s│%s\n' "${c_yellow}${c_bold}" "${c_reset}"
printf '  %s│%s  %sReset → WHM → Accounts → Reset Password%s' "${c_yellow}${c_bold}" "${c_reset}" "${c_dim}" "${c_reset}"
printf '%*s' 4 '' | tr ' ' ' '
printf '%s│%s\n' "${c_yellow}${c_bold}" "${c_reset}"
printf '  %s╰───────────────────────────────────────────────╯%s\n' "${c_yellow}${c_bold}" "${c_reset}"

# ─── Features password (shown once) ─────────────────────────────────────────
if [ -n "$FEAT_PASS" ]; then
    printf '\n'
    printf '  %s╭───────────────────────────────────────────────╮%s\n' "${c_red}${c_bold}" "${c_reset}"
    printf '  %s│%s  %s⚠  /features password — SAVE THIS NOW%s' \
        "${c_red}${c_bold}" "${c_reset}" "${c_bold}${c_red}" "${c_reset}"
    printf '%*s' 7 '' | tr ' ' ' '
    printf '%s│%s\n' "${c_red}${c_bold}" "${c_reset}"
    printf '  %s│%s  %s%s%s / %s%s%s' \
        "${c_red}${c_bold}" "${c_reset}" \
        "${c_bold}${c_green}" "$FEAT_USER" "${c_reset}" \
        "${c_bold}${c_green}" "$FEAT_PASS" "${c_reset}"
    printf '%*s' $(( 31 - ${#FEAT_USER} - ${#FEAT_PASS} )) '' | tr ' ' ' '
    printf '%s│%s\n' "${c_red}${c_bold}" "${c_reset}"
    printf '  %s╰───────────────────────────────────────────────╯%s\n' "${c_red}${c_bold}" "${c_reset}"
fi

# ─── Quick start ────────────────────────────────────────────────────────────
printf '\n'
printf '  %s%s🚀 Quick Start%s\n' "${c_bold}" "${c_blue}" "${c_reset}"
printf '  %s' "${c_gray}"
printf '%*s' "$_LINE_W" '' | tr ' ' '─'
printf '%s\n' "${c_reset}"
printf '  %s~/start%s    start the panel\n' "${c_bold}${c_green}" "${c_reset}"
printf '  %s~/off%s      stop the panel\n'  "${c_bold}${c_red}"   "${c_reset}"
printf '  %sPanel%s     %shttp://localhost:%s%s\n' "${c_bold}${c_cyan}" "${c_reset}" "${c_cyan}" "$PORT" "${c_reset}"
printf '  %sWHM%s       %shttp://localhost:%s/whm%s\n' "${c_bold}${c_cyan}" "${c_reset}" "${c_cyan}" "$PORT" "${c_reset}"
[ "$SITE_DOMAIN" != "localhost" ] && \
    printf '  %sPublic%s    %shttps://%s%s\n' "${c_bold}${c_magenta}" "${c_reset}" "${c_magenta}" "$SITE_DOMAIN" "${c_reset}"
printf '  %s' "${c_gray}"
printf '%*s' "$_LINE_W" '' | tr ' ' '─'
printf '%s\n' "${c_reset}"

# Error log hint
if [ -s "$ERR_LOG" ]; then
    printf '\n  %s⚠ %sWarning(s) logged to:%s %s\n' "${c_yellow}" "" "${c_reset}" "$ERR_LOG"
fi
printf '\n'

# ─── Auto-start ─────────────────────────────────────────────────────────────
if [ "${ZENPANEL_NO_START:-0}" != "1" ]; then
    ok "Starting the panel now… (rerun anytime with: ~/start)"
    bash "$HOME_DIR/start" || warn "The launcher reported trouble — check the logs listed above."
fi
