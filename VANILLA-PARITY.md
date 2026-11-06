# BetterPMMP – Vanilla-Parität (Stand 4.6-worldgen-beta.2)

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
| Kolben / Klebekolben | ❌ | braucht bewegte Blöcke (moving_block) |
| Beobachter | ❌ | Block ist noch nicht registriert |
| Werfer / Spender | ❌ | |
| Schienen-Logik (Antriebs-/Aktivierungsschiene) | 🟡 | Zustände vorhanden, keine Loren-Physik |
| Sculk-Sensor, Ziel-Block, Tageslichtsensor-Feinheiten | ❌ | |

## Welt & Dimensionen

| System | Stand | Hinweis |
|---|---|---|
| Overworld-Generator | ✅/🟡 | **neu in 4.5**: Multi-Noise wie 1.18, y -64..319, 50 Oberflächen- + 3 Höhlenbiome, Deepslate. Nicht seedgleich mit Bedrock |
| Höhlen | ✅/🟡 | Käse-, Spaghetti-, Nudelhöhlen, Lava unter -55, **Schluchten, Geoden, Seen, Fossilien (4.6)**. Fehlen: Aquifere |
| Höhlenbiome | 🟡 | üppige Höhlen und Tiefes Dunkel dekoriert; Moos/Tropfstein-Blöcke fehlen im Kern |
| Dörfer | ✅/🟡 | **4.6**: verzweigte Straßen, 12 Berufsgebäude mit Arbeitsblock und Truhe, Häuser, Felder, Gehege, 5 Biomtypen |
| Andere Strukturen | ✅/🟡 | **4.6**: Mine, Festung mit Endportal, Verlies, Wüsten-/Dschungeltempel, Iglu, Hexenhütte, Außenposten, Ruinenportal, Schiffswrack, Ozeanruinen, Monument, Waldanwesen, Antike Stadt, Pfadruinen, Schatz (nachgebaut) |
| Nether | ✅/🟡 | 5 Nether-Biome; **4.6**: Netherfestungen, Bastionen, Ruinenportale |
| End | ✅/🟡 | **4.6**: Vanilla-Inselform, Säulen, Außeninseln, Chorus, End-Städte und -Schiffe (ohne Shulker/Elytren) |
| Portale (Nether/End) | 🟡 | Nether/End werden beim Serverstart angelegt; Ankunft im End auf der Obsidian-Plattform |

## Kreaturen

| System | Stand | Hinweis |
|---|---|---|
| Vorhandene Mobs | 🟡 | Huhn, Kuh, Schwein, Schaf, Tintenfisch, Zombie, Dorfbewohner, Kupfergolem |
| Mob-KI (Pfadfindung, Ziele) | 🟡 | einfache KI, kein Vanilla-Goal-System |
| Natürliches Spawning nach Licht/Biom/Mob-Cap | ❌/🟡 | |
| Wither | ✅/🟡 | **4.6**: Beschwörung, Aufladen + Explosion, Bossbar, 600 HP, Fernangriff (ohne Projektil) |
| Übrige Mobs, Wächter-Boss | ❌/🟡 | |
| Handel, Dorf-Mechaniken | ❌ | |

## Survival

| System | Stand |
|---|---|
| Kampf, Rüstung, Hunger, XP | 🟡 (PMMP-Basis) |
| Verzauberungen | 🟡 (Teilmenge) |
| Brauen | ❌ |
| Loot-Tables für Truhen/Mobs | 🟡 |

## Reihenfolge der nächsten Schritte

1. Fehlende Blöcke und Gegenstände registrieren (Moos, Tropfstein, Seegras, Seetang, Sculk-Sensor, Komposter, Sattel, Elytren …).
2. Wesen in Strukturen (Wächter, Shulker, Hexen, Plünderer).
3. Kolben, Beobachter, Werfer/Spender.
4. Mob-Spawning nach Vanilla-Regeln + weitere feindliche Mobs.
5. Speichern geplanter Blockticks über Neustarts.

Jeder Punkt ist ein eigenes, großes Paket. „100 % Vanilla“ erreicht man nur Schritt
für Schritt, mit Vergleichstests gegen den offiziellen Bedrock-Server.
