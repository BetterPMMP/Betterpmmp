# BetterChats

Der Direktchat, der nichts speichert. Eigenständiges Produkt – hat nichts mit BetterPMMP/Minecraft zu tun.

## Das Prinzip

- Konto = **Name + 6-stellige Zahlen-PIN**. Das ist alles, was jemals gespeichert wird – und auch das nur verschlüsselt (Name als HMAC, PIN als scrypt).
- **Keine Kontaktliste, kein Verzeichnis, keine Suche.** Du musst den Namen deines Gegenübers kennen.
- Ist die Person offline, steht da „*xy* ist offline“. Nachrichten werden **nie** zwischengespeichert.
- **Geht einer von beiden offline, ist der Chat bei beiden weg.** Neu laden, Tab schließen, Abmelden, Verbindungsabbruch, 30 Min. Inaktivität → alles weg.
- Keine Uhrzeiten, keine Lesebestätigungen, keine Vorschau.

## Verschlüsselung – 5 Schichten

| Schicht | Was | Schützt gegen |
|---|---|---|
| 1. Ende-zu-Ende, **Post-Quanten-hybrid** | ECDH P-256 **+ ML-KEM-768** (NIST-Standard, wie Signal PQXDH / iMessage PQ3) → HKDF. Neue Schlüssel bei jedem Login, private Schlüssel nicht exportierbar | Server, Hacker, Geheimdienste – auch mit künftigen Quantencomputern (man müsste **beide** Verfahren brechen) |
| 2. Schlüssel pro Nachricht | Hash-Ratchet, benutzte Schlüssel werden sofort gelöscht | Spätere Kompromittierung (Forward Secrecy), Replay |
| 3. AES-256-GCM + Padding | Absender/Empfänger/Schlüssel/Zähler authentifiziert, Länge auf 256-Byte-Blöcke aufgefüllt | Manipulation, Längen-Analyse |
| 4. **Tunnel** | Zusätzliche AES-256-GCM-Verschlüsselung jedes Datenpakets zwischen Browser und Server (eigener ECDH pro Verbindung, Zähler-Nonces). Alle Pakete gleich groß + zufälliger **Tarn-Traffic** in beide Richtungen | Mitlesen im Browser-Netzwerk-Tab (DevTools), Verkehrsanalyse (wann/wie viel wird geschrieben) |
| 5. TLS 1.3 (HTTPS) | nur TLS 1.3, HSTS | Netzwerk |

**Auf dem Gerät:**
- Chatverlauf liegt im **Arbeitsspeicher nur verschlüsselt** (eigener Schlüssel pro Tab).
- **Entwürfe**: Sobald du Tab/App verlässt, wird das Getippte verschlüsselt und aus dem Feld gelöscht. Zurückkommen → wieder da.
- Nachrichten werden als **Bild (Canvas)** gezeichnet, nicht als Text → „Element untersuchen“ zeigt keinen Text, Erweiterungen können ihn nicht einfach auslesen.
- **Sichtschutz** (standardmäßig an): Nachrichten unscharf, bis du drüberfährst/tippst. Tab-Wechsel → alles unscharf.
- Rechtschreibprüfung/Autokorrektur/Grammarly aus – sonst schicken Chrome/Edge Getipptes an Google/Microsoft.
- **Selbstzerstörung** pro Chat (30 s / 5 min / 1 h) – bei dir und beim Empfänger.
- **Sicherheitsnummer** + Warnung „Nicht verifiziert“, bis ihr die Nummer verglichen habt. Stimmt sie, kann niemand dazwischen sitzen – auch nicht der Server.

**Quellcode:** Was der Browser bekommt, ist **verschleiert** (umbenannte Variablen, verschlüsselte Texttabelle, verwürfelter Ablauf) – nicht lesbar. Der lesbare Code liegt nur in `client/`. `BUILD-HASHES.txt` enthält die SHA-256-Prüfsummen, damit man prüfen kann, dass der Server genau diesen Build ausliefert.

## Server-Schutz („Firewall“)

**In der App eingebaut:**
- **Tarnung:** Mit gesetzter `DOMAIN` antwortet der Server auf nichts anderes. Scans per IP, falsche Domain/SNI, unbekannte Pfade (`/.env`, `/wp-admin`…), Müll-Daten → **keine Antwort**, Verbindung wird kommentarlos getrennt. Ohne passende SNI wird nicht mal das TLS-Zertifikat gezeigt. Ergebnis `nmap -sV`: `30/tcp open unknown` – kein Dienst, keine Version, kein Name.
- **Auto-Bann:** Wer scannt, probiert oder angreift, wird 1 h gesperrt (bereits auf TCP-Ebene, vor dem ersten Byte).
- PIN-Raten: 5 Fehlversuche → Sperre 1, 2, 4 … min bis 24 h; max. 30 pro IP/h; gleiche Antwortzeit für existierende/unbekannte Namen.
- Überlastung: max. 4 gleichzeitige PIN-Prüfungen, 10 Verbindungen/IP, Timeouts, Größen- und Ratenlimits, max. 5 neue Konten/IP/h.
- Keine Datenbank (→ keine SQL-Injection), Dateien beim Start in den RAM geladen (→ kein Dateizugriff von außen), nur GET/HEAD.
- Browser-Härtung: strenge CSP + **Trusted Types**, COOP/COEP/CORP, kein Einbetten, keine Cookies, kein Speicher, keine Kompression (CRIME/BREACH).
- **Keine Logs.** IPs nur als gehashter Wert im RAM, Schlüssel wird täglich neu gewürfelt.

**System-Firewall (`deploy/nftables.conf`):** alles dicht, geschlossene Ports antworten nicht („filtered“), kein Ping, Scan-Pakete (NULL/XMAS/FIN…) verworfen, 2 Verbindungsversuche auf geschlossene Ports → 1 h Bann, SSH und Chat-Port pro IP gedrosselt.

**Gehärteter Dienst (`deploy/betterchats.service`):** eigener Nutzer ohne Rechte, darf nur Port 30 öffnen, **kein Swap** (RAM landet nie auf der Platte), keine Core-Dumps, Dateisystem schreibgeschützt, Sandbox.

## Port 30 und „unsichtbar für nmap“

- Standard-Port ist **30** (`PORT` ändert ihn).
- **Ehrlich:** Ein Port, auf den Nutzer sich verbinden sollen, kann für nmap nicht *komplett* unsichtbar sein – sonst kämen auch die Nutzer nicht hin. Im **Public-Modus** sieht nmap nur „offen, unbekannt“.
- **Wirklich unsichtbar** geht nur mit dem **Onion-Modus**: Port 30 ist von außen komplett zu, Zugriff nur über Tor. nmap findet **gar nichts**, und niemand – auch nicht du als Betreiber – sieht die IP der Nutzer. Das ist gleichzeitig das „eingebaute VPN“.

## Installation auf einem Server (Debian/Ubuntu)

```bash
# Öffentlich mit eigener Domain (vorher: certbot certonly --standalone -d chat.example.com)
sudo bash deploy/install.sh public chat.example.com
# → https://chat.example.com:30

# Unsichtbar über Tor
sudo bash deploy/install.sh onion
# → gibt die .onion-Adresse aus (im Tor Browser öffnen)
```

## Lokal ausprobieren

```bash
npm install
npm run build      # client/ → public/ (verschleiert)
sudo npm start     # Port 30 braucht Rechte – oder: PORT=8080 npm start
```

Umgebungsvariablen: `PORT` (30), `HOST`, `DOMAIN`, `DATA_DIR`, `TLS_CERT`/`TLS_KEY`, `TRUST_PROXY`, `BETTERCHATS_PEPPER`, `MAX_USERS`, `COVER_TRAFFIC=0` (Tarn-Traffic aus).

## Ehrliche Grenzen

- Was gerade auf deinem Bildschirm steht bzw. was du in dem Moment tippst, muss für deine Augen lesbar sein. Ein Virus/Keylogger auf deinem eigenen Gerät sieht das – das kann keine Software der Welt verhindern.
- Code im Browser kann nicht *verschlüsselt*, nur *verschleiert* werden – sonst könnte der Browser ihn nicht ausführen. Die Sicherheit hängt nicht davon ab, dass der Code geheim ist, sondern von den Schlüsseln.
- 6-stellige PIN = 1 Mio. Möglichkeiten. Online-Raten ist durch die Sperren praktisch unmöglich; wer aber Server-Daten **und** Pepper stiehlt, kann offline raten.
- Wer den Server übernimmt, könnte manipulierten Code ausliefern → Sicherheitsnummer vergleichen, Hashes prüfen; die Apps lösen das später endgültig.
- Der Server weiß im RAM, *wer gerade mit wem* chattet (sonst kann er nicht zustellen), nie *was*. Er speichert nichts davon.
