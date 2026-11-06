# BetterPMMP – Vanilla-Parität (Stand 5.1-vanilla-beta.1)

Diese Datei listet ehrlich, welche Vanilla-Systeme wie weit umgesetzt sind.
„Vanilla“ heißt hier: Verhalten wie der offizielle Bedrock Dedicated Server.

Legende: ✅ weitgehend umgesetzt · 🟡 teilweise · ❌ fehlt

## Redstone

| Komponente | Stand | Hinweis |
|---|---|---|
| Staub, Leitungen, Abschwächung | ✅ | seit 4.3 |
| Hebel, Knöpfe, Druckplatten | ✅ | seit 4.3 |
| Repeater, Komparator, Fackel, Lampe, TNT | ✅ | seit 4.3, Bedrock-Timing nicht verglichen |
| Holz-/Kupfer-/Eisentüren | ✅ | **neu in 4.4**: Flankensteuerung, beide Hälften, Eisentür nur per Redstone |
| Falltüren (inkl. Eisen) | ✅ | **neu in 4.4** |
| Zauntore | ✅ | **neu in 4.4** |
| Notenblock | ✅ | **neu in 4.4**: Stimmen, Schlagen, steigende Flanke, Instrument nach Block darunter, nur mit Luft darüber |
| Trichter | 🟡 | **neu in 4.4**: Schieben/Ziehen, Item-Aufsammeln, 8-Tick-Cooldown, Sperre durch Redstone, Ofen-Seitenregeln, Doppeltruhen. Fehlt: Lore mit Trichter, Komposter, Braustand-Seitenregeln |
| Kolben / Klebekolben | ✅/🟡 | **neu in 4.7**: Bedrock-Regeln (keine Quasi-Konnektivität), Limit 12, Schleim/Honig ziehen Nachbarn mit, Obsidian/Tile-Blöcke unbeweglich, zerbrechliche Blöcke droppen, Kopf über PistonArm-Tile. Blöcke springen ohne moving_block-Animation |
| Beobachter | ✅ | **neu in 4.7**: 2 Ticks Verzögerung, 2 Ticks Impuls, stark versorgt nach hinten |
| Werfer / Spender | ✅/🟡 | **neu in 4.7**: Inventar-UI, Pfeile, Schneebälle, Eier, EP-Flaschen, Wurftränke, Wasser/Lava-Eimer setzen und aufnehmen, Feuerzeug, Feuerkugel, Knochenmehl, TNT; Werfer füllt Container. Fehlt: Rüstung anlegen, Boote/Loren, Shulker |
| Schienen-Logik (Antriebs-/Aktivierungsschiene) | 🟡 | Zustände vorhanden, keine Loren-Physik |
| Zielblock | ✅ | **neu in 4.7**: Signalstärke nach Trefferpunkt, 20 Ticks (Pfeil/Dreizack) bzw. 8 Ticks |
| Sculk-Sensor-Logik, Tageslichtsensor-Feinheiten | ❌ | Blöcke vorhanden (4.7), Vibrationen fehlen |

## Welt & Dimensionen

| System | Stand | Hinweis |
|---|---|---|
| Overworld-Generator | ✅/🟡 | **neu in 4.5**: Multi-Noise wie 1.18, y -64..319, 50 Oberflächen- + 3 Höhlenbiome, Deepslate. Nicht seedgleich mit Bedrock |
| Höhlen | ✅/🟡 | Käse-, Spaghetti-, Nudelhöhlen, Lava unter -55, **Schluchten, Geoden, Seen, Fossilien (4.6)**. Fehlen: Aquifere |
| Höhlenbiome | ✅/🟡 | **4.7**: Moos, Moosteppich, Tropfstein, Sculk, Sculk-Kreischer, -Katalysator, Pulverschnee, verdächtiger Sand/Kies |
| Dörfer | ✅/🟡 | **4.6**: verzweigte Straßen, 12 Berufsgebäude mit Arbeitsblock und Truhe, Häuser, Felder, Gehege, 5 Biomtypen |
| Andere Strukturen | ✅/🟡 | **4.6**: Mine, Festung mit Endportal, Verlies, Wüsten-/Dschungeltempel, Iglu, Hexenhütte, Außenposten, Ruinenportal, Schiffswrack, Ozeanruinen, Monument, Waldanwesen, Antike Stadt, Pfadruinen, Schatz (nachgebaut) |
| Nether | ✅/🟡 | 5 Nether-Biome; **4.6**: Netherfestungen, Bastionen, Ruinenportale |
| End | ✅/🟡 | **4.6**: Vanilla-Inselform, Säulen, Außeninseln, Chorus, End-Städte und -Schiffe (ohne Shulker) |
| Drachenkampf | ✅/🟡 | **neu in 4.9**: Kampf-Manager wie Vanilla (Zustand im Weltordner), 10 Kristalle einmalig, genau ein Drache, Bossbar für alle im Kampfgebiet, Flugphasen (Kreisen, Sturzflug, Feuerball-Wolke, Landung auf dem Portal mit Atem-Angriff, Abheben), Heilung durch Kristalle, Flügel-Rückstoß, 10 s Todesanimation mit 12000/500 EP, Ausgangsportal, Drachenei beim ersten Sieg, End-Gateways zu den Außeninseln (mit Rück-Gateway) |
| Portale (Nether/End) | ✅/🟡 | Nether/End werden beim Serverstart angelegt; Ankunft im End auf der Obsidian-Plattform; **4.9/5.0**: Enderauge fliegt direkt zum Portalraum der nächsten Festung (jede Festung hat ein vollständiges Portal, geprüft) |

## Kreaturen

| System | Stand | Hinweis |
|---|---|---|
| Vorhandene Mobs | 🟡 | Huhn, Kuh, Schwein, Schaf, Tintenfisch, Zombie, Dorfbewohner, Kupfergolem |
| Mob-KI (Pfadfindung, Ziele) | ✅/🟡 | **neu in 4.8**: A*-Wegfindung (Stufen springen, bis 3 Blöcke fallen, Lava/Feuer/Kaktus meiden, geschlossene Türen und Zäune als Hindernis, schwimmen), Ziele wie Vanilla (Sichtlinie, Folgereichweite, Rache, Zombies jagen Dorfbewohner, Endermen bei Blickkontakt, Spinnen tagsüber neutral, Zombie-Piglins rufen Hilfe), Nahkampf mit Schaden nach Schwierigkeit, Skelette halten Abstand und weichen seitlich aus, Eisengolems verteidigen, Tiere folgen Futter und laufen zur Paarung, Panik bei Schaden |
| Mob-Werte | ✅ | **neu in 4.8**: Vanilla-Leben, -Tempo, -Schaden, -Reichweite und -Größe für 75 Mobs |
| Natürliches Spawning nach Licht/Biom/Mob-Cap | ✅/🟡 | **neu in 4.8**: Kategorien mit Vanilla-Limits (70 Monster, 10 Tiere, 15 Fledermäuse pro Spieler), Gruppen-Spawning, gewichtete Biomtabellen (Wüste: Husks, Schnee: Eiswanderer, Sumpf: Bogged/Frösche/Schleime, Pilzinseln und Tiefes Dunkel: keine Monster), Monster bei Blocklicht 0, Schleimchunks, Tiere bleiben dauerhaft, Friedlich entfernt Monster |
| Wither | ✅/🟡 | **4.6**: Beschwörung, Aufladen + Explosion, Bossbar, 600 HP, Fernangriff (ohne Projektil) |
| Übrige Mobs, Wächter-Boss | ❌/🟡 | |
| Handel, Berufe, Level | ✅/🟡 | **neu in 4.7**: 13 Berufe über Arbeitsblöcke, 5 Level (Novize–Meister) mit Vanilla-EP-Grenzen, Bedrock-Handelsfenster, Nachschub am Arbeitsblock, EP für Spieler |
| Plünderer | ✅/🟡 | **4.7**: Außenposten (max. 6) und Patrouillen ab Tag 5; **5.0**: Außenposten haben von Anfang an 4–6 Wachen, Plünderer tragen Armbrüste. Fehlt: Überfälle (Raids) |
| Warden | ✅/🟡 | **neu in 5.0**: Sculk-Kreischer im Tiefen Dunkel und in Antiken Städten warnen (Dunkelheit, Schrei), bei der 4. Warnung erscheint der Warden |
| Nether-Spawning | ✅ | **neu in 5.0**: Vanilla-Tabellen pro Nether-Biom, Netherfestungen mit Lohen, Wither-Skeletten, Skeletten, Magmawürfeln, Zombie-Piglins; Ghasts brauchen Platz |
| Dorfbewohner-Alltag | ✅/🟡 | **neu in 5.0**: arbeiten tagsüber am Arbeitsblock (füllt Angebote auf), Bauern ernten und pflanzen neu, Treffen an der Glocke, schlafen nachts im eigenen Bett, fliehen vor Zombies/Illagern, Eisengolems ab 5 Dorfbewohnern, Nachwuchs bei freien Betten, Babys wachsen, von Zombies getötete Dorfbewohner werden zu Zombie-Dorfbewohnern (Normal 50 %, Schwer 100 %) |

## Survival

| System | Stand |
|---|---|
| Kampf, Rüstung, Hunger, XP | 🟡 (PMMP-Basis) |
| Verzauberungen | 🟡 (Verzauberungstisch aus dem Kern) |
| Amboss | ✅ **neu in 5.1**: Reparieren mit Material (25 % pro Stück), zwei Gegenstände kombinieren (+12 %), verzauberte Bücher, Verzauberungen zusammenführen (gleiche Stufe +1, Maximum), Umbenennen, Arbeitsstrafe (RepairCost), „Zu teuer“ ab 40 Stufen, Amboss nutzt sich ab (12 %) |
| Schleifstein | ✅ **neu in 5.1**: entfernt Verzauberungen (außer Fluch) mit EP-Rückgabe, verzauberte Bücher werden zu Büchern, zwei Gegenstände kombinieren (+5 %) |
| Brauen | 🟡 (Braustand aus dem Kern) |
| Loot-Tables für Truhen/Mobs | 🟡 |

## Reihenfolge der nächsten Schritte

1. ~~Fehlende Blöcke und Gegenstände registrieren~~ (4.7: 219 Blocktypen, 4.9: 152 Gegenstände – damit ist die ganze 1.26.60-Palette vorhanden). Mit Funktion: Elytren (Gleiten, Abnutzung), Streitkolben, Schild, Armbrust, Pinsel (Haltbarkeit). Ohne eigene Funktion bisher: Sattel, Leine, Boote, Loren, Bündel, Pferderüstung, Fisch-Eimer, Karten.
2. Wesen in Strukturen (Wächter, Shulker, Hexen).
3. ~~Mob-KI und Spawning~~ (4.8). Offen: Babyzombies, Türen einschlagen, Endermen-Teleport, Hexentränke, Creeper-Ladung.
4. Speichern geplanter Blockticks über Neustarts.
5. Verhalten der übrigen neuen Blöcke (Sculk-Sensor, Crafter, Tropfstein-Schaden …).

Jeder Punkt ist ein eigenes, großes Paket. „100 % Vanilla“ erreicht man nur Schritt
für Schritt, mit Vergleichstests gegen den offiziellen Bedrock-Server.

## Einstellungen (betterpmmp.yml)

Beim ersten Start entsteht `betterpmmp.yml` im Serverordner. Dort stellt man ein, in welchen
Welten Mobs spawnen (`worlds`, `disabled-worlds`) und welche Arten (Monster, Tiere, Fledermäuse,
Wasser, Dorfbewohner, Plünderer, Warden) samt Vanilla-Limits. Nether- und End-Welten verhalten
sich immer wie Vanilla.
