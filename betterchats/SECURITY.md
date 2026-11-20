# BetterChats – Sicherheitsdokumentation & Audit-Paket

Dieses Dokument ist für **unabhängige Sicherheitsprüfer** geschrieben. Es beschreibt Ziele, Angreifermodell,
Kryptografie, Protokoll, bekannte Grenzen und wie man alles testet. Ein externes Audit ist **noch nicht erfolgt**.

## 1. Sicherheitsziele

| # | Ziel |
|---|---|
| Z1 | Niemand außer Absender und Empfänger kann Nachrichteninhalte lesen – auch nicht der Server-Betreiber, auch nicht mit einem späteren Quantencomputer. |
| Z2 | Der Server speichert dauerhaft nur: HMAC(Name) und einen OPAQUE-Registrierungsdatensatz. Keine Nachrichten, keine Kontakte, keine IPs, keine Zeitstempel, keine Logs. |
| Z3 | Das Passwort verlässt nie das Gerät (auch nicht gehasht). |
| Z4 | Ein Netzwerkbeobachter (auch mit gebrochenem TLS) sieht nur gleich große, verschlüsselte Pakete mit Tarn-Traffic. |
| Z5 | Im Browser (DevTools: Elemente, Netzwerk, Speicher) stehen keine persönlichen Daten im Klartext. |
| Z6 | Chats existieren nur, solange beide online sind; danach ist alles gelöscht. |
| Z7 | Der Server ist robust gegen Scans, Injection, Brute-Force, Protokollmissbrauch und DoS-Versuche einfacher Art. |

## 2. Angreifermodell

| Angreifer | Fähigkeiten | Erwartung |
|---|---|---|
| Netzwerk (passiv/aktiv) | liest/verändert Verkehr | Z1, Z3, Z4 halten (TLS + Tunnel + E2E) |
| Böswilliger Server-Betreiber | kontrolliert Relay, sieht RAM | Z1, Z3 halten, **sofern Nutzer die Sicherheitsnummer vergleichen**; sieht aber wer gerade mit wem chattet (RAM). **Kann bei der Web-Version manipulierten JavaScript-Code ausliefern** (→ Apps, Hash-Prüfung). |
| Dieb der Server-Festplatte | `data/` | ohne `BETTERCHATS_PEPPER` (nur Umgebung) kein Offline-Raten möglich; mit Pepper: OPRF-Key + Argon2id → Offline-Raten der PIN möglich |
| Anderer Nutzer | kennt Namen | kann nur Online-Status abfragen (gedrosselt) |
| Browser-Erweiterung / Malware auf dem Gerät | liest Bildschirm/Tastatur | **außerhalb des Schutzbereichs** |

## 3. Kryptografie-Inventar

| Zweck | Verfahren | Implementierung |
|---|---|---|
| Passwort-Login | **OPAQUE** (RFC 9807), Ristretto255, Argon2id („memory-constrained“, RFC 9106) | `@serenity-kit/opaque` 1.1.0 (opaque-ke, WASM) |
| E2E-Schlüsseleinigung | **ECDH P-256 + ML-KEM-768** (FIPS 203), kombiniert über HKDF-SHA256 | WebCrypto + `@noble/post-quantum` 0.7.1 |
| Nachrichtenschlüssel | symmetrischer Hash-Ratchet (HMAC-SHA256), Schlüssel nach Benutzung gelöscht | `client/e2e.js` |
| Nachrichtenverschlüsselung | AES-256-GCM, 96-bit Zufalls-IV, AAD = Absender/Empfänger/alle öffentl. Schlüssel/Zähler/KEM-Ciphertext, Padding auf 256 Byte | WebCrypto |
| Transport-Tunnel | ECDH P-256 (ephemer pro Verbindung) → HKDF → AES-256-GCM, Zähler-Nonces, getrennte Schlüssel je Richtung | WebCrypto / Node crypto |
| Transport | TLS 1.3 only, HSTS | Node https |
| RAM-Tresor im Browser | AES-256-GCM, nicht exportierbarer Schlüssel pro Tab | WebCrypto |
| Namens-Speicherung | HMAC-SHA256(Server-Key ‖ Pepper, Name) | Node crypto |
| OPAQUE-Setup at rest | AES-256-GCM, Schlüssel aus Server-Key ‖ Pepper | Node crypto |
| Sicherheitsnummer | SHA-256 über beide (sortierte) Schlüsselsätze → 60 Ziffern | WebCrypto |

## 4. Protokoll

```
Verbindung:   S→C  {"t":"hello","spub":<P-256>}          (Klartext, einziger Klartext-Frame)
              C→S  {"t":"hello","cpub":<P-256>}
              danach nur Binär-Frames: AES-256-GCM(k_dir, nonce=Zähler, JSON gepolstert auf 4/8/16/32 KiB)
Registrierung: reg1{name, req} → reg1{res} → reg2{rec, pub, kpub} → ok
Login:         log1{name, req} → log1{res} → log2{fin, pub, kpub} → ok      (unbekannte Namen: Fake-Antwort gleicher Länge)
Konto löschen: del1{req} → del1{res} → del2{fin} → delete{ok}
Suche:         lookup{name} → lookup{exists, online, pub, kpub}          (20 Burst, +1 / 3 s)
Nachricht:     msg{to, pub, kpub, k, n, iv, ct, id} → beim Empfänger msg{from (vom Server gesetzt), pub, kpub, k, n, iv, ct}
               Absender: delivered | undelivered{offline | newkey}
Offline:       gone{name} an alle, die mit dem Nutzer Kontakt hatten (nur RAM)
Tarn-Traffic:  {"t":"c"} in beide Richtungen, exponentiell verteilte Abstände
```

E2E pro Richtung: `ck0 = HKDF(ECDH ‖ ML-KEM-SS, salt=0^32, info="BetterChats v4|"‖SHA-256(from|fromKeys|to|toKeys))`,
`mk_n = HMAC(ck_n, 0x01)`, `ck_{n+1} = HMAC(ck_n, 0x02)`. Empfänger hält max. 500 übersprungene Schlüssel und max. 200 Empfangsketten.

## 5. Bekannte Grenzen (bewusst)

1. **Web-Auslieferung:** Wer den Server kontrolliert, kann anderen Code ausliefern. Gegenmaßnahmen: `BUILD-HASHES.txt`, Apps mit eingebautem Code + Server-Key-Pinning (in `apps/`, pausiert).
2. **Keine langfristige Identität:** Schlüssel sind pro Sitzung neu → Sicherheitsnummer muss pro Sitzung verglichen werden. Kein Post-Compromise-Security-Ratchet (DH-Ratchet) innerhalb einer Sitzung.
3. **Metadaten im RAM:** Der Server sieht, wer gerade wem schreibt (nicht gespeichert, nicht geloggt).
4. **6-stellige PIN:** schwache Entropie; geschützt durch Online-Sperren, OPAQUE (kein Offline-Angriff ohne OPRF-Key), Pepper.
5. **Verschleierung ist keine Verschlüsselung:** Der ausgelieferte JavaScript-Code ist verschleiert (javascript-obfuscator, RC4-Stringtabelle). Der Browser muss ihn ausführen können, er ist also prinzipiell rekonstruierbar. Die Sicherheit beruht ausschließlich auf den Schlüsseln (Kerckhoffs-Prinzip).
6. **Canvas-Darstellung:** schützt vor DOM-Auslesen, nicht vor Screenshots/Bildschirmaufnahme auf dem Gerät (Desktop-App: `setContentProtection`, Android: `FLAG_SECURE`).
7. **JS-Speicher:** Garbage-Collector-Kopien von Klartext (z. B. beim Anzeigen) lassen sich in JavaScript nicht garantiert überschreiben.

## 6. Prüfumfang für das Audit (Vorschlag)

- `client/e2e.js`, `client/app.js` – E2E, Ratchet, Tunnel-Client, OPAQUE-Fluss, Canvas/DOM-Leaks
- `server.js` – Tunnel, OPAQUE-Server, Ratenbegrenzung, Bann-Logik, Relay, Header/CSP
- `build.js` – Build-Integrität, Verschleierung, CSP
- `deploy/` – nftables, systemd-Härtung, Installer
- Abhängigkeiten: `@serenity-kit/opaque`, `@noble/post-quantum`, `ws`
- Optional: `apps/desktop` (Electron-Härtung), `apps/mobile`

## 7. Tests

```bash
npm ci
npm run build
npm test            # 42 Tests: Krypto-Angriffe, Server-Angriffe, Fuzzing, Browser-E2E (Playwright, falls installiert)
npm run test:fuzz   # 1000 Fuzz-Runden
```

## 8. Wer kann das prüfen?

| Firma / Programm | Hinweis |
|---|---|
| **Open Technology Fund – Red Team Lab** | finanziert Audits für Open-Source-Datenschutz-Software **kostenlos** (Antrag nötig, Code muss offen sein) |
| **Radically Open Security** (NL) | gemeinnützig, vergleichsweise günstig, viel Erfahrung mit Messengern |
| **Cure53** (DE) | hat u. a. Signal-Komponenten, Wire, Threema geprüft |
| **NCC Group / Trail of Bits** | Kryptografie-Spezialisten, teuer |
| **Bug-Bounty** (z. B. intigriti, YesWeHack) | nach dem Audit, für laufende Prüfung |

Realistischer Umfang für dieses Projekt: ca. 5–10 Personentage.

## 9. Schwachstelle melden

Bitte **nicht öffentlich** melden, sondern direkt an den Betreiber (Kontakt beim Veröffentlichen hier eintragen).
