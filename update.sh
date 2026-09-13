#!/data/data/com.termux/files/usr/bin/bash
# ============================================================
#  ZRPanel updater — run from the terminal:
#
#    cd ~/storage/downloads/hosting && ./update.sh
#    (or ~/storage/downloads/hosting/update.sh from anywhere)
#
#  Pulls the latest panel code, syncs the database schema,
#  removes the installer-only files and makes sure the
#  services are running. Your config.local.php, tunnel_data/
#  and user_data/ are left untouched.
# ============================================================

set -u

PREFIX=/data/data/com.termux/files/usr
DEFAULT_PANEL_DIR="$HOME/storage/downloads/hosting"

# Locate the panel directory. When run from a downloaded copy, resolve it from
# the script's own path; when streamed via `curl ... | bash` (BASH_SOURCE is
# empty), fall back to the current directory, then to the standard install
# location.
if [ -n "${BASH_SOURCE[0]:-}" ] && [ -f "${BASH_SOURCE[0]}" ]; then
    ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
else
    ROOT="$PWD"
fi
if [ ! -d "$ROOT/.git" ] && [ -d "$DEFAULT_PANEL_DIR/.git" ]; then
    ROOT="$DEFAULT_PANEL_DIR"
fi

GIT_URL="$(git -C "$ROOT" remote get-url origin 2>/dev/null || echo "unknown")"

# ────────────────────────────────────────────────────────────
#  COLOR PALETTE (matches scripts/start & scripts/off)
# ────────────────────────────────────────────────────────────
R=$'\033[0m'
BOLD=$'\033[1m'
DIM=$'\033[2m'
ITALIC=$'\033[3m'

BRED=$'\033[91m'
BGREEN=$'\033[92m'
BYELLOW=$'\033[93m'
BBLUE=$'\033[94m'
BMAGENTA=$'\033[95m'
BCYAN=$'\033[96m'

TERM_WIDTH=$(tput cols 2>/dev/null || echo 60)
[ "$TERM_WIDTH" -gt 70 ] && TERM_WIDTH=70
[ "$TERM_WIDTH" -lt 50 ] && TERM_WIDTH=50

hr() {
    local char="${1:-─}"
    local width="${2:-$TERM_WIDTH}"
    local line=""
    for ((i=0; i<width; i++)); do
        line+="$char"
    done
    printf "%s\n" "$line"
}

center() {
    local text="$1"
    local width="${2:-$TERM_WIDTH}"
    local clean_text
    clean_text=$(printf "%s" "$text" | sed 's/\x1b\[[0-9;]*m//g')
    local text_len=${#clean_text}
    local padding=$(( (width - text_len) / 2 ))
    [ "$padding" -lt 0 ] && padding=0
    printf "%*s%s\n" "$padding" "" "$text"
}

box_top() {
    local width="${1:-$TERM_WIDTH}"
    printf "${BCYAN}╔"
    for ((i=0; i<width-2; i++)); do printf "═"; done
    printf "╗${R}\n"
}

box_bottom() {
    local width="${1:-$TERM_WIDTH}"
    printf "${BCYAN}╚"
    for ((i=0; i<width-2; i++)); do printf "═"; done
    printf "╝${R}\n"
}

box_line() {
    local text="$1"
    local width="${2:-$TERM_WIDTH}"
    local clean_text
    clean_text=$(printf "%s" "$text" | sed 's/\x1b\[[0-9;]*m//g')
    local text_len=${#clean_text}
    local inner=$((width - 4))
    local padding=$(( (inner - text_len) / 2 ))
    [ "$padding" -lt 0 ] && padding=0
    local right_pad=$((inner - text_len - padding))
    [ "$right_pad" -lt 0 ] && right_pad=0
    printf "${BCYAN}║${R} %*s%s%*s ${BCYAN}║${R}\n" "$padding" "" "$text" "$right_pad" ""
}

section() {
    local title="$1"
    local icon="${2:-◆}"
    echo
    printf "${BMAGENTA}${BOLD}  ${icon}  ${title}${R}\n"
    printf "${MAGENTA}  "
    for ((i=0; i<TERM_WIDTH-4; i++)); do printf "─"; done
    printf "${R}\n"
}

status_ok() {
    local label="$1"
    local value="${2:-}"
    printf "  ${BGREEN}${BOLD} ✓ ${R}  ${BOLD}%-28s${R}" "$label"
    if [ -n "$value" ]; then
        printf " ${DIM}${BGREEN}%s${R}" "$value"
    fi
    echo
}

status_warn() {
    local label="$1"
    local value="${2:-}"
    printf "  ${BYELLOW}${BOLD} ⚠ ${R}  ${BOLD}%-28s${R}" "$label"
    if [ -n "$value" ]; then
        printf " ${DIM}${BYELLOW}%s${R}" "$value"
    fi
    echo
}

status_err() {
    local label="$1"
    local value="${2:-}"
    printf "  ${BRED}${BOLD} ✗ ${R}  ${BOLD}%-28s${R}" "$label"
    if [ -n "$value" ]; then
        printf " ${DIM}${BRED}%s${R}" "$value"
    fi
    echo
}

status_info() {
    local label="$1"
    printf "  ${BBLUE}${BOLD} ℹ ${R}  ${DIM}%s${R}\n" "$label"
}

# ────────────────────────────────────────────────────────────
#  SPLASH
# ────────────────────────────────────────────────────────────
show_splash() {
    clear 2>/dev/null || true
    echo

    printf "${BCYAN}${BOLD}"
    cat <<'BANNER'
    ███████╗███████╗███╗   ██╗
    ╚══███╔╝██╔════╝████╗  ██║
      ███╔╝ █████╗  ██╔██╗ ██║
     ███╔╝  ██╔══╝  ██║╚██╗██║
    ███████╗███████╗██║ ╚████║
    ╚══════╝╚══════╝╚═╝  ╚═══╝
BANNER
    printf "${R}"

    printf "${BMAGENTA}${BOLD}"
    cat <<'BANNER2'
    ██████╗  █████╗ ███╗   ██╗███████╗██╗
    ██╔══██╗██╔══██╗████╗  ██║██╔════╝██║
    ██████╔╝███████║██╔██╗ ██║█████╗  ██║
    ██╔═══╝ ██╔══██║██║╚██╗██║██╔══╝  ██║
    ██║     ██║  ██║██║ ╚████║███████╗███████╗
    ╚═╝     ╚═╝  ╚═╝╚═╝  ╚═══╝╚══════╝╚══════╝
BANNER2
    printf "${R}"

    echo
    center "${DIM}${ITALIC}ZRPanel Updater${R}"
    center "${DIM}v1.0 • Termux Edition${R}"
    echo

    box_top
    box_line "${BCYAN}${BOLD}Updating Panel...${R}"
    box_bottom
    echo
}

show_splash

section "PANEL DIRECTORY" "📁"

if [ ! -d "$ROOT/.git" ]; then
    status_err "Not a git repository"
    echo
    printf "  ${DIM}Expected:${R}\n"
    printf "  ${BRED}%s${R}\n" "$ROOT"
    echo
    printf "  ${DIM}This updater only works on a panel installed via the${R}\n"
    printf "  ${DIM}curl installer (which clones the repository).${R}\n"
    echo
    exit 1
fi

status_ok "Panel directory" "$ROOT"
status_ok "Remote" "$GIT_URL"

section "FETCH UPDATES" "📥"

_BEFORE="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo '?')"

if git -C "$ROOT" pull --ff-only --autostash >/dev/null 2>&1; then
    _AFTER="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo '?')"
    if [ "$_BEFORE" = "$_AFTER" ]; then
        status_ok "Already up to date" "($_BEFORE)"
    else
        status_ok "Pulled updates" "$_BEFORE → $_AFTER"
        git -C "$ROOT" log --oneline "$_BEFORE..$_AFTER" 2>/dev/null \
            | sed 's/^/    /' | head -20 || true
    fi
else
    _AFTER="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo '?')"
    if [ "$_BEFORE" != "$_AFTER" ]; then
        status_warn "Pull succeeded with warnings" "$_BEFORE → $_AFTER"
    else
        status_err "git pull failed"
        echo
        printf "  ${DIM}Local changes may conflict with upstream. Backup your work and either:${R}\n"
        printf "  ${DIM}  • stash them:   git -C \"%s\" stash && ./update.sh${R}\n" "$ROOT"
        printf "  ${DIM}  • re-install:   re-run the curl installer (it preserves config.local.php)${R}\n"
        echo
        exit 1
    fi
fi

section "INSTALLER FILES" "🧹"

rm -f "$ROOT/install.sh" "$ROOT/README.md" 2>/dev/null
status_ok "Removed installer-only files" "install.sh · README.md"
status_ok "Kept updater" "update.sh"

section "DATABASE SCHEMA" "🗄"

if command -v php >/dev/null 2>&1 && [ -f "$ROOT/config.php" ]; then
    if (cd "$ROOT" && php -r 'require "config.php"; init_db(); echo "schema_ok\n";' >/dev/null 2>&1); then
        status_ok "Schema synced" "tables & migrations up to date"
    else
        status_warn "Schema sync printed a warning" "it re-runs automatically on first page load"
    fi
else
    status_warn "PHP config not found" "schema sync skipped"
fi

section "SERVICES" "🚀"

if [ -f "$ROOT/scripts/start" ]; then
    status_info "Ensuring services are running (idempotent)…"
    bash "$ROOT/scripts/start"
else
    status_warn "Launcher missing (scripts/start)"
fi

echo
echo

printf "${BGREEN}${BOLD}"
hr " " "$TERM_WIDTH"
center "🎉  ZENPANEL IS UP TO DATE  🎉"
hr " " "$TERM_WIDTH"
printf "${R}"
echo

box_top
printf "${BCYAN}║${R}  ${BOLD}${BCYAN}%-18s${R}  ${BGREEN}%s${R}\n" "🔄  Version" "${_AFTER:-?}"
printf "${BCYAN}║${R}  ${BOLD}${BCYAN}%-18s${R}  ${BGREEN}%s${R}\n" "📁  Directory" "$ROOT"
printf "${BCYAN}║${R}  ${BOLD}${BCYAN}%-18s${R}  ${BMAGENTA}%s${R}\n" "🧰  Re-run" "$ROOT/update.sh"
box_bottom

echo

printf "${BCYAN}"
hr "─" "$TERM_WIDTH"
printf "${R}"
center "${DIM}${ITALIC}ZRPanel Updater • $(date '+%Y-%m-%d %H:%M:%S')${R}"
printf "${BCYAN}"
hr "─" "$TERM_WIDTH"
printf "${R}"
echo