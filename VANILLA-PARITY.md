# BetterPMMP – Vanilla-Parität (Stand 5.34-vanilla-beta.1)

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
| Schienen + Loren | ✅/🟡 | **neu in 5.2**: Lore zum Mitfahren, folgt geraden, gebogenen und schrägen Schienen, Schwung mit Reibung, Antriebsschienen beschleunigen/bremsen, Aktivierungsschienen werfen ab. Fehlt: Truhen-/Trichter-/TNT-Lore, Sensorschiene |
| Zielblock | ✅ | **neu in 4.7**: Signalstärke nach Trefferpunkt, 20 Ticks (Pfeil/Dreizack) bzw. 8 Ticks |
| Sculk-Sensor-Logik, Tageslichtsensor-Feinheiten | ❌ | Blöcke vorhanden (4.7), Vibrationen fehlen |

## Welt & Dimensionen

| System | Stand | Hinweis |
|---|---|---|
| Overworld-Generator | ✅/🟡 | **neu in 4.5**: Multi-Noise wie 1.18, y -64..319, 50 Oberflächen- + 3 Höhlenbiome, Deepslate. Nicht seedgleich mit Bedrock |
| Höhlen | ✅/🟡 | Käse-, Spaghetti-, Nudelhöhlen, Lava unter -55, **Schluchten, Geoden, Seen, Fossilien (4.6)**. Fehlen: Aquifere |
| Höhlenbiome | ✅/🟡 | **4.7**: Moos, Moosteppich, Tropfstein, Sculk, Sculk-Kreischer, -Katalysator, Pulverschnee, verdächtiger Sand/Kies |
| Dörfer | ✅/🟡 | **5.3**: Dörfer werden beim Näherkommen mit 3–10 Bewohnern auf Straßen gefüllt; **4.6**: verzweigte Straßen, 12 Berufsgebäude mit Arbeitsblock und Truhe, Häuser, Felder, Gehege, 5 Biomtypen |
| Andere Strukturen | ✅/🟡 | **4.6**: Mine, Festung mit Endportal, Verlies, Wüsten-/Dschungeltempel, Iglu, Hexenhütte, Außenposten, Ruinenportal, Schiffswrack, Ozeanruinen, Monument, Waldanwesen, Antike Stadt, Pfadruinen, Schatz (nachgebaut) |
| Nether | ✅/🟡 | 5 Nether-Biome; **4.6**: Netherfestungen, Bastionen, Ruinenportale |
| End | ✅/🟡 | **4.6**: Vanilla-Inselform, Säulen, Außeninseln, Chorus, End-Städte und -Schiffe (ohne Shulker) |
| Drachenkampf | ✅/🟡 | **neu in 4.9**: Kampf-Manager wie Vanilla (Zustand im Weltordner), 10 Kristalle einmalig, genau ein Drache, Bossbar für alle im Kampfgebiet, Flugphasen (Kreisen, Sturzflug, Feuerball-Wolke, Landung auf dem Portal mit Atem-Angriff, Abheben), Heilung durch Kristalle, Flügel-Rückstoß, 10 s Todesanimation mit 12000/500 EP, Ausgangsportal, Drachenei beim ersten Sieg, End-Gateways zu den Außeninseln (mit Rück-Gateway) |
| Portale (Nether/End) | ✅/🟡 | Nether/End werden beim Serverstart angelegt; Ankunft im End auf der Obsidian-Plattform; **4.9/5.0**: Enderauge fliegt direkt zum Portalraum der nächsten Festung (jede Festung hat ein vollständiges Portal, geprüft) |

## Was noch fehlt (Bestandsaufnahme 5.28)

Aus dem Code ermittelt: Mobs, Blöcke und Items, die registriert sind, aber noch kein eigenes Vanilla-Verhalten haben.

| Bereich | Offen |
|---|---|
| Kern | Wasserbindung (Blöcke im Wasser), Bündel, Kisten-/Trichter-/Befehlsblock-Lore, Leitstein-Kompass, Nautilus-Rüstung, Befehlsblöcke, Strukturblöcke |
| Blöcke ohne Funktion | Seetang und Seegras (Wachstum), Tropfstein-Wachstum und fallende Stalaktiten, Blasensäule, Aquisator, Verzierter Krug (Inventar), Kupfertruhen (Inventar), Sculk-Katalysator und -Ader (Ausbreitung), Leitstein, neue Setzlinge (Kirsche, Blasseiche, Mangrove, Pappel wachsen nicht), Pappel-Holzset (Türen, Falltüren, Knöpfe, Schilder ohne Funktion), Beton-/Woll-Stufen und -Treppen (Form), Regale, Strohbett |
| Mobs mit Standard-KI | Piglin-Barbar, Hoglin/Zoglin, Fuchs, Schildkröte (Eier legen), Delfin, Axolotl, Panda, Eisbär, Lama (Truhe, Spucken), Mooshroom (Scheren), Kaninchen, Fledermaus, Allay, Plagegeist, Endermilbe, Silberfischchen (aus Blöcken), neue 26er-Mobs (Nautilus, Kamel-Wüstenzombie, Schwefelwürfel) |
| Abweichungen | Strukturen sind nachgebaut, nicht Block für Block Mojang; Welt nicht seedgleich; Bedrock-Redstone-Timing nicht verglichen; kalibrierter Sculk-Sensor ohne Frequenzen |
| Protokoll | 1.26.60/1.26.62 (Protokoll 2225). 1.26.70 ist noch Vorschau (Protokoll 2259) – Update nötig, sobald es erscheint |

## Neu in 5.34

| System | Stand |
|---|---|
| Java-Bridge: Spieler rutscht ständig | ✅ Plugins setzen oft unsichtbare Platzhalter-Wesen mit Größe 0 neben Spieler (z. B. einen Wither für die Bossbar). Java kennt Größe 0 nicht, das Wesen ist dort normal groß und schiebt den Spieler dauernd weg. BetterPMMP verschiebt solche Platzhalter (Größe unter 0,01, kein Namensschild) für Java-Spieler 64 Blöcke nach unten – Bossbar und alles andere bleiben |

## Neu in 5.33

| System | Stand |
|---|---|
| Java-Bridge: Kisten-Menüs und Inventar | ✅ eigenes ViaProxy-Plugin „BetterPMMP-Bridge“ (Quellcode in `tools/java-bridge-plugin`, wird automatisch nach `java-bridge/plugins/` installiert): Kisten-Fenster in jeder Größe (auch 54 Plätze), keine Block-Prüfung bei Menüs ohne echte Kiste, und Inventar-Klicks, die ViaBedrock noch gar nicht übersetzt, werden an BetterPMMP weitergeleitet. BetterPMMP führt sie wie ein Java-Server aus (Links-/Rechtsklick, Shift, Zahlentasten, F, Q, Doppelklick, Ziehen) – als normale Inventar-Transaktion, Menü-Plugins sehen die Klicks also wie bei Bedrock |
| ViaProxy-Version | ⚙️ fest auf den getesteten Dev-Build 964 (`viaproxy-build`), „latest“ = immer der neueste |
| Noch offen für Java | Ressourcenpakete, eigene Skins/Modelle, NPCs mit eigenen Modellen (hängt an ViaBedrock) |

## Neu in 5.31

| System | Stand |
|---|---|
| Java-Modus: Formulare als Chat-Menü | ⚙️ seit 5.32 nur noch mit `java-bridge: chat-forms: true` (ViaProxy zeigt die Fenster selbst). Java-Spieler (über die Java-Bridge) bekommen Formulare von Plugins als Chat-Menü: Knöpfe nummeriert (Zahl schreiben), Ja/Nein-Fenster mit 1/2, ausführliche Formulare Frage für Frage (Text, ja/nein, Auswahl, Zahl), 0 oder x schließt. Die Antwort geht wie bei Bedrock an das Plugin, Plugins müssen nichts ändern |
| Java-Bridge: Kisten-Menüs, Inventar, Blöcke setzen | 🟡 hängt an ViaBedrock (Übersetzung für 1.26.60 noch unfertig) |

## Neu in 5.30

| System | Stand |
|---|---|
| Java-Bridge: „Outdated client!“ behoben | ✅ Die stabile ViaProxy-Version (3.4.14) kann nur Bedrock 1.26.51, BetterPMMP spricht 1.26.60. BetterPMMP lädt jetzt den neuesten ViaProxy-Dev-Build (ci.viaversion.com), prüft bei jedem Start auf Updates und ersetzt eine alte ViaProxy.jar automatisch (`viaproxy-channel: dev` oder `stable`). Spricht ViaProxy trotzdem ein anderes Protokoll, steht die Ursache in der Konsole |

## Neu in 5.29

| System | Stand |
|---|---|
| Java-Bridge | 🟡 BetterPMMP lädt Java 21 (Linux x64/ARM, falls nötig) und ViaProxy, startet es auf Port 25565 (TCP), überwacht und beendet es. Erkennt nach ViaProxy-Updates die neue Bedrock-Version selbst. Java-Spieler ohne Xbox-Konto (Mojang-Prüfung durch ViaProxy), Name mit Präfix J_. Was Java-Spieler sehen, hängt von ViaBedrock ab (laut Entwicklern noch unvollständig) |

## Neu in 5.28

| System | Stand |
|---|---|
| Bienen | ✅ fliegen zu Blumen in 8 Blöcken, sammeln 10 s Nektar, bestäuben unterwegs Feldfrüchte, merken sich ihren Stock (gespeichert) und füllen ihn mit Honig; tagsüber unterwegs, nachts und bei Regen am Stock; wütend nach Angriff oder Ernte ohne Lagerfeuer, stechen (Gift 10 s / 18 s auf Schwer) und sterben danach. Bienen gehen nicht in den Stock hinein (keine gespeicherten Bewohner) |
| Bienenstock, Bienennest | ✅ Honigstufe 0–5, Schere → 3 Honigwaben, Glasflasche → Honigflasche, Lagerfeuer bis 5 Blöcke darunter beruhigt, Vorderseite zum Spieler, Nest nur mit Behutsamkeit |
| Pulverschnee | ✅ keine Kollision (Einsinken), kein Fallschaden, Erfrieren nach 7 s (Frost-Overlay, 1 Schaden alle 2 s), Lederstiefel, Eiswanderer, Schneegolem und Eisbär immun, löscht Feuer und schmilzt dabei, mit Eimer aufnehmen |
| Honigblock | ✅ kleinere Hitbox, verlangsamt, Rutschen an der Seite, 20 % Fallschaden |
| Spitzer Tropfstein | ✅ Spitze nach oben: doppelter Fallschaden; hängende Spitze unter Tropfsteinblock mit Wasser/Lava darüber füllt Kessel bis 10 Blöcke darunter; bricht ohne Halt |
| Schildkröteneier | ✅ 1–4 pro Block, schlüpfen auf Sand (meist nachts) in drei Stufen, Babyschildkröten; Zertrampeln durch Laufen/Landen (Schleichen schützt, Zombies gezielt) |
| Gerüst | ✅ Stabilität 0–7, Teile mehr als 6 Blöcke von der Stütze fallen ab, Gerüst auf Gerüst baut die Säule nach oben, kein Fallschaden darin |

## Neu in 5.27

| System | Stand |
|---|---|
| Phantome | ✅ erscheinen nachts über Spielern, die 3 Spieltage nicht geschlafen haben (über Meereshöhe, freier Himmel, alle 1–2 min, 1–4 Stück), kreisen und stoßen herab (6 Schaden), brennen am Tag; Schlafen setzt den Zähler zurück (Zähler gilt bis zum Server-Neustart) |
| Wächter, Großer Wächter | ✅ Laser: visiert sichtbare Spieler in 15 Blöcken an, lädt 2 s (3 s) mit sichtbarem Strahl, 6 (8) Schaden; Stacheln verletzen Nahkämpfer; Großer Wächter gibt jede Minute Abbaulähmung III in 50 Blöcken |
| Shulker | ✅ bleibt an seinem Platz, öffnet sich und schießt zielsuchende Geschosse (4 Schaden + 10 s Schweben), geschlossen 80 % weniger Schaden und Pfeile prallen ab, teleportiert sich bei wenig Leben; Geschosse lassen sich wegschlagen |
| Ertrunkene | ✅ 15 % tragen einen Dreizack und werfen ihn (bis 20 Blöcke, alle 2 s), geworfene Dreizacke sind nicht aufhebbar, 8,5 % lassen ihren fallen; brennen nur außerhalb des Wassers |

## Neu in 5.26

| System | Stand |
|---|---|
| Ghast | ✅ schwebt, schießt alle 3 s einen explodierenden Feuerball auf sichtbare Spieler (64 Blöcke), Feuerball lässt sich zurückschlagen |
| Lohe | ✅ hält Abstand, Salven aus 3 kleinen Feuerbällen (5 Schaden, Feuer), 5 s Pause, Schaden durch Schneebälle, Wasser und Regen |
| Schneegolem | ✅ wirft Schneebälle auf Monster, Schneespur außerhalb heißer Biome |
| Schleim, Magmawürfel | ✅ drei Größen, Leben/Schaden nach Größe, teilen sich beim Tod in 2–4 kleinere, Drops nach Vanilla-Regel |
| Piglin | ✅ Tauschhandel (Goldbarren → Gegenstand nach 6 s, Bedrock-Tabelle), lässt Spieler in Goldrüstung in Ruhe bis er angegriffen wird |
| Zombie-Dorfbewohner | ✅ heilen: Schwäche + Goldapfel, zittert 3–5 min, wird zum Dorfbewohner; brennt in der Sonne |
| Komposter | ✅ Pflanzen mit Vanilla-Chancen (30/50/65/85/100 %), 7 Schichten werden zu Kompost, gibt Knochenmehl |

## Neu in 5.25

| System | Stand |
|---|---|
| Hände tauschen | ✅ jedes Item zwischen Haupthand und Zweithand: `Player::swapHandItems()`, `PlayerSwapHandItemsEvent` (abbrechbar), Befehl `/swaphands` (`/swap`, `/offhand`), Geste „zweimal schnell schleichen“ (abschaltbar in betterpmmp.yml) |
| Spawner | ✅ alle 83 Mob-Arten per Spawn-Ei (Ei wird verbraucht, Käfig zeigt den Mob sofort), auch Tintenfische und andere Wasser-Mobs (nur im Wasser), Spawn-Partikel; Spawn-Ei auf Prüfungs-Spawner legt dessen Mob fest (gespeichert) |
| Rüstungsständer | ✅ aufstellen, Rüstung anlegen/tauschen, Item in die Hand geben, mit leerer Hand abnehmen (nach Klickhöhe), Schleichen + Benutzen wechselt die Pose (13), Schlag zerlegt ihn, wird gespeichert |
| Sitzkissen | 🟡 alle 16 Farben aufstellen, benutzen zum Hinsetzen, Schlag hebt es wieder auf (Farbdarstellung im Client ungeprüft) |

## Neu in 5.24

| System | Stand |
|---|---|
| Redstone-Schienen | ✅ Antriebs- und Aktivierungsschienen werden durch Redstone geschaltet und leiten den Strom bis zu 8 Schienen weiter; Sensorschiene gibt mit Lore darauf Signal 15 (stark nach unten) |
| Karten speichern | ✅ Karten werden zusätzlich im Serverordner `betterpmmp_maps/` gespeichert und von dort geladen; fehlt eine, steht in der Konsole wo gesucht wurde |
| Pferderüstung | ✅ Leder/Kupfer/Eisen/Gold/Diamant/Netherit am gezähmten Pferd, sichtbar, Rüstungswert 3/4/5/7/11/19, gespeichert, fällt beim Tod |
| Wolfsrüstung | ✅ am eigenen Wolf, nimmt den Schaden statt des Wolfs (64 Haltbarkeit), Hornschild repariert +8, Schere nimmt sie ab |
| TNT-Lore | ✅ zündet auf aktivierter Aktivierungsschiene, durch Feuer und Explosionen (4 s), explodiert sofort wenn sie schnell zerstört wird, Sprengkraft je nach Tempo |
| Pulverschnee-Eimer, bunte Eier | ✅ Pulverschnee platzieren; blaue und braune Eier werfen (1 : 8 Küken) |

## Neu in 5.20

| System | Stand |
|---|---|
| Gürteltier | ✅ rollt sich bei sprintenden Spielern/Untoten/Treffern ein (halber Schaden, bleibt stehen), wirft alle 5–10 min einen Hornschild ab, Pinsel bürstet einen ab (16 Haltbarkeit), vermehrt sich mit Spinnenaugen |
| Ziege | ✅ melken mit Eimer, rammt Spieler ab und zu (Rückstoß, Schaden), verliert beim Rammen von Stamm/Stein/Erz/Packeis ein Ziegenhorn (2 Hörner, gespeichert) |
| Frosch | ✅ Variante nach Biom (gemäßigt/kalt/warm), frisst Schleime (Schleimball) und Magmawürfel (Froschlicht in seiner Farbe), legt Froschlaich aufs Wasser statt Junge zu bekommen |
| Froschlaich, Kaulquappe | ✅ Laich schlüpft zu 2–6 Kaulquappen, Kaulquappen werden in 20 min zu Fröschen (Schleimball: −10 %), im Eimer fangbar |
| Schnüffler | ✅ gräbt etwa alle 8 min in Gras/Erde/Moos/Schlamm Fackelblumensamen oder Kannenpflanzenkapseln aus; Schnüffler-Ei bekommt Risse und schlüpft (auf Moos doppelt so schnell) |
| Sumpfskelett, Eiswanderer | ✅ Giftpfeile (4 s) bzw. Langsamkeitspfeile (30 s) |

## Neu in 5.18

| System | Stand |
|---|---|
| Kartentisch | ✅ **5.19**: Karte + Papier = eine Stufe größer (bis 4), + leere Karte = 2 Kopien, + Glasscheibe = gesperrte Karte (zeichnet nicht mehr weiter), Umbenennen, keine EP-Kosten |
| Schatzkarten | ✅ in Schiffswrack-Kartentruhen; beim ersten In-die-Hand-Nehmen wird der nächste Strand gesucht, die Karte zeigt das rote X, die Schatztruhe wird eingegraben, sobald man in die Nähe kommt (vanilla Schatz-Beute) |
| Entdeckerkarten | ✅ Kartograf (Lehrling: Ozean-Entdeckerkarte für 13 Smaragde + Kompass, Prüfungs-Entdeckerkarte für 12; Geselle: Waldland-Entdeckerkarte für 14) – zeigen den nächsten Ozeanmonument / das Waldanwesen / die Prüfungskammer mit Symbol |

## Neu in 5.17

| System | Stand |
|---|---|
| Crafter | ✅ Redstone-Impuls stellt ein Teil nach dem Rezept im 3x3-Raster her (alle Werkbank-Rezepte), gibt es nach vorn aus (in einen Behälter oder als Gegenstand), Eimer/Flaschen bleiben zurück, Plätze per Klick sperren (gespeichert), Ausrichtung beim Platzieren |
| Sculk-Sensor | ✅ Vibrationen durch Laufen (nicht Schleichen), Abbauen, Bauen, Treffer und landende Geschosse; Reichweite 8 (kalibriert 16), Signal 15 minus Entfernung, 30 Ticks aktiv, 10 Ticks Abklingzeit, Töne. Fehlt: Wolle dämpft nicht, Frequenzen des kalibrierten Sensors |
| Hexe | ✅ wirft Wurftränke (Langsamkeit, Gift, Schwäche, Schaden nach Vanilla-Regeln), trinkt Heilung/Feuerschutz/Wasseratmung, nur 15 % Magieschaden |
| Papagei auf der Schulter | ✅ gezähmte, stehende Papageien springen dem Besitzer auf die Schulter (max. 2), springen ab beim Springen/Fallen, Schwimmen, Fliegen, Schlafen |

## Happy Ghast (1.21.90)

| System | Stand |
|---|---|
| Ausgetrockneter Ghast | ✅ **neu in 5.16**: quillt in 4 Stufen (~20 min) auf, wenn Wasser angrenzt (Wasserbindung gibt es im Kern noch nicht), trocknet sonst wieder aus, wird zum Ghastling |
| Happy Ghast | ✅ **neu in 5.16**: Ghastling wächst in 20 min heran (Schneebälle: −10 %, heilen), Geschirr in allen Farben anlegen (sichtbar), bis zu 4 Spieler reiten oben, der erste fliegt in Blickrichtung (Springen: hoch), Schere nimmt das Geschirr ab, folgt Spielern mit Schneebällen, anleinbar, kein Fallschaden, greift nie an |

## Blassgarten (1.21.4)

| System | Stand |
|---|---|
| Blassgarten | ✅ **neu in 5.15**: Biom an der Vanilla-Stelle (Varianten-Hochebenen statt Dunkelwald), Blasseichen mit hängendem Blassmoos, Blassmoos und Moosteppiche am Boden, Augenblüten (öffnen sich nachts, schließen sich am Tag) |
| Knarzherz | ✅ **neu in 5.15**: in jedem zehnten Baum, nur zwischen zwei Blasseichenstämmen aktiv, wacht nachts auf und ruft einen Knarz (16 Blöcke) sobald ein Spieler in 32 Blöcken ist, schläft am Tag (Knarz zerfällt), abgebaut tötet es seinen Knarz und gibt 1–3 Harzklumpen (Behutsamkeit: das Herz selbst) |
| Knarz | ✅ **neu in 5.15**: bleibt stehen, solange ein Spieler ihn ansieht, sonst schleicht er heran (3 Schaden); vom Herz gerufen ist er unverwundbar – Treffer lassen am Herz Harz wachsen; aus dem Spawn-Ei ein normaler Mob |

## Prüfungskammern (1.21)

| System | Stand |
|---|---|
| Prüfungskammern | ✅ **neu in 5.13**: tief unter der Erde (y −40 bis −25, Abstand 34 Chunks), Halle aus Tuffziegeln und Kupfer mit Kupfergittern und Kupferlampen, Gänge, Seitenäste, Kammern mit Prüfungs-Spawnern, Tresoren und Vorratstruhen |
| Prüfungs-Spawner | ✅ **neu in 5.13**: wacht bei Spielern in 14 Blöcken auf, 6 Mobs (+2 pro weiterem Spieler), max. 2 gleichzeitig (+1), feste Mob-Art pro Spawner (Zombie, Wüstenzombie, Skelett, Eiswanderer, Spinne, Höhlenspinne, Schleim, Silberfischchen, Breeze, Sumpfskelett), Belohnung pro Teilnehmer (Prüfungsschlüssel, Essen, Pfeile, Smaragde …), 30 min Abklingzeit (gespeichert) |
| Tresor | ✅ **neu in 5.13**: mit Prüfungsschlüssel öffnen, 1–3 Gegenstände (Smaragde, Windkugeln, Diamanten, Armbrust, Schild, Verzauberte Bücher, Goldäpfel, Schallplatte „Precipice“ …), einmal pro Spieler und Tresor (gespeichert) |
| Breeze, Windkugel | ✅ **neu in 5.13**: Breeze springt umher, schießt alle 2 s Windkugeln, Pfeile prallen ab, kein Fallschaden, lässt Breeze-Ruten fallen; Windkugel werfbar (Abklingzeit 0,5 s), Rückstoß, Spieler schleudern sich hoch, kein Fallschaden, öffnet Türen/Falltüren/Zauntore, schaltet Hebel und Knöpfe |
| Unheilvolle Prüfungen | ✅ **neu in 5.14**: Böses Omen an einem Prüfungs-Spawner wird zum Prüfungs-Omen (15 min pro Stufe), Spawner werden unheilvoll (+4 Mobs, unheilvolle Prüfungsschlüssel, bessere Belohnung), ein Drittel der Tresore ist unheilvoll und braucht den unheilvollen Schlüssel: dort gibt es den **schweren Kern** (8 %), Verzauberte Goldäpfel, Flow-Rüstungsbesatz, Schallplatte „Creator“, Diamant- und Smaragdblöcke |
| Überfälle | ✅ **neu in 5.14**: Unheilvolle Flasche (Böses Omen I–V, 100 min) – im Dorf wird daraus Überfalls-Omen (30 s), dann beginnt der Überfall: 3/5/7 Wellen (Leicht/Normal/Schwer) + Bonuswelle ab Stufe II, Plünderer, Diener, Verwüster, Hexen, Magier nach der Vanilla-Tabelle, Horn, rote Bossleiste „Raid“, 15 s Pause zwischen Wellen, Sieg gibt **Held des Dorfes** (Rabatt 30 % + 6,25 % pro Stufe bei Dorfbewohnern), Niederlage wenn kein Dorfbewohner mehr lebt. Patrouillen-Anführer und Außenposten-Hauptleute tragen das unheilvolle Banner und lassen eine Unheilvolle Flasche fallen |
| Neue Effekte | ✅ **neu in 5.14**: sanfter Fall (kein Fallschaden), Böses Omen, Held des Dorfes, Prüfungs-Omen, Überfalls-Omen, Windgeladen (Windstoß beim Tod), Weben (Spinnweben beim Tod), Schleimig (2 Schleime beim Tod), Befallen (Silberfischchen bei Treffern), alle auch per `/effect` |

## Wetter

| System | Stand |
|---|---|
| Wetter | ✅ **neu in 5.9**: Vanilla-Zyklus (klar 0,5–7,5 Tage, Regen 0,5–1 Tag, Gewitter 3–13 min), Regen/Schnee/trocken nach Biom-Temperatur aus den Bedrock-Daten, wird in level.dat gespeichert, `/weather clear\|rain\|thunder [Sekunden]`, `/weather query`, `/toggledownfall` |
| Blitze | ✅ **neu in 5.9**: im Gewitter 1 : 100 000 pro Chunk und Tick, treffen bevorzugt Mobs, 5 Schaden + 8 s Feuer, Feuer am Boden (Normal/Schwer), geladene Creeper (doppelte Explosion), Schwein → Zombie-Piglin, Dorfbewohner → Hexe, Blitzableiter gibt Signal 15. **5.10**: Blitzableiter (offen zum Himmel) ziehen Blitze im Umkreis von 128 Blöcken an. Fehlt: Skelettpferd-Falle |
| Folgen | ✅ **neu in 5.9**: Schnee legt sich und Wasser gefriert in kalten Biomen, Regen löscht brennende Mobs und Feuer, bewässert Ackerboden, dunklerer Himmel (Monster spawnen im Gewitter auch tagsüber), Schlafen im Gewitter, Aufwachen beendet das Unwetter, Sog im Regen, Kanalisierung |

## Kreaturen

| System | Stand | Hinweis |
|---|---|---|
| Vorhandene Mobs | 🟡 | Huhn, Kuh, Schwein, Schaf, Tintenfisch, Zombie, Dorfbewohner, Kupfergolem |
| Mob-KI (Pfadfindung, Ziele) | ✅/🟡 | **neu in 4.8**: A*-Wegfindung (Stufen springen, bis 3 Blöcke fallen, Lava/Feuer/Kaktus meiden, geschlossene Türen und Zäune als Hindernis, schwimmen), Ziele wie Vanilla (Sichtlinie, Folgereichweite, Rache, Zombies jagen Dorfbewohner, Endermen bei Blickkontakt, Spinnen tagsüber neutral, Zombie-Piglins rufen Hilfe), Nahkampf mit Schaden nach Schwierigkeit, Skelette halten Abstand und weichen seitlich aus, Eisengolems verteidigen, Tiere folgen Futter und laufen zur Paarung, Panik bei Schaden |
| Mob-Werte | ✅ | **neu in 4.8**: Vanilla-Leben, -Tempo, -Schaden, -Reichweite und -Größe für 75 Mobs |
| Natürliches Spawning nach Licht/Biom/Mob-Cap | ✅/🟡 | **neu in 4.8**: Kategorien mit Vanilla-Limits (70 Monster, 10 Tiere, 15 Fledermäuse pro Spieler), Gruppen-Spawning, gewichtete Biomtabellen (Wüste: Husks, Schnee: Eiswanderer, Sumpf: Bogged/Frösche/Schleime, Pilzinseln und Tiefes Dunkel: keine Monster), Monster bei Blocklicht 0, Schleimchunks, Tiere bleiben dauerhaft, Friedlich entfernt Monster |
| Reiten | ✅/🟡 | **neu in 5.2**: Boote (alle Holzarten, Bambusfloß, Truhenboot ohne Inventar) mit 2 Plätzen, Pferde/Esel/Maultiere zähmen (Temperament, Füttern beruhigt, Abwerfen), Sattel, Skelettpferde, Schweine mit Karottenrute, Schreiter mit Wirrpilzrute, Kamele mit 2 Plätzen; Absteigen per Schleichen oder Taste, kein Fallschaden beim Reiten |
| Wither | ✅/🟡 | **4.6**: Beschwörung, Aufladen + Explosion, Bossbar, 600 HP, Fernangriff (ohne Projektil) |
| Übrige Mobs, Wächter-Boss | ❌/🟡 | |
| Handel, Berufe, Level | ✅/🟡 | **5.3**: Arbeitsblock-Suche bis 32 Blöcke (vorher 8 → Dorfbewohner blieben arbeitslos), Handelsspieler-Metadaten für das Bedrock-Handelsfenster, Dorfbewohner bleiben beim Handeln stehen; **neu in 4.7**: 13 Berufe über Arbeitsblöcke, 5 Level (Novize–Meister) mit Vanilla-EP-Grenzen, Bedrock-Handelsfenster, Nachschub am Arbeitsblock, EP für Spieler |
| Fahrender Händler | ✅ | **neu in 5.5**: Vanilla-Angebote (5 normale + 1 seltenes), Handelsfenster, spawnt alle 20 Minuten mit 25/50/75 % Chance mit zwei Händler-Lamas, verschwindet nach 40 Minuten |
| Endermen | ✅/🟡 | **neu in 5.5**: Projektile treffen nicht (Teleport), teleportieren bei Schaden, im Wasser, und zum Ziel; Blickkontakt macht wütend. Fehlt: Blöcke tragen |
| Plünderer | ✅/🟡 | **4.7**: Außenposten (max. 6) und Patrouillen ab Tag 5; **5.0**: Außenposten haben von Anfang an 4–6 Wachen, Plünderer tragen Armbrüste. Fehlt: Überfälle (Raids) |
| Zähmen | ✅ **neu in 5.11**: Wolf (Knochen, 1 : 3), Katze (roher Kabeljau/Lachs, 1 : 3), Papagei (Samen, 1 : 10; Keks vergiftet) – Herzen oder Rauch, sitzt nach dem Zähmen, Besitzer lässt sitzen/aufstehen, folgt ab 10 Blöcken, teleportiert ab 12 Blöcken, heilen mit Futter, Wolf-Halsband färben, gezähmte Wölfe (40 Leben) greifen an, was den Besitzer angreift oder von ihm angegriffen wird (außer Creeper), wilde Wölfe wehren sich, gezähmte Tiere verschwinden nie. Pferde, Esel, Maultiere: zähmen durch Reiten (5.4). Fehlt: Papagei auf der Schulter, Katzen verscheuchen Creeper, Ozelot-Vertrauen |
| Warden | ✅/🟡 | **neu in 5.0**: Sculk-Kreischer im Tiefen Dunkel und in Antiken Städten warnen (Dunkelheit, Schrei), bei der 4. Warnung erscheint der Warden |
| Nether-Spawning | ✅ | **neu in 5.0**: Vanilla-Tabellen pro Nether-Biom, Netherfestungen mit Lohen, Wither-Skeletten, Skeletten, Magmawürfeln, Zombie-Piglins; Ghasts brauchen Platz |
| Dorfbewohner-Alltag | ✅/🟡 | **neu in 5.0**: arbeiten tagsüber am Arbeitsblock (füllt Angebote auf), Bauern ernten und pflanzen neu, Treffen an der Glocke, schlafen nachts im eigenen Bett, fliehen vor Zombies/Illagern, Eisengolems ab 5 Dorfbewohnern, Nachwuchs bei freien Betten, Babys wachsen, von Zombies getötete Dorfbewohner werden zu Zombie-Dorfbewohnern (Normal 50 %, Schwer 100 %) |

## Survival

| System | Stand |
|---|---|
| Kampf, Rüstung, Hunger, XP | 🟡 (PMMP-Basis) |
| Verzauberungen | ✅ **neu in 5.8**: alle Bedrock-Verzauberungen, auch die 18, die der Kern nicht kannte (Bann, Nemesis der Gliederfüßer, Plünderung, Glück des Meeres, Köder, Wasserläufer, Fluch der Bindung, Harpune, Sog, Treue, Kanalisierung, Mehrfachschuss, Durchschuss, Schnellladen, Seelenläufer, Windstoß, Dichte, Bresche). Mit Vanilla-IDs, Seltenheit, Stufen, Kosten am Verzauberungstisch, Amboss-Gegenständen, `/enchant`-Namen und gegenseitigen Ausschlüssen |
| Werkzeuge | ✅/🟡 **neu in 5.8**: **Angel** (Auswerfen und Einholen, Biss nach 5–30 s, Köder −5 s pro Stufe, Fisch/Müll/Schätze mit Glück des Meeres, 1–6 EP, Mobs heranziehen); **Schild** (beim Schleichen nach 0,25 s erhoben, blockt Nahkampf, Pfeile und Explosionen von vorn, Haltbarkeit 1 + Schaden, Axt-Treffer sperren 5 s); **Streitkolben** (Smash-Angriff: 4/2/1 Schaden pro Fallblock, kein Fallschaden, Rückstoß rundherum, Dichte, Bresche, Windstoß); **Armbrust** (Laden 1,25 s, Schnellladen, geladener Pfeil bleibt im Gegenstand, Mehrfachschuss); **Dreizack** (Treue holt ihn zurück, Sog im Wasser, Harpune auch beim Wurf); **Pinsel** (seltsamer Sand/Kies in Wüstentempeln und Pfadruinen, 4 Stufen, Archäologie-Beute); **Fisch-Eimer** (Fische, Axolotl, Kaulquappen fangen und aussetzen); **Plünderung** bei Mob-Drops. **5.10**: Durchschuss (Pfeile gehen durch 1–4 Mobs), Feuerwerk aus der Nebenhand in die Armbrust (fliegt gerade, explodiert am Ziel); **Leine** (Tiere und Golems anleinen, an Zäune binden, Knoten lösen, reißt über 10 Blöcke) |
| Amboss | ✅ **neu in 5.1**: Reparieren mit Material (25 % pro Stück), zwei Gegenstände kombinieren (+12 %), verzauberte Bücher, Verzauberungen zusammenführen (gleiche Stufe +1, Maximum), Umbenennen, Arbeitsstrafe (RepairCost), „Zu teuer“ ab 40 Stufen, Amboss nutzt sich ab (12 %) |
| Schleifstein | ✅ **neu in 5.1**: entfernt Verzauberungen (außer Fluch) mit EP-Rückgabe, verzauberte Bücher werden zu Büchern, zwei Gegenstände kombinieren (+5 %) |
| Brauen | 🟡 (Braustand aus dem Kern) |
| Loot-Tables für Truhen/Mobs | 🟡 |
| Karten | ✅/🟡 | **neu in 5.3**: leere Karte benutzen → Karte der Umgebung (Vanilla-Raster, Vanilla-Farben, Höhenschattierung, Wassertiefe), zeichnet beim Laufen weiter, Spielermarkierung, gespeichert im Weltordner. Fehlt: Vergrößern/Kopieren/Sperren am Kartentisch, Schatzkarten |

## Reihenfolge der nächsten Schritte

1. ~~Fehlende Blöcke und Gegenstände registrieren~~ (4.7: 219 Blocktypen, 4.9: 152 Gegenstände – damit ist die ganze 1.26.60-Palette vorhanden). Mit Funktion: Elytren, Streitkolben, Schild, Armbrust, Pinsel, Angel, Fisch-Eimer, Boote, Loren, Karten (5.8). Ohne eigene Funktion bisher: Bündel.
2. Wesen in Strukturen (Wächter, Shulker, Hexen).
3. ~~Mob-KI und Spawning~~ (4.8). **5.10**: Babyzombies (5 %, halb so groß, 50 % schneller, 12 EP), Zombies schlagen auf Schwer Holztüren ein (12 s, Risse, Klopfen), geladene Creeper (5.9). Offen: Hexentränke, Hühnerjockeys.
4. Speichern geplanter Blockticks über Neustarts.
5. Verhalten der übrigen neuen Blöcke (Sculk-Sensor, Crafter, Tropfstein-Schaden …).

Jeder Punkt ist ein eigenes, großes Paket. „100 % Vanilla“ erreicht man nur Schritt
für Schritt, mit Vergleichstests gegen den offiziellen Bedrock-Server.

## Einstellungen (betterpmmp.yml)

Beim ersten Start entsteht `betterpmmp.yml` im Serverordner. Dort stellt man ein, in welchen
Welten Mobs spawnen (`worlds`, `disabled-worlds`) und welche Arten (Monster, Tiere, Fledermäuse,
Wasser, Dorfbewohner, Plünderer, Warden) samt Vanilla-Limits. Nether- und End-Welten verhalten
sich immer wie Vanilla.
