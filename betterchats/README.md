# BetterChats

Der Direktchat, der nichts speichert. Eigenständiges Produkt – hat nichts mit BetterPMMP/Minecraft zu tun.

## Was wird gespeichert?

| Daten | Wo | Wie |
|---|---|---|
| Nutzername | Server (`data/users.json`) | nur als HMAC-SHA256 mit geheimem Server-Schlüssel – nie im Klartext |
| Passwort (6 Ziffern) | Server (`data/users.json`) | scrypt mit zufälligem Salt + Server-Schlüssel – nie im Klartext |
| Nachrichten | **nirgends** | nur im Arbeitsspeicher des Browser-Tabs. Neu laden = weg |

## Wie funktioniert die Verschlüsselung?

- Bei jedem Seitenaufruf erzeugt der Browser ein neues ECDH-P-256-Schlüsselpaar. Der private Schlüssel ist nicht exportierbar und verlässt den Tab nie.
- Jede Nachricht wird mit AES-256-GCM verschlüsselt (Schlüssel via ECDH + HKDF). Absender/Empfänger sind als „additional data“ eingebunden, Nachrichten werden auf 256-Byte-Blöcke aufgefüllt (Länge bleibt verborgen).
- Der Server leitet nur Chiffretext an den Empfänger weiter, wenn dieser online ist, und vergisst ihn sofort. Ist der Empfänger offline, wird nichts zwischengespeichert.
- **Sicherheitsnummer** („🔒 Prüfen“): Beide Seiten sehen dieselbe Nummer. Vergleicht man sie persönlich, ist ausgeschlossen, dass jemand (auch der Server) dazwischen sitzt.

## Schutz gegen Angriffe

- **Keine SQL-Datenbank** → SQL-Injection ist nicht möglich.
- **XSS**: Inhalte werden nur per `textContent` eingefügt, dazu eine strikte Content-Security-Policy (keine Inline-Skripte, keine fremden Quellen).
- **Dateizugriff**: Der Server liefert nur Dateien aus `public/`, Namen per Whitelist (`../`-Tricks laufen ins Leere, `data/` ist nicht erreichbar).
- **Brute-Force auf die PIN**: Nach 5 Fehlversuchen wird der Name gesperrt (1 min, dann 2, 4, 8 … bis 24 h), zusätzlich max. 30 Fehlversuche pro IP und Stunde. Login-Zeit ist für existierende und nicht existierende Namen gleich.
- **Fremde Webseiten** dürfen keine WebSocket-Verbindung aufbauen (Origin-Prüfung), Clickjacking ist per `frame-ancestors 'none'` verboten.
- Größenlimits für Nachrichten, Rate-Limit pro Verbindung, keine Cookies, kein localStorage, `no-store`-Caching, `no-referrer`.
- Nur eine aktive Sitzung pro Name; bei Verbindungsverlust wird im Browser alles gelöscht.

## Starten

```bash
npm install
npm start          # http://localhost:8080
```

Umgebungsvariablen: `PORT`, `DATA_DIR`, `TLS_CERT` + `TLS_KEY` (HTTPS direkt), `TRUST_PROXY=1` (hinter Reverse-Proxy, damit die IP-Sperre die echte IP nutzt).

**Im Internet immer nur über HTTPS betreiben** (z. B. hinter Caddy/nginx mit Let's-Encrypt-Zertifikat). Die Browser-Verschlüsselung (WebCrypto) funktioniert außerhalb von `localhost` ohnehin nur über HTTPS.

## Ehrliche Grenzen

- Eine 6-stellige PIN hat nur 1 Mio. Möglichkeiten. Die Sperren machen Online-Raten praktisch unmöglich – wer aber die Server-Dateien **und** den Server-Schlüssel stiehlt, kann PINs offline durchprobieren. Den Server also gut schützen.
- Wer den Server kontrolliert, könnte manipulierten JavaScript-Code ausliefern. Dagegen hilft nur, den Server selbst sicher zu betreiben – und die Sicherheitsnummer zu vergleichen.
- Der Server sieht, *wer wann wem* schreibt (Metadaten), aber nicht *was*. Er speichert das nicht.
