# BetterPMMP 4.5 — Weltgenerierung Beta 1

Neuer Oberwelt- und Nether-Generator nach dem Vorbild von Minecraft 1.18+.
Er ersetzt den alten PocketMine-Generator **nur für neue Welten**.

## Oberwelt (`vanilla`, Standard für neue Welten)

- **Weltgröße wie Vanilla:** y = -64 bis 319, Grundgestein unten, Deepslate unter y = 0
  (mit Übergang zwischen y = 0 und 8).
- **Multi-Noise-Biome** wie in 1.18: Temperatur, Feuchtigkeit, Kontinentalität,
  Erosion und Verwitterung entscheiden das Biom. Die Biom-Tabelle folgt dem
  Vanilla-Aufbau (Täler, niedrige/mittlere/hohe Bereiche, Gipfel, Varianten).
- **50 Oberflächen-Biome plus 3 Höhlenbiome**, u. a.: Ebene, Sonnenblumenebene, Wald, Blumenwald,
  Birkenwald (auch Urbirkenwald), Dunkler Wald, Taiga, Verschneite Taiga,
  Ur-Fichten-/Kiefertaiga, Savanne (auch Plateau und zerklüftet), Dschungel,
  lichter Dschungel, Bambusdschungel, Wüste, Tafelberge (normal, zerklüftet,
  bewaldet), Sumpf, Mangrovensumpf, Wiese, Kirschhain, Hain, Schneehänge,
  vereiste/zerklüftete/steinige Gipfel, Windgepeitschte Hügel (auch Kies und Wald),
  Pilzinsel, Fluss, gefrorener Fluss, Strand, Schneestrand, Steinküste und alle
  9 Ozeanarten (warm, lauwarm, normal, kalt, gefroren, jeweils auch tief).
- **Gelände:** Kontinente und Ozeane, Küsten, Flusstäler, Hochebenen, Berge bis
  über y = 200 mit Graten, Klippen und Überhängen.
- **Höhlen wie 1.18:** Käsehöhlen (große Hallen mit Säulen), Spaghettihöhlen
  (lange Tunnel, teils mit Eingängen an der Oberfläche) und Nudelhöhlen
  (dünne, verwinkelte Gänge). Lava unter y = -55.
- **Höhlenbiome:** üppige Höhlen (Azaleen, Leuchtbeeren-Ranken, kleines
  Tropfblatt, Lehm), Tiefes Dunkel (Sculk) und Tropfsteinhöhlen (Biom gesetzt).
- **Oberflächen je Biom:** Gras/Erde, Sand/Sandstein, Terrakotta-Schichten in
  Tafelbergen, Podsol/grobe Erde, Myzel, Schlamm, Schnee, Eis und Packeis,
  Kies, Kalzit, steile Hänge aus Stein, gefrorene Ozeane und Flüsse.
- **Erze nach 1.18-Höhenverteilung** inkl. Deepslate-Varianten: Kohle, Eisen,
  Kupfer, Gold (mehr in Tafelbergen), Redstone, Lapis, Diamant, Smaragd (Berge);
  dazu Granit, Diorit, Andesit, Tuff, Erde, Kies.
- **Vegetation je Biom:** Eiche, Birke (auch hohe), Fichte, Riesenfichte/-kiefer,
  Dschungel und Riesendschungelbaum, Akazie, Schwarzeiche, Kirsche, Mangrove,
  Sumpfeiche mit Ranken, Riesenpilze, Gras, Farne, doppelte Pflanzen, Blumen je
  Biom, Kakteen, tote Büsche, Zuckerrohr, Seerosen, Bambus, Melonen, Kürbisse,
  Süßbeeren, Rosa Blütenblätter, Korallenriffe im warmen Ozean, Eisstacheln,
  bemooste Felsbrocken, Wüstenbrunnen.
- **Dörfer** in Ebene, Wüste, Savanne, Taiga und Schnee: Brunnen mit Glocke, vier
  Wege, Häuser aus den Mojang-Vorlagen (so gedreht, dass die Tür zur Straße zeigt),
  Felder mit Wasser und Feldfrüchten, Laternenpfosten. Dorfbewohner erscheinen,
  sobald ein Spieler das Dorf betritt.
- Der Spawnpunkt wird auf Land in der Nähe von 0/0 gesucht.

## Nether (`vanilla_nether`, für neue Nether-Welten über Portale)

- Höhe 0–127, Grundgestein als Boden und Decke, Lavameer auf y = 31,
  große Höhlen.
- **Alle 5 Nether-Biome:** Nether-Ödland, Seelensandtal, Karmesinwald, Wirrwald,
  Basaltdeltas, jeweils mit eigener Oberfläche.
- Karmesin- und Wirrpilzbäume, Wurzeln, Sprossen, Trauer- und Zwirbelranken,
  Seelenfeuer, Basaltsäulen, Magma, Glowstone-Trauben an der Decke.
- Erze: Netherquarz, Nethergold, Magma, Antiker Schrott (nie an der Luft).

## Wichtig für bestehende Server

- **Bestehende Welten bleiben unverändert.** Sie haben den Generator `normal` in
  ihrer `level.dat` gespeichert und nutzen ihn weiter. So entstehen keine Brüche
  an der Grenze zu bereits erkundetem Gebiet.
- Neue Welten mit `level-type=DEFAULT` (Standard in `server.properties`) oder
  `level-type=vanilla` bekommen den neuen Generator.
- Wer die neue Welt sehen will: Server stoppen, alten Weltordner sichern und
  umbenennen, Server starten → neue Welt wird erzeugt.
- Neue Nether-Welten, die durch ein Portal entstehen, nutzen `vanilla_nether`.
  Ein bereits vorhandener Nether-Ordner behält seinen alten Generator.

## Bekannte Grenzen (ehrlich)

- **Nicht seedgleich mit Bedrock:** Gleicher Seed ergibt eine andere Welt als im
  offiziellen Spiel. Aufbau und Regeln sind nachgebildet, Mojangs Noise-Code nicht.
- **Fehlende Blöcke im Kern** (noch nicht registriert): Moos, Tropfstein,
  Pulverschnee, Seegras, Seetang. Deshalb haben üppige Höhlen kein Moos,
  Tropfsteinhöhlen keine Tropfsteine und Ozeane kein Seegras/Seetang.
- **Fehlende Strukturen:** Festungen (Stronghold), Minen, Antike Städte,
  Ozeanmonumente, Schiffswracks, Tempel, Außenposten, Waldanwesen, Ruinenportale,
  Netherfestungen, Bastionen. Dörfer nutzen nur die vorhandenen kleinen Häuser
  (keine Schmiede, Kirche usw., da deren Vorlagen fehlen).
- Keine Schluchten, Seen, Amethystgeoden; wassergefüllte Höhlen (Aquifere)
  vereinfacht.
- Nether-Biome sind 2D (in Vanilla 3D).
- Getestet mit einem Testaufbau ohne PocketMine-PHP-Erweiterungen:
  1.200 Chunks über 6 Seeds fehlerfrei, ca. 15–20 ms je Chunk für Erzeugung und
  Bevölkerung. Kein Test mit einem echten Minecraft-Client.

---

# BetterPMMP 4.6 — Weltgenerierung Beta 2: Strukturen, Dörfer, End

## Beim Serverstart

Nutzt die Hauptwelt den neuen Generator (`vanilla`), legt der Server beim Start
automatisch `<welt>_nether` (Generator `vanilla_nether`) und `<welt>_the_end`
(Generator `vanilla_end`) an bzw. lädt sie. Die Portale verbinden diese Welten.

## Strukturen (Oberwelt)

Platziert wie in Vanilla auf einem Raster mit Mindestabstand („spacing/separation“)
und nur in passenden Biomen. Große Strukturen gehen über viele Chunks.

| Struktur | Inhalt |
|---|---|
| **Dörfer** (Ebene, Wüste, Savanne, Taiga, Schnee) | Brunnen mit Glocke, verzweigte Straßen, kleine Häuser (Mojang-Vorlagen) und mittlere Häuser, Arbeitsgebäude: Waffenschmied (Esse mit Lava, Amboss), Werkzeugschmied (Schmiedetisch), Rüstungsschmied (Schmelzofen), Metzger (Räucherofen), Kartograf, Pfeilmacher, Fischer (Fass), Bibliothek (Lesepult, Bücherregale), Tempel (Braustand, Glocke), Steinmetz (Steinsäge), Schäfer (Webstuhl), Gerber (Kessel); Felder, Tiergehege, Laternen; Truhen mit Dorf-Beute; Betten in Farben; Material je Biom |
| Verlassene Mine | Gangnetz mit Holzstützen, Schienen, Spinnweben, Kreuzungen, Treppen, Truhen, Höhlenspinnen-Spawner; im Tafelberg-Biom aus Schwarzeiche |
| Festung (Stronghold) | 12–45 Räume: Gänge, Kreuzungen mit Brunnen, Gefängnis, Bibliothek mit Galerie, Truhengänge, Treppen; **Endportalraum** mit 12 Rahmen (je 10 % mit Auge), Lavabecken, Silberfisch-Spawner. Platzierung in Ringen wie Vanilla (erste 3 bei ca. 1400–2700 Blöcken) |
| Verlies | Bruchstein/Moosstein-Raum mit Spawner (Zombie/Skelett/Spinne) und Truhen |
| Wüstentempel | Pyramide mit Türmen, Terrakotta-Muster, Schatzkammer mit 4 Truhen und TNT-Falle |
| Dschungeltempel | Moosbruchstein-Tempel, 3 Ebenen, Hebel, Stolperdraht, 2 Truhen, Ranken |
| Iglu | Schneekuppel mit Bett, Ofen; 50 % mit Keller (Leiter, Braustand, Kessel, Truhe, Zellen) |
| Hexenhütte | Fichtenhütte auf Stelzen im Sumpf mit Kessel |
| Plünderer-Außenposten | Wachturm aus Schwarzeiche mit Truhe, Käfig, Zelt, Holzstapel; Abstand zu Dörfern |
| Ruinenportal | Zerbrochener Obsidianrahmen, Netherrack, Magma, Gold, Truhe; Varianten je Biom; auch im Nether |
| Schiffswrack | Rumpf, Mast, Kajüte, 3 Truhen (Vorrat, Karte, Schatz); auch kopfüber |
| Ozeanruinen | Stein- bzw. Sandsteinruinen mit Truhe, große Ruinen mit Nebenruinen |
| Ozeanmonument | 58×58 Prismarin, Flügel, Mittelturm, Räume, Meereslaternen, nasse Schwämme, 8 Goldblöcke |
| Waldanwesen | Mehrstöckig aus Schwarzeiche: Schlafzimmer, Bibliotheken, Lager, Spinnenraum, Speisesaal, Truhen |
| Antike Stadt | Riesige Höhle im Tiefen Dunkel auf y −51, Tor aus verstärktem Deepslate, Gebäude, Sculk, Seelenlaternen, Kerzen, Truhen |
| Pfadruinen | Vergrabene Terrakotta/Schlammziegel-Ruinen |
| Vergrabener Schatz | Truhe unter Stränden |

**Untergrund:** Schluchten (bis ~100 Blöcke lang), Amethystgeoden, Wasser- und
Lavaseen, Fossilien, Verliese.

## Nether

Netherfestungen (Brücken über der Lava, Gänge mit Truhen, Lohen-Spawner,
Netherwarzen-Räume) und Bastionen (Wohneinheiten, Ställe, Brücke, Schatzkammer mit
Magma-Kern und Gold), Ruinenportale.

## End (`vanilla_end`, für neue End-Welten)

- Inselform nach der Vanilla-Formel: Hauptinsel (~100 Blöcke Radius), Leere,
  Außeninseln ab 1024 Blöcken.
- 10 Obsidiansäulen (Höhe 76–103, zwei mit Käfig), Kristalle wie bisher über den
  Drachenkampf-Code, Ausgangsportal-Rahmen in der Mitte.
- Ankunft auf der Obsidian-Plattform bei (100, 49, 0) wie in Vanilla.
- Chorus-Pflanzen und End-Städte (Türme, Brücken, Truhen) mit End-Schiffen
  (Schatztruhen, Drachenkopf).

## Beute

35 Beute-Tabellen nach Vanilla-Vorbild (Verlies, Mine, Tempel, Festung,
Dorf-Berufe, Iglu, Schiffswrack, Schatz, Außenposten, Ruinenportal, Netherfestung,
Bastion, End-Stadt, Antike Stadt, Anwesen, Ozeanruine). Truhen, Spawner, Betten,
Öfen usw. bekommen ihre Tiles beim Laden des Chunks (neu: Tile-Daten werden vom
Generator-Thread mitgeschickt).

## Ehrliche Grenzen

- Die Strukturen sind **nachgebaut** (eigener Code), nicht Mojangs Original-Vorlagen
  (Ausnahme: die kleinen Dorfhäuser). Aussehen und Aufbau folgen Vanilla, Details
  weichen ab.
- **Fehlende Blöcke im Kern:** Moos, Tropfstein, Pulverschnee, Seegras, Seetang,
  Sculk-Sensor/-Kreischer/-Katalysator, verdächtiger Sand/Kies, Komposter,
  Schleifstein, Werfer, End-Gateway. Wo Vanilla sie nutzt, fehlen sie oder sind ersetzt.
- **Fehlende Gegenstände im Kern:** Sattel, Pferderüstung, Karten, Armbrust, Leine,
  Elytren. Sie werden in der Beute übersprungen; der Rahmen im End-Schiff bleibt leer.
- **Keine Wesen beim Generieren:** Wächter, Shulker, Hexen, Plünderer,
  Zombie-Dorfbewohner werden nicht gesetzt. Dorfbewohner erscheinen weiterhin über
  das bestehende Spawn-System.
- Nicht seedgleich mit Bedrock; die Drachensäulen sind in jeder Welt gleich angeordnet.
- Getestet im Testaufbau ohne PocketMine-PHP-Erweiterungen (768 Chunks in 3
  Dimensionen fehlerfrei, ca. 15–20 ms je Chunk). Kein Test mit einem echten Client.
