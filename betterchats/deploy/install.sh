#!/usr/bin/env bash
# BetterChats installer for Debian/Ubuntu servers. Run as root from the betterchats folder:
#   sudo bash deploy/install.sh public chat.example.com   # public on port 30 (HTTPS via Let's Encrypt cert)
#   sudo bash deploy/install.sh onion                     # stealth: only reachable via Tor, port 30 closed to the world
set -euo pipefail
MODE="${1:-}"; DOMAIN="${2:-}"
CHAT_PORT="${CHAT_PORT:-30}"
SSH_PORT="${SSH_PORT:-$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}')}"; SSH_PORT="${SSH_PORT:-22}"
[ "$(id -u)" = 0 ] || { echo "Bitte als root ausführen."; exit 1; }
[ "$MODE" = public ] || [ "$MODE" = onion ] || { echo "Nutzung: install.sh public <domain> | install.sh onion"; exit 1; }
[ "$MODE" = onion ] || [ -n "$DOMAIN" ] || { echo "Für 'public' wird eine Domain gebraucht."; exit 1; }
SRC="$(cd "$(dirname "$0")/.." && pwd)"

apt-get update -qq
apt-get install -y -qq nftables nodejs npm >/dev/null
NODE_MAJOR="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
[ "$NODE_MAJOR" -ge 18 ] || { echo "Node.js >= 18 nötig (gefunden: $NODE_MAJOR). Siehe https://nodejs.org/en/download"; exit 1; }
command -v setfacl >/dev/null || apt-get install -y -qq acl >/dev/null
[ "$MODE" = onion ] && apt-get install -y -qq tor >/dev/null

id betterchats >/dev/null 2>&1 || useradd --system --home /nonexistent --shell /usr/sbin/nologin betterchats
install -d -m 755 /opt/betterchats
cp -r "$SRC/server.js" "$SRC/package.json" "$SRC/package-lock.json" "$SRC/public" /opt/betterchats/
(cd /opt/betterchats && npm ci --omit=dev --no-audit --no-fund >/dev/null)
install -d -m 700 -o betterchats -g betterchats /var/lib/betterchats
install -d -m 700 /etc/betterchats

if [ ! -f /etc/betterchats/env ]; then
  umask 077
  {
    echo "PORT=$CHAT_PORT"
    echo "BETTERCHATS_PEPPER=$(head -c 48 /dev/urandom | base64 -w0)"
    if [ "$MODE" = onion ]; then
      echo "HOST=127.0.0.1"
    else
      echo "DOMAIN=$DOMAIN"
      echo "TLS_CERT=/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
      echo "TLS_KEY=/etc/letsencrypt/live/$DOMAIN/privkey.pem"
    fi
  } > /etc/betterchats/env
fi

if [ "$MODE" = public ]; then
  CHAT_RULES="tcp dport \$CHAT_PORT ct state new meter chat4 { ip saddr limit rate over 15/second burst 30 packets } add @ban4 { ip saddr } drop
    tcp dport \$CHAT_PORT ct state new meter chat6 { ip6 saddr limit rate over 15/second burst 30 packets } add @ban6 { ip6 saddr } drop
    tcp dport \$CHAT_PORT ct state new meter chatc4 { ip saddr ct count over 20 } drop
    tcp dport \$CHAT_PORT ct state new accept"
  # node must be able to read the certificate
  if [ -d "/etc/letsencrypt/live/$DOMAIN" ]; then
    setfacl -R -m u:betterchats:rX /etc/letsencrypt/live /etc/letsencrypt/archive 2>/dev/null || chmod -R g+rX /etc/letsencrypt/live /etc/letsencrypt/archive
  else
    echo "HINWEIS: Kein Zertifikat gefunden. Erst: apt install certbot && certbot certonly --standalone -d $DOMAIN"
  fi
else
  CHAT_RULES="# stealth mode: chat port closed to the outside, Tor connects via localhost"
  grep -q betterchats /etc/tor/torrc || printf '\nHiddenServiceDir /var/lib/tor/betterchats/\nHiddenServicePort 80 127.0.0.1:%s\n' "$CHAT_PORT" >> /etc/tor/torrc
  systemctl restart tor
fi

sed -e "s/__SSH_PORT__/$SSH_PORT/" -e "s/__CHAT_PORT__/$CHAT_PORT/" "$SRC/deploy/nftables.conf" \
  | awk -v r="$CHAT_RULES" '{ if ($0 ~ /__CHAT_RULES__/) print "    " r; else print }' > /etc/betterchats/nftables.conf
nft -c -f /etc/betterchats/nftables.conf
nft -f /etc/betterchats/nftables.conf
grep -q betterchats /etc/nftables.conf 2>/dev/null || echo 'include "/etc/betterchats/nftables.conf"' >> /etc/nftables.conf
systemctl enable --now nftables >/dev/null

# don't answer pings, ignore bogus packets, no source routing
cat > /etc/sysctl.d/90-betterchats.conf <<SYS
net.ipv4.icmp_echo_ignore_all=1
net.ipv4.tcp_syncookies=1
net.ipv4.conf.all.rp_filter=1
net.ipv4.conf.all.accept_source_route=0
net.ipv6.conf.all.accept_source_route=0
net.ipv4.conf.all.accept_redirects=0
net.ipv6.conf.all.accept_redirects=0
net.ipv4.conf.all.log_martians=0
kernel.core_pattern=|/bin/false
SYS
sysctl -q --system

cp "$SRC/deploy/betterchats.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now betterchats
sleep 2
systemctl is-active betterchats >/dev/null && echo "BetterChats läuft auf Port $CHAT_PORT."
if [ "$MODE" = onion ]; then
  echo "Deine geheime Adresse (nur im Tor Browser): http://$(cat /var/lib/tor/betterchats/hostname)"
  echo "Danach DOMAIN=<diese .onion-Adresse> in /etc/betterchats/env eintragen und 'systemctl restart betterchats'."
else
  echo "Erreichbar unter: https://$DOMAIN:$CHAT_PORT"
fi
echo "SSH bleibt auf Port $SSH_PORT offen (limitiert). Alles andere ist dicht."
