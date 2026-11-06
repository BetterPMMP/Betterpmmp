# BetterPMMP 4.3 — Redstone Beta 1

Dieser Entwicklungsstand erweitert den Serverkern von
`BetterPMMP-v4.2-EndBossbar-Bugfix.phar`. Er ist **keine vollständige
Vanilla-Engine**. Die API-Basis bleibt 5.51.0; die zusätzliche Build-Kennung
lautet `4.3-redstone-beta.1` und erscheint im Startprotokoll.

## Was hinzugekommen ist

- Serverseitige Redstone-Signalberechnung mit Stärke 0–15.
- Staubleitungen mit Abschwächung, Abzweigungen, mehreren Quellen und
  vollständigem Entladen von Schleifen nach Entfernen der Versorgung.
- Auf- und absteigende Leitungen; eine blockierte Steigung unterbricht das Signal.
- Bestehende Hebel, Knöpfe, Druckplatten und analoge Signalquellen speisen
  nun die Berechnung. Ihre Aktivierung bleibt in den jeweiligen Blockklassen.
- Gerichtete Repeater, Verzögerung von 1–4 Redstone-Ticks (2–8 Spielticks),
  Weitergabe kurzer Eingangsimpulse und seitliche Sperrung durch Dioden.
- Komparatoren: Vergleich/Subtraktion, gerichtete analoge Ausgabe und
  Auslesen von Containerinventaren, auch hinter einem leitenden Block.
- Inventaränderungen wecken benachbarte Komparatoren. Analogwerte werden
  weiterhin im vorhandenen Comparator-Tile gespeichert.
- Redstone-Fackeln als verzögerte Inverter.
- Lampen schalten bei Versorgung ein und nach vier Spielticks ohne Versorgung aus.
- TNT kann durch ein Redstone-Signal gezündet werden.
- Benachrichtigungen erreichen auch diagonale Leitungen und die andere Seite
  eines leitenden Blocks. `setBlock(..., false)` behält seine Bedeutung:
  Die aufrufende Anwendung muss dann Updates selbst auslösen.

## Technische Änderungen an der Tick-Planung

Geplante Blockupdates verwenden absolute Fälligkeit, eine eindeutige Kennung
und den erwarteten Blocktyp. Ein überholter Queue-Eintrag oder ein zwischenzeitlich
entfernter Block löst kein Update des Ersatzblocks aus. Ein früherer Termin kann
einen späteren ersetzen. Null-Tick-Anfragen bleiben möglich.

Beim Erstellen eines Tiles wird der aktuelle Terrainzustand statt eines noch
zwischengespeicherten Vorgängerblocks gelesen. Neue Komparatoren erhalten ihren
ersten Termin nach zwei Spielticks.

Pro Welt werden höchstens 10.000 Nachbarupdates je Spieltick abgearbeitet.
Verbleibende Updates bleiben in der Queue. Sehr große Schaltungen können dadurch
über mehrere Spielticks verteilt werden; dies ist keine Garantie für identisches
Bedrock-Timing unter Last.

## Bekannte Grenzen

- **Kolben/Klebekolben, bewegte Blöcke, Beobachter, Werfer, Spender, vollständige
  Trichterlogik sowie Türen/Klappen/Tore als Redstone-Verbraucher fehlen weiterhin.**
- Kein vollständiges Fackel-Burnout-Verhalten, keine garantierte Bedrock-Reihenfolge
  gleichzeitiger Updates und keine vollständige Nachbildung aller Puls-Sonderfälle.
- Leitende Vollblöcke werden über Vollwürfel/Transparenz erkannt. Einzelne
  Bedrock-Sonderfälle brauchen noch eine ausdrückliche Blocktabelle.
- Komparator-Sonderquellen wie Lesepulte, Rahmen, Kessel oder Kuchen sind nicht
  vollständig angebunden. Die hier ergänzte Inventarberechnung ist kein Ersatz dafür.
- Ausstehende Blockticks werden nicht über einen Serverneustart gespeichert.
  Automatische Wiederaufnahme beliebiger Schaltungen nach Chunk-Entladen ist noch
  nicht vollständig implementiert. Stabile Comparator-/Containerzustände wurden
  separat mit Speichern und Chunk-Neuladen geprüft.
- Kein Live-Test mit einem Minecraft-Client und kein vollständiger Vergleich mit
  dem offiziellen Bedrock-Server. Die vorhandene Netzwerkversion wurde nicht geändert.
- Bestehende Mob-, Weltgenerierungs-, Nether- und End-Funktionen wurden durch
  diese Redstone-Änderung nicht zu vollständiger Vanilla-Parität ausgebaut.

## Installation auf einem Testserver

1. Server mit `stop` beenden; bisherige Server-PHAR und Welten sichern.
2. Die neue PHAR als **Serverdatei** verwenden, nicht in `plugins/` ablegen.
   Falls das Startskript `PocketMine-MP.phar` lädt, die neue Datei unter diesem Namen
   einsetzen. Das vorhandene spezielle PMMP-PHP-Binary weiterverwenden.
3. Für diesen bereits als Entwicklungsbuild markierten Fork muss in
   `pocketmine.yml` unter `settings` der Wert `enable-dev-builds: true` stehen.
4. Starten und auf `BetterPMMP core build: 4.3-redstone-beta.1` achten.
5. Zuerst eine Kopie einer Testwelt benutzen. Parallel installierte Plugins,
   die dieselben Redstone-Blockklassen ersetzen, sind nicht zusammen geprüft.

Die Quelldateien und Testquellen liegen im separaten Source-ZIP. Das QA-Plugin
erstellt eine Testwelt und beendet den Testserver; es gehört **nicht** auf einen
laufenden Spielserver.

## Prüfen im Spiel

- Hebel → 16 Staubblöcke: Stärke fällt von 15 auf 0; Ausschalten entlädt alles.
- Hebel → Repeater → Lampe: alle vier Verzögerungen und Richtungen testen.
- Seitlicher gespeister Repeater sperrt einen zweiten Repeater in seinem Zustand.
- Truhe → Komparator → Staub: Items einlegen, entnehmen und Subtraktion prüfen.
- Hebel am Träger einer Fackel: Versorgung schaltet die Fackel nach kurzer Verzögerung aus.

## Nächste Schritte zum Vanilla-Ziel

1. Wiederaufnahme und Speicherung geplanter Redstone-Ticks sowie Chunkgrenzen.
2. Türen, Klappen und Tore mit korrekten Signalflanken und manueller Bedienung.
3. Kolben mit bewegten Blockzuständen, Schubgrenze, Haftung und Inventarbehandlung.
4. Beobachter, Werfer/Spender, Trichter und übrige Redstone-Komponenten.
5. Bedrock-Vergleichstests für Uhren, kurze Impulse, Update-Reihenfolgen und Sonderblöcke.
6. Danach weitere eigenständige Vanilla-Systeme: Mob-KI, Generierung/Strukturen,
   Dörfer/Handel, Dimensionen/Bosse und übrige Survival-Mechaniken.

## Referenzen und Herkunft

Der Code wurde direkt im bereitgestellten BetterPMMP-Kern weiterentwickelt.
Es wurde kein fremdes Redstone-Plugin eingebaut. Die vorhandenen Lizenzen und
Dateiköpfe bleiben gültig; die neue RedstonePower-Klasse steht ebenfalls unter
LGPL-3.0-or-later. Protokollinformationen sind keine Spielmechanik-Implementierung:

- https://github.com/Mojang/bedrock-protocol-docs
- https://github.com/pmmp/PocketMine-MP

Die Regeln und Prüfungen dieses Builds dokumentieren die implementierte Teilmenge,
nicht eine Zusicherung vollständiger Vanilla-Kompatibilität.

---

# BetterPMMP 4.4 — Redstone Beta 2

Neue Redstone-Verbraucher und Trichterlogik:

- **Türen** (Holz, Kupfer, Eisen): öffnen bei steigender und schließen bei fallender
  Signalflanke an einer der beiden Hälften. Ohne Flanke bleibt der von Hand gesetzte
  Zustand erhalten. Eisentüren lassen sich nicht mehr von Hand öffnen.
- **Falltüren** (inkl. Eisenfalltür, nur per Redstone) und **Zauntore** mit derselben Flankenlogik.
- Ein Verbraucher, der in ein bestehendes Signal gesetzt wird, öffnet sofort.
- **Notenblock**: Rechtsklick stimmt (0–24) und spielt, Schlagen spielt, steigende
  Signalflanke spielt. Instrument nach dem Block darunter; nur mit Luft darüber.
- **Trichter**: schiebt 1 Item in den Container in Blickrichtung und zieht 1 Item aus dem
  Container darüber bzw. sammelt Item-Entities ein. Cooldown 8 Spielticks; ein leerer
  Trichter, der ein Item erhält, wartet ebenfalls einen vollen Cooldown. Redstone-Signal
  sperrt den Trichter (Zustand `toggle_bit`). Öfen: von oben in den Eingang, von der Seite
  nur Brennstoff in den Brennstoffslot, unten wird das Ergebnis entnommen.
  Trichter laufen ereignisgesteuert (Inventaränderungen, Blockupdates, Items) statt per
  Dauerabfrage. Der Cooldown wird mit `TransferCooldown` gespeichert.

Grenzen: Die Flankenzustände von Türen/Notenblöcken liegen im Speicher und gehen bei
Neustart verloren (Bedrock-Blockzustände haben dafür kein Bit). Nach einem Neustart
gilt ein bereits versorgter Verbraucher beim nächsten Update als „neu versorgt“.
Kein Live-Test mit Client; nur statische Analyse (PHPStan Level 6) der geänderten Dateien.
