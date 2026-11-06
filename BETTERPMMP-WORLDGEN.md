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
