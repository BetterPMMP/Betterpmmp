# BetterChats

Der Direktchat, der nichts speichert. Eigenständiges Produkt – hat nichts mit BetterPMMP/Minecraft zu tun.

## Was wird gespeichert?

| Daten | Wo | Wie |
|---|---|---|
| Nutzername | Server (`data/users.json`) | nur als HMAC-SHA256 mit geheimem Server-Schlüssel – nie im Klartext |
| Passwort (6 Ziffern) | Server (`data/users.json`) | scrypt mit zufälligem Salt + Server-Schlüssel – nie im Klartext |
| Nachrichten | **nirgends** | nur im Arbeitsspeicher des Browser-Tabs. Neu laden = weg |

## Wie funktioniert die Verschlüsselung?

**Auf dem Weg (3 Schichten):**
1. **Ende-zu-Ende:** Bei jedem Seitenaufruf erzeugt der Browser ein neues ECDH-P-256-Schlüsselpaar (privater Schlüssel nicht exportierbar). Pro Kontakt werden daraus per HKDF zwei Ketten-Schlüssel abgeleitet (einer pro Richtung).
2. **Jede Nachricht hat ihren eigenen Schlüssel** (Hash-Ratchet). Benutzte Schlüssel werden sofort gelöscht → selbst wer später den Speicher ausliest, kann alte Nachrichten nicht entschlüsseln (Forward Secrecy). Ein vom Server wiederholt zugestellter (Replay-)Ciphertext wird verworfen.
3. **AES-256-GCM** mit Absender, Empfänger, beiden Schlüsseln und Zähler als „additional data“, aufgefüllt auf 256-Byte-Blöcke (die Länge bleibt verborgen). Darüber liegt noch TLS (HTTPS) – und optional Tor/VPN, siehe unten.

**Auf deinem Gerät:**
- Der Chatverlauf liegt **im Arbeitsspeicher nur verschlüsselt** (eigener, nicht exportierbarer AES-Schlüssel pro Tab). Entschlüsselt wird nur der gerade offene Chat zur Anzeige.
- Rechtschreibprüfung, Autokorrektur, Grammarly & Co. sind in allen Feldern abgeschaltet – Chrome/Edge können sonst getippten Text an Google/Microsoft schicken, *während du schreibst*.
- Keine Kontaktliste, kein Verzeichnis, keine Vorschau. Du musst den Namen deines Gegenübers kennen.
- Neu laden, Tab schließen, Abmelden, Verbindungsabbruch oder 30 Min. Inaktivität → alles wird gelöscht.
- Wechselst du den Tab/die App, wird der Chat unscharf gemacht.

**Sicherheitsnummer** („🔒 Prüfen“): Beide Seiten sehen dieselbe Nummer. Vergleicht man sie persönlich, ist ausgeschlossen, dass jemand – auch der Server – dazwischen sitzt. Da jede Sitzung neue Schlüssel bekommt, ändert sie sich nach jedem Login (das wird im Chat angezeigt).

## Schutz gegen Angriffe

| Angriff | Schutz |
|---|---|
| SQL-Injection | Es gibt keine Datenbank. |
| XSS / eingeschleuster Code | Inhalte nur per `textContent`; CSP ohne Inline-Skripte + **Trusted Types** (der Browser verbietet jede HTML-Injektion) |
| Dateien abrufen (`../`, `/data`) | Server lädt die Webseiten-Dateien beim Start in den RAM und liefert nur diese – es gibt keinen Dateisystem-Zugriff pro Anfrage |
| PIN durchprobieren | 5 Fehlversuche → Sperre 1, 2, 4 … min bis 24 h; max. 30 Fehlversuche pro IP/Stunde; gleiche Antwortzeit für existierende/unbekannte Namen |
| Server lahmlegen | max. 4 gleichzeitige Passwort-Prüfungen, max. 10 Verbindungen pro IP, Timeouts gegen Slowloris, Nachrichten- und Größenlimits |
| Massen-Registrierung | max. 5 neue Konten pro IP/Stunde, Obergrenze Gesamtkonten |
| Fremde Webseite benutzt deinen Login | Origin-Prüfung bei der Verbindung; keine Cookies |
| Clickjacking / Einbetten | `frame-ancestors 'none'`, `X-Frame-Options: DENY`, COOP/COEP/CORP |
| Replay durch den Server | Zähler + gelöschte Schlüssel |
| Kompressions-Angriffe (CRIME/BREACH) | WebSocket-Kompression aus |
| Daten-Diebstahl vom Server | Name nur als HMAC, PIN nur als scrypt. Optional `BETTERCHATS_PEPPER` als Umgebungsvariable: dann reicht der `data`-Ordner allein nicht zum Knacken |

## Nicht zurückverfolgbar?

- Der Server schreibt **keine Logs** – keine IPs, keine Nachrichten, keine Zeitpunkte.
- IP-Adressen werden nur für die Brute-Force-Sperre im RAM gehalten, und auch das nur als gehashter Wert mit einem Schlüssel, der alle 24 h neu gewürfelt wird.
- **Ehrlich:** Eine Webseite kann kein VPN „einbauen“. Jeder Server sieht technisch die IP, von der die Verbindung kommt. Für echte Anonymität: BetterChats im **Tor Browser** nutzen und den Server als **.onion-Dienst** betreiben (unten). Dann kennt niemand – auch nicht der Server-Betreiber – deine IP. Ein VPN geht auch, verlagert das Vertrauen aber auf den VPN-Anbieter.

## Starten

```bash
npm install
npm start          # http://localhost:8080
```

Umgebungsvariablen: `PORT`, `HOST`, `DATA_DIR`, `TLS_CERT` + `TLS_KEY` (HTTPS direkt), `TRUST_PROXY=1` (hinter Reverse-Proxy), `BETTERCHATS_PEPPER` (geheimer Zusatzschlüssel), `MAX_USERS`.

**Im Internet immer nur über HTTPS betreiben** (z. B. Caddy mit Let's Encrypt). Ohne HTTPS verweigert die Seite den Start.

### Als Tor-.onion-Dienst

```
# /etc/tor/torrc
HiddenServiceDir /var/lib/tor/betterchats/
HiddenServicePort 80 127.0.0.1:8080
```
Server mit `HOST=127.0.0.1 npm start` starten, Tor neu starten, Adresse steht in `/var/lib/tor/betterchats/hostname`.

## Ehrliche Grenzen

- Was du gerade tippst und der offene Chat stehen zwangsläufig im Klartext auf deinem Bildschirm. Schadsoftware auf deinem Gerät (Keylogger, Bildschirmaufnahme) kann keine Webseite der Welt verhindern.
- Eine 6-stellige PIN hat nur 1 Mio. Möglichkeiten. Online-Raten ist durch die Sperren praktisch unmöglich; wer aber Server-Dateien **und** Pepper stiehlt, kann offline raten.
- Wer den Server kontrolliert, könnte manipulierten JavaScript-Code ausliefern. Die geplanten Apps lösen das, weil der Code dann fest in der App steckt.
- Der Server sieht im RAM, *wer wem* gerade schreibt (sonst könnte er nicht zustellen) – aber nie *was*, und er speichert nichts davon.
