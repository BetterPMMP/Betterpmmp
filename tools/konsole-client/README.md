# BetterPMMP-Konsole.exe

Windows-Programm mit Menü, das Konsolen-Befehle auf deinem BetterPMMP-Server ausführt,
während du spielst. Läuft in einem eigenen Fenster (Edge/Chrome App-Modus).

## Benutzen

1. Server mit BetterPMMP 5.69+ starten (RCON ist an, TCP-Port 19140).
2. Im Spiel auf den Server gehen und `/console koppeln` eingeben (nur mit Konsolen-Recht, also OP).
3. `BetterPMMP-Konsole.exe` starten, Server-Adresse eintragen, den 6-stelligen Code eingeben.
4. Fertig. Beim nächsten Start sucht die Konsole selbst, auf welchem gekoppelten Server du
   gerade online bist ("Wo bin ich?"), und verbindet sich.

Die Buttons unter "Mein Spieler" wirken auf deinen eigenen Spieler (`{player}`).
Eigene Befehle unten ins Feld tippen. Der Verlauf wird gespeichert.

- Kopplung aufheben: im Spiel `/console entkoppeln`, oder in der .exe beim Server auf ✕.
- Spielst du nicht auf dem PC, auf dem der Server läuft: Port 19140 TCP in Firewall/Router freigeben.
- Gespeichert wird in `%APPDATA%\BetterPMMP-Konsole\config.json` (Kopplungs-Token, kein Code).

## Selbst bauen

```
GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags "-s -w -H windowsgui" -o BetterPMMP-Konsole.exe .
```
