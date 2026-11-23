# BetterPMMP – Prüfung aller Items und Blöcke (Stand 5.83)

Ermittelt aus dem Code: Welche Items und Blöcke der Bedrock-Palette 1.26.60 haben eigenes Verhalten,
welche sind nur registriert (man kann sie halten, setzen, lagern, aber sie tun nichts Besonderes).

Die Items und Blöcke, die PocketMine selbst schon kennt (Werkzeuge, Rüstung, Essen, Tränke, Redstone,
Erze, Holz, Pflanzen, Truhen, Öfen …), wurden hier nicht einzeln aufgeführt: Sie haben das Verhalten von
PocketMine plus die BetterPMMP-Erweiterungen aus `VANILLA-PARITY.md`. Geprüft wurden die rund 300 Items und
Blöcke, die BetterPMMP zusätzlich aus der Bedrock-Palette registriert.

## In 5.81 behoben

| Item | Fehler | Jetzt |
|---|---|---|
| Pappeltür, Pappelschild, Pappel-Hängeschild | ließen sich nicht setzen | werden gesetzt (Schild stehend oder an der Wand) |
| Seetang, Strohbett | aus dem Kreativinventar, aus Truhen oder nach einem Neustart nicht setzbar (nur per `/give`) | werden immer gesetzt |
| Kupfergolem-Spawn-Ei | tat nichts | spawnt einen Kupfergolem |

## Items mit eigenem Verhalten

Boote und Floße mit Truhe, Rüstungsständer, Sitzkissen, Fisch- und Axolotl-Eimer, Pulverschnee-Eimer,
blaue und braune Eier, Pinsel, Karottenrute, Wirrpilzrute, Loren mit Truhe/Trichter/TNT, Armbrust, Elytren,
leere Karte, gefüllte Karte, Leine, Streitkolben, Unheilvolle Flasche, Schild, Windkugel, Sattel (Pferde,
Schweine, Schreiter, Kamele), Pferderüstungen, Wolfsrüstung, Geschirre (Glücklicher Ghast), Prüfungsschlüssel
und unheilvolle Prüfungsschlüssel (Tresor), Leitstein-Kompass, Lohenrute und Breeze-Rute (Brauen, Amboss),
Gürteltier-Schuppe, Töpferscherben und Bannermuster (Beute in Strukturen).

## In 5.82 dazugekommen

Moos und Blasses Moos mit Knochenmehl, Laubstreu und Wildblumen (bis zu vier Stück, Knochenmehl), Busch,
Glühwürmchenbusch, trockenes Gras, Goldlöwenzahn und hängendes Moos brauchen Halt, Pappelstämme quer und
entrindbar, Pappellaub verrottet, Schallplatten „Tears“ und „Bounce“. Noch offen bei diesen Blöcken:
Glühwürmchen-Partikel, Moosteppich braucht keinen Halt.

## In 5.83 dazugekommen

Schmiedetisch für Bedrock-Spieler (Netherit-Aufwertung und Rüstungsbesätze), Besätze auch für Java-Spieler.

## Items ohne eigenes Verhalten

| Item | Was fehlt |
|---|---|
| Bündel (alle 17 Farben) | Gegenstände hineinlegen und herausnehmen |
| Nautilus-Rüstungen (Kupfer bis Netherit) | anlegen am Nautilus (der Nautilus selbst hat nur Standard-KI) |
| Töpferscherben | Muster am verzierten Krug beim Herstellen |
| Bannermuster | Muster am Webstuhl (ungeprüft) |
| Schwefelwürfel-Eimer | den Schwefelwürfel (neues 26er-Wesen) fangen und aussetzen |
| Befehlsblock-Lore | Befehle ausführen |

## Blöcke mit eigenem Verhalten

Regale, Bienennest und Bienenstock, Wolle- und Betonstufen/-treppen, Blasensäule, Sculk-Sensor (auch
kalibriert), Befehlsblöcke (Impuls, Kette, Wiederholung), Kirsch-/Blasseichen-/Pappelsetzling, Augenblüte,
Komposter, Aquäduktkern, Kupfertruhen, Crafter, Knarzherz, verzierter Krug, Werfer und Spender, getrockneter
Ghast, Endtransitportal, Froschlaich und Schnüffler-Ei, Schleifstein, Honigblock, Seetang, Leitstein,
Mangroven-Keimling, Beobachter, Kolben, Tropfstein, Pappel-Knöpfe/-Tür/-Zaun/-Zauntor/-Schilder/-Druckplatte/
-Falltür, Pulverschnee, Gerüst, Sculk-Katalysator, Sculk-Kreischer, Seegras, Strohbett, verdächtiger Sand und
Kies, Zielblock, Prüfungsspawner, Schildkrötenei, Tresor.

## Blöcke ohne eigenes Verhalten

Diese Blöcke kann man setzen und abbauen, aber sie verhalten sich nicht wie in Vanilla:

| Block | Was fehlt |
|---|---|
| Sculk-Ader | breitet sich nicht aus (Katalysator setzt sie aber) |
| Tropfsteinblock | (Tropfstein wächst nur an echtem Tropfstein) |
| Pilzblöcke, Pilzstiel | Drops passen, Seiten-Zustände beim Setzen nicht |
| Schwefelspitze, Eiskristall, Eiszapfen, Regalpilz, roter Strauch | neue Blöcke aus 26.x, Verhalten noch unbekannt |
| Strukturblock, Puzzleblock, Kamera, Tafel, Erlauben/Verweigern/Grenzblock | Bildungs- und Technik-Blöcke, im normalen Spiel ohne Bedeutung |
| moving_block, deprecated_*, disc_press | technische oder veraltete Einträge |

## Was diese Prüfung nicht abdeckt

- Ob jedes Verhalten genau dem offiziellen Server entspricht (Zeiten, Zufallswerte): dafür braucht es
  Vergleichstests im Spiel.
- Die Items und Blöcke aus PocketMine selbst im Detail.
- Leistung (siehe Timings auf dem eigenen Server).
