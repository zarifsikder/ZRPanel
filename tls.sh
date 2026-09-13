#!/data/data/com.termux/files/usr/bin/bash
# ZRPanel local TLS terminator (stunnel) control script.
#
# Terminates HTTPS on 0.0.0.0:8443 (Android blocks binding <1024) with a
# Let's Encrypt certificate for the configured server hostname (WHM →
# Hostname, e.g. *.server.dzhost.shop) and forwards plain HTTP to the panel
# on 127.0.0.1:8080. The certificate is issued/renewed automatically via
# DNS-01 (Cloudflare API).
#
# If the network router forwards external 443 -> this port, the standard
# https://... URL works; otherwise use https://host:8443.
#
# Usage: tls.sh {start|stop|restart|status|renew}   [TLS_HOSTNAME=foo.example.com] [PORT=8443]
#   e.g. PORT=443 TLS_HOSTNAME=server.dzhost.shop bash tls.sh start

set -u

PANEL_DIR=/storage/emulated/0/Download/hosting
CERT_DIR="$PANEL_DIR/tls"
CERT_FILE="$CERT_DIR/panel.pem"
STUNNEL=/data/data/com.termux/files/usr/bin/stunnel
CONF="$CERT_DIR/stunnel.conf"
PID_FILE="$CERT_DIR/stunnel.pid"
LOG="$CERT_DIR/stunnel.log"
ACME_DIR=/data/data/com.termux/files/home/.acme.sh
ACME="$ACME_DIR/acme.sh"
OPENSSL=/data/data/com.termux/files/usr/glibc/bin/openssl
# The TLS terminator is scoped to the server hostname (WHM → Hostname), NOT a
# hardcoded domain. Reads the panel's configured hostname via server_hostname()
# so the issued certificate always matches the name the panel answers on.
# Pass TLS_HOSTNAME=foo.example.com to override, or fall back to new.dzhost.shop.
DOMAIN="${TLS_HOSTNAME:-}"
UPSTREAM=127.0.0.1:8080
PORT="${PORT:-8443}"
RENEW_DAYS=30

export PATH="/data/data/com.termux/files/usr/glibc/bin:$PATH"

# Pull the Cloudflare credentials from the panel config so this script never
# holds its own copy of the secret.
CF_TOKEN=$(php -r 'require "'"$PANEL_DIR"'/config.php"; echo defined("CF_API_TOKEN") ? CF_API_TOKEN : "";' 2>/dev/null)
CF_ZONE=$(php -r 'require "'"$PANEL_DIR"'/config.php"; echo defined("CF_ZONE_ID") ? CF_ZONE_ID : "";' 2>/dev/null)

# Resolve the hostname the certificate must cover: the configured server
# hostname, unless overridden on the command line.
if [ -z "$DOMAIN" ]; then
    DOMAIN=$(php -r 'require "'"$PANEL_DIR"'/config.php"; echo function_exists("server_hostname") ? server_hostname() : "";' 2>/dev/null)
fi
DOMAIN="${DOMAIN:-new.dzhost.shop}"
# Normalize: strip any scheme/port the user may have typed, lower-case it.
DOMAIN=$(echo "$DOMAIN" | sed -E 's#^[a-z]+://##; s#[:/].*$##' | tr 'A-Z' 'a-z')

# Refuse to run toward a placeholder hostname: there is nothing to certify and
# the OS name "localhost" has no public ownership to prove. stop/status can
# proceed even without a valid hostname (they only manage the pid file).
NEEDS_CERT_TOOLING=1
case "$(echo "${1:-start}" | tr 'A-Z' 'a-z')" in
    stop|status) NEEDS_CERT_TOOLING=0 ;;
esac

if [ "$NEEDS_CERT_TOOLING" -eq 1 ] && { [ -z "$DOMAIN" ] || [ "$DOMAIN" = "localhost" ] || echo "$DOMAIN" | grep -qE '^[0-9.]+$'; }; then
    echo "ERROR: no valid server hostname configured (got '$DOMAIN')."
    echo "Set one in WHM → Hostname, or pass TLS_HOSTNAME=server.example.com."
    exit 1
fi

# Hard dependency checks. Without these the script could silently do nothing
# (or worse, hand stunnel a stale cert from a different domain). Fail loudly.
if [ "$NEEDS_CERT_TOOLING" -eq 1 ]; then
    if [ ! -x "$STUNNEL" ]; then
        echo "ERROR: stunnel not found at $STUNNEL (pkg install stunnel)."
        exit 1
    fi
    if [ ! -x "$ACME" ]; then
        echo "ERROR: acme.sh not found at $ACME (install acme.sh)."
        exit 1
    fi
    if [ ! -x "$OPENSSL" ]; then
        echo "ERROR: openssl not found at $OPENSSL."
        exit 1
    fi
fi

mkdir -p "$CERT_DIR"

write_config() {
    cat > "$CONF" <<EOF
foreground = no
debug = 3
output = $LOG
pid = $PID_FILE

[panel]
accept = 0.0.0.0:$PORT
cert = $CERT_FILE
connect = $UPSTREAM
EOF
}

fetch_cert() {
    CF_Token="$CF_TOKEN" CF_Zone_ID="$CF_ZONE" bash "$ACME" --home "$ACME_DIR" \
        --issue --dns dns_cf -d "$DOMAIN" -d "*.$DOMAIN" \
        --keylength ec-256 --server letsencrypt >/dev/null 2>&1
}

renew_cert() {
    CF_Token="$CF_TOKEN" CF_Zone_ID="$CF_ZONE" bash "$ACME" --home "$ACME_DIR" \
        --renew -d "$DOMAIN" -d "*.$DOMAIN" --server letsencrypt >/dev/null 2>&1
}

install_cert() {
    local D="$ACME_DIR/${DOMAIN}_ecc"
    if [ -f "$D/fullchain.cer" ] && [ -f "$D/$DOMAIN.key" ]; then
        cat "$D/fullchain.cer" "$D/$DOMAIN.key" > "$CERT_FILE"
        chmod 600 "$CERT_FILE"
        return 0
    fi
    return 1
}

# True if an existing panel.pem already covers $DOMAIN. A cert issued for a
# previous hostname is useless (browsers reject the name), so it must be
# treated as absent and re-issued for the current hostname.
cert_covers_domain() {
    [ -f "$CERT_FILE" ] || return 1
    "$OPENSSL" x509 -in "$CERT_FILE" -noout -subject 2>/dev/null | grep -qi "CN=$DOMAIN" || return 1
    return 0
}

ensure_cert() {
    if ! cert_covers_domain; then
        if [ -f "$CERT_FILE" ]; then
            echo "Certificate is for a different hostname; re-issuing for $DOMAIN..."
            fetch_cert
        else
            echo "No certificate found; requesting a new one..."
            fetch_cert
        fi
        if ! install_cert; then
            echo "ERROR: could not obtain a certificate for $DOMAIN (check network / CF token)."
            exit 1
        fi
        echo "Certificate issued for $DOMAIN."
    elif ! "$OPENSSL" x509 -in "$CERT_FILE" -noout -checkend $((RENEW_DAYS * 86400)) >/dev/null 2>&1; then
        echo "Certificate expiring soon; renewing..."
        renew_cert
        install_cert
        echo "Certificate renewed."
    fi
}

start() {
    write_config
    ensure_cert
    if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
        echo "Local TLS already running (pid $(cat "$PID_FILE"))"
        return 0
    fi
    nohup "$STUNNEL" "$CONF" >/dev/null 2>&1 &
    sleep 2
    if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
        echo "Local TLS started (pid $(cat "$PID_FILE"))"
    else
        echo "ERROR: Local TLS failed to start; check $LOG"
        return 1
    fi
}

stop() {
    if [ ! -f "$PID_FILE" ]; then
        echo "Local TLS not running"
        return 0
    fi
    kill "$(cat "$PID_FILE")" 2>/dev/null
    rm -f "$PID_FILE"
    echo "Local TLS stopped"
}

status() {
    if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
        echo "running (pid $(cat "$PID_FILE"))"
        return 0
    fi
    echo "not running"
    return 1
}

case "${1:-start}" in
    start)   start ;;
    stop)    stop ;;
    restart) stop; sleep 1; start ;;
    status)  status ;;
    renew)   ensure_cert && echo "Local TLS certificate current for $DOMAIN." ;;
    *)
        echo "Usage: $0 {start|stop|restart|status|renew}"
        exit 1
        ;;
esac
