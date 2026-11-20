#!/usr/bin/env bash
# BetterChats installer for Debian/Ubuntu servers. Run as root from the betterchats folder:
#   sudo bash deploy/install.sh public chat.example.com   # public on port 30 (HTTPS via Let's Encrypt cert)
#   sudo bash deploy/install.sh onion                     # stealth: only reachable via Tor, port 30 closed to the world
# SSH access for YOU (the admin), choose with SSH_MODE=…:
#   onion  (default) port 22 closed to the internet, nmap sees nothing; you log in via Tor + SSH key only
#   closed           no SSH at all – you need your hosting provider's web/VNC console for maintenance
#   open             port 22 open, rate-limited, keys only (for initial setup)
set -euo pipefail
MODE="${1:-}"; DOMAIN="${2:-}"
SSH_MODE="${SSH_MODE:-onion}"
CHAT_PORT="${CHAT_PORT:-30}"
SSH_PORT="${SSH_PORT:-$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}')}"; SSH_PORT="${SSH_PORT:-22}"
[ "$(id -u)" = 0 ] || { echo "Bitte als root ausführen."; exit 1; }
[ "$MODE" = public ] || [ "$MODE" = onion ] || { echo "Nutzung: install.sh public <domain> | install.sh onion"; exit 1; }
[ "$MODE" = onion ] || [ -n "$DOMAIN" ] || { echo "Für 'public' wird eine Domain gebraucht."; exit 1; }
case "$SSH_MODE" in onion|closed|open) ;; *) echo "SSH_MODE muss onion, closed oder open sein."; exit 1;; esac

# never lock the admin out by accident: key-only SSH needs a key
HAVE_KEY=0
for f in /root/.ssh/authorized_keys /home/*/.ssh/authorized_keys; do [ -s "$f" ] && HAVE_KEY=1; done
if [ "$SSH_MODE" != closed ] && [ "$HAVE_KEY" = 0 ]; then
  echo "ABBRUCH: Kein SSH-Schlüssel hinterlegt (authorized_keys leer). Erst 'ssh-copy-id' ausführen,"
  echo "sonst sperrst du dich aus. Oder SSH_MODE=closed, wenn du nur die Anbieter-Konsole nutzen willst."
  exit 1
fi
if [ "$SSH_MODE" = closed ]; then
  echo "WARNUNG: SSH wird KOMPLETT geschlossen. Danach kommst du nur noch über die Web-/VNC-Konsole deines"
  echo "Server-Anbieters auf den Server. Bestehende Verbindungen bleiben bis zum Trennen bestehen."
  read -r -p "Zum Bestätigen 'SSH ZU' eintippen: " ok; [ "$ok" = "SSH ZU" ] || { echo "Abgebrochen."; exit 1; }
fi
SRC="$(cd "$(dirname "$0")/.." && pwd)"

apt-get update -qq
apt-get install -y -qq nftables nodejs npm >/dev/null
NODE_MAJOR="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
[ "$NODE_MAJOR" -ge 18 ] || { echo "Node.js >= 18 nötig (gefunden: $NODE_MAJOR). Siehe https://nodejs.org/en/download"; exit 1; }
command -v setfacl >/dev/null || apt-get install -y -qq acl >/dev/null
{ [ "$MODE" = onion ] || [ "$SSH_MODE" = onion ]; } && apt-get install -y -qq tor >/dev/null

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

# ---- SSH ----
case "$SSH_MODE" in
  open)
    SSH_RULES="tcp dport \$SSH_PORT ct state new meter ssh4 { ip saddr limit rate 4/minute burst 6 packets } accept
    tcp dport \$SSH_PORT ct state new meter ssh6 { ip6 saddr limit rate 4/minute burst 6 packets } accept" ;;
  onion)
    SSH_RULES="# SSH closed to the internet - reachable only through Tor (localhost)"
    grep -q betterchats-ssh /etc/tor/torrc || printf '\nHiddenServiceDir /var/lib/tor/betterchats-ssh/\nHiddenServicePort 22 127.0.0.1:%s\n' "$SSH_PORT" >> /etc/tor/torrc
    systemctl restart tor ;;
  closed)
    SSH_RULES="# SSH completely closed - maintenance via the hosting provider's console" ;;
esac
if [ "$SSH_MODE" != closed ]; then
  # keys only, no passwords, no root password login, short grace time
  install -d -m 755 /etc/ssh/sshd_config.d
  cat > /etc/ssh/sshd_config.d/90-betterchats.conf <<SSHD
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
PermitEmptyPasswords no
MaxAuthTries 3
LoginGraceTime 20
X11Forwarding no
AllowAgentForwarding no
SSHD
  sshd -t && systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
else
  systemctl disable --now ssh.socket ssh sshd 2>/dev/null || true
fi

sed -e "s/__SSH_PORT__/$SSH_PORT/" -e "s/__CHAT_PORT__/$CHAT_PORT/" "$SRC/deploy/nftables.conf" \
  | awk -v r="$CHAT_RULES" -v s="$SSH_RULES" '{ if ($0 ~ /__CHAT_RULES__/) print "    " r; else if ($0 ~ /__SSH_RULES__/) print "    " s; else print }' > /etc/betterchats/nftables.conf
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
case "$SSH_MODE" in
  open)   echo "SSH: Port $SSH_PORT offen (gedrosselt, nur Schlüssel). Später mit SSH_MODE=onion schließen." ;;
  onion)  echo "SSH: Port $SSH_PORT ist von außen ZU. Admin-Zugang nur über Tor:"
          echo "      torsocks ssh root@$(cat /var/lib/tor/betterchats-ssh/hostname 2>/dev/null || echo '<wird erzeugt – /var/lib/tor/betterchats-ssh/hostname>')"
          echo "      Diese Adresse GUT AUFBEWAHREN und nicht weitergeben." ;;
  closed) echo "SSH: komplett geschlossen. Wartung nur über die Konsole deines Server-Anbieters." ;;
esac
echo "Alles andere ist dicht. Ping wird ignoriert, Scanner werden gesperrt."
