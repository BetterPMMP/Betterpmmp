<a name="english"></a>
# BetterPMMP — Vanilla Engine for Minecraft Bedrock 1.26.60

**English** · [Deutsch](#deutsch)

BetterPMMP is a server software for Minecraft: Bedrock Edition 1.26.60, based on
PocketMine-MP / AxolotlMP. Standard PocketMine-MP is a lightweight server core:
it has no redstone, a simple world generator, no mob AI, no structures, no
villager trading and many blocks and items without any function. BetterPMMP
keeps PocketMine's network layer, world storage and plugin API, and adds a
vanilla game engine on top so that you can play survival from start to finish.

GitHub: https://github.com/BetterPMMP/Betterpmmp

Current version: **5.31-vanilla-beta.1** (API 5.51.0, plugins for PocketMine-MP 5 keep working).

> Status: beta. Many systems behave like vanilla, but not every detail matches
> the official Bedrock Dedicated Server yet. See `VANILLA-PARITY.md` for an honest
> per-feature status.

## What BetterPMMP adds compared to standard PocketMine-MP

### World generation
- New overworld generator like 1.18+: world height −64 to 319, deepslate, multi-noise
  biomes (50+ surface biomes, lush caves, deep dark, dripstone caves), mountains up to
  y 200+, rivers, coasts, all 9 ocean types.
- Caves: cheese, spaghetti and noodle caves, ravines, geodes, lakes, fossils, lava below y −55.
- Pale garden biome (1.21.4) with pale oaks, hanging moss, eyeblossoms and creaking hearts.
- Structures: villages (5 biome styles, streets, all 12 job buildings, farms, pens),
  mineshafts, strongholds with end portal, dungeons, desert and jungle temples, igloos,
  witch huts, pillager outposts, ruined portals, shipwrecks, ocean ruins, ocean monuments,
  woodland mansions, ancient cities, trail ruins, buried treasure, **trial chambers**.
- Nether generator with 5 biomes, nether fortresses, bastions.
- Vanilla End: island shape, obsidian pillars, outer islands, chorus plants, end cities and end ships.
- All blocks and items of the 1.26.60 palette are registered.

### Dimensions, bosses and progression
- Nether and End are created automatically; working nether and end portals.
- Eye of ender flies to the nearest stronghold; end gateways; elytra gliding.
- Ender dragon fight like vanilla: crystals, flight phases, breath attack, boss bar,
  exit portal, dragon egg, end gateways.
- Wither: summoning, boss bar and attacks.

### Redstone
- Full signal engine: dust, repeaters, comparators, torches, lamps, TNT, doors,
  trapdoors, fence gates, note blocks, hoppers.
- Pistons and sticky pistons (Bedrock rules), observers, dispensers and droppers, target blocks.
- Rails: minecarts with real rail physics, powered, activator and detector rails, TNT minecart.
- Crafter (1.21), sculk sensors and calibrated sculk sensors, lightning rods.

### Mobs and AI
- A* pathfinding: mobs walk around obstacles, jump up blocks and swim.
- Vanilla natural spawning: categories, caps, packs, biome spawn tables, light levels.
- Hostile mobs with melee and ranged combat; baby zombies; zombies break doors on Hard;
  creepers (charged by lightning); witches throw potions; bogged and strays shoot tipped arrows.
- Breeze, creaking, warden (summoned by sculk shriekers), pillager patrols and captains.
- Animals with breeding, babies and food: armadillo, goat, frog and tadpole, sniffer and more.
- Taming: wolves, cats, parrots (also on your shoulder), horses, donkeys, mules.
- Riding: horses, pigs, striders, camels, boats, minecarts, **happy ghast** (up to 4 riders).
- Leads and lead knots on fences.

### Villages and trading
- Villagers with all professions, job sites, levels (novice to master), vanilla trades,
  restocking, daily schedule (work, meet at the bell, sleep in their bed), breeding.
- Wandering trader with trader llamas.
- **Raids**: ominous bottle, bad omen, raid omen, waves by difficulty, raid boss bar,
  Hero of the Village with trade discounts.

### Trial chambers (1.21)
- Trial spawners (waves, rewards, cooldown), vaults with trial keys, breeze, wind charges.
- Ominous trials with trial omen, ominous vaults and the **heavy core** for the mace.

### Combat, tools and items
- Mace with smash attack, shield blocking, crossbow (loading, multishot, piercing,
  fireworks), trident (loyalty, riptide, channeling, impaling), fishing rod with vanilla loot.
- All Bedrock enchantments, including the ones PocketMine lacks (smite, looting, lure,
  luck of the sea, depth strider, riptide, loyalty, channeling, multishot, piercing,
  quick charge, soul speed, density, breach, wind burst, curse of binding).
- New effects: slow falling, bad omen, raid omen, trial omen, hero of the village,
  wind charged, weaving, oozing, infested.
- Working anvil (repair, combine, rename) and grindstone.
- Brush and archaeology (suspicious sand and gravel), fish buckets, horse armor,
  wolf armor, powder snow bucket, wind charges, colored eggs.
- Maps: filled maps that draw while you walk, cartography table (zoom, copy, lock,
  rename), buried treasure maps and explorer maps from cartographers.

### Java Edition players (Java bridge)
- Java players can join directly: on first start BetterPMMP downloads Java 21 (if none
  is installed) and ViaProxy, runs it in the background on port 25565, restarts it when
  it crashes and stops it with the server. Java accounts are checked by Mojang, names
  get the prefix `J_`. Settings: `java-bridge` in `betterpmmp.yml`.
- Forms from plugins are shown to Java players as chat menus (type the number).

### Bees and cave blocks
- Bees fly to flowers, collect nectar, pollinate crops and fill beehives and bee nests
  with honey; shears give honeycomb, glass bottles give honey bottles; bees get angry
  when the hive is harvested without a campfire below, sting (poison) and die later.
- Powder snow (sink in, freezing damage, leather boots protect, bucket pick-up), honey
  block (slow, sticky, less fall damage), pointed dripstone (double fall damage, drips
  water and lava into cauldrons), turtle eggs (hatch on sand, can be trampled),
  scaffolding (stability, columns, unsupported pieces fall).

### Weather and world
- Vanilla weather cycle: rain, snow, thunderstorms, lightning (charged creepers,
  witches, zombified piglins), snow layers and ice, rain puts out fire, sleeping skips storms.
- `/weather` and `/toggledownfall` commands.

### Server settings
- `betterpmmp.yml`: choose in which worlds mobs spawn, switch mob categories on or off,
  mob caps, pathfinding budget, map diagnostics.
- Works next to world plugins like MultiWorld.

---

<a name="deutsch"></a>
# BetterPMMP — Vanilla-Engine für Minecraft Bedrock 1.26.60

[English](#english) · **Deutsch**

BetterPMMP ist eine Server-Software für Minecraft: Bedrock Edition 1.26.60 auf
Basis von PocketMine-MP / AxolotlMP. Das normale PocketMine-MP ist ein schlanker
Serverkern: kein Redstone, ein einfacher Weltgenerator, keine Mob-KI, keine
Strukturen, kein Dorfbewohner-Handel und viele Blöcke und Items ohne Funktion.
BetterPMMP behält das Netzwerk, die Weltspeicherung und die Plugin-API von
PocketMine und setzt eine Vanilla-Spiel-Engine obendrauf, damit man Survival von
Anfang bis Ende spielen kann.

GitHub: https://github.com/BetterPMMP/Betterpmmp

Aktuelle Version: **5.31-vanilla-beta.1** (API 5.51.0, Plugins für PocketMine-MP 5 laufen weiter).

> Stand: Beta. Viele Systeme verhalten sich wie in Vanilla, aber noch nicht jedes
> Detail stimmt mit dem offiziellen Bedrock Dedicated Server überein. Den ehrlichen
> Stand pro Funktion findest du in `VANILLA-PARITY.md`.

## Was BetterPMMP gegenüber dem normalen PocketMine-MP hinzufügt

### Weltgenerierung
- Neuer Oberwelt-Generator wie ab 1.18: Welthöhe −64 bis 319, Tiefenschiefer,
  Multi-Noise-Biome (über 50 Oberflächenbiome, üppige Höhlen, Tiefes Dunkel,
  Tropfsteinhöhlen), Berge über y 200, Flüsse, Küsten, alle 9 Ozeanarten.
- Höhlen: Käse-, Spaghetti- und Nudelhöhlen, Schluchten, Geoden, Seen, Fossilien, Lava unter y −55.
- Blassgarten (1.21.4) mit Blasseichen, hängendem Moos, Augenblüten und Knarzherzen.
- Strukturen: Dörfer (5 Biom-Stile, Straßen, alle 12 Berufsgebäude, Felder, Gehege),
  Minen, Festungen mit Endportal, Verliese, Wüsten- und Dschungeltempel, Iglus,
  Hexenhütten, Plünderer-Außenposten, Ruinenportale, Schiffswracks, Ozeanruinen,
  Ozeanmonumente, Waldanwesen, Antike Städte, Pfadruinen, vergrabene Schätze,
  **Prüfungskammern**.
- Nether-Generator mit 5 Biomen, Netherfestungen und Bastionen.
- Vanilla-End: Inselform, Obsidiansäulen, Außeninseln, Chorus, End-Städte und End-Schiffe.
- Alle Blöcke und Items der 1.26.60-Palette sind registriert.

### Dimensionen, Bosse und Spielfortschritt
- Nether und End werden automatisch angelegt; funktionierende Nether- und Endportale.
- Enderauge fliegt zur nächsten Festung; Endtransitportale; Elytren-Gleitflug.
- Enderdrachen-Kampf wie Vanilla: Kristalle, Flugphasen, Drachenatem, Bossleiste,
  Ausgangsportal, Drachenei, Endtransitportale.
- Wither: Beschwörung, Bossleiste und Angriffe.

### Redstone
- Komplette Signal-Engine: Redstone-Staub, Verstärker, Komparatoren, Fackeln, Lampen,
  TNT, Türen, Falltüren, Zauntore, Notenblöcke, Trichter.
- Kolben und Klebekolben (Bedrock-Regeln), Beobachter, Werfer und Spender, Zielblöcke.
- Schienen: Loren mit echter Schienenphysik, Antriebs-, Aktivierungs- und Sensorschienen, TNT-Lore.
- Crafter (1.21), Sculk-Sensoren und kalibrierte Sculk-Sensoren, Blitzableiter.

### Mobs und KI
- A*-Wegfindung: Mobs laufen um Hindernisse, springen auf Blöcke und schwimmen.
- Vanilla-Spawning: Kategorien, Obergrenzen, Gruppen, Spawn-Tabellen pro Biom, Lichtlevel.
- Feindliche Mobs mit Nah- und Fernkampf; Babyzombies; Zombies schlagen auf Schwer
  Türen ein; Creeper (durch Blitze geladen); Hexen werfen Tränke; Sumpfskelette und
  Eiswanderer schießen Effekt-Pfeile.
- Breeze, Knarz, Warden (durch Sculk-Kreischer gerufen), Plünderer-Patrouillen mit Hauptmann.
- Tiere mit Vermehrung, Jungtieren und Futter: Gürteltier, Ziege, Frosch und Kaulquappe,
  Schnüffler und mehr.
- Zähmen: Wölfe, Katzen, Papageien (auch auf der Schulter), Pferde, Esel, Maultiere.
- Reiten: Pferde, Schweine, Schreiter, Kamele, Boote, Loren, **Happy Ghast** (bis zu 4 Spieler).
- Leinen und Leinenknoten an Zäunen.

### Dörfer und Handel
- Dorfbewohner mit allen Berufen, Arbeitsblöcken, Stufen (Neuling bis Meister),
  Vanilla-Angeboten, Nachschub, Tagesablauf (arbeiten, Treffen an der Glocke,
  im eigenen Bett schlafen) und Vermehrung.
- Fahrender Händler mit Händler-Lamas.
- **Überfälle (Raids):** Unheilvolle Flasche, Böses Omen, Überfalls-Omen, Wellen je
  nach Schwierigkeit, Raid-Bossleiste, Held des Dorfes mit Handelsrabatt.

### Prüfungskammern (1.21)
- Prüfungs-Spawner (Wellen, Belohnung, Abklingzeit), Tresore mit Prüfungsschlüsseln,
  Breeze, Windkugeln.
- Unheilvolle Prüfungen mit Prüfungs-Omen, unheilvollen Tresoren und dem
  **schweren Kern** für den Streitkolben.

### Kampf, Werkzeuge und Items
- Streitkolben mit Smash-Angriff, Schild-Blocken, Armbrust (Laden, Mehrfachschuss,
  Durchschuss, Feuerwerk), Dreizack (Treue, Sog, Kanalisierung, Harpune), Angel mit
  Vanilla-Beute.
- Alle Bedrock-Verzauberungen, auch die, die PocketMine fehlen (Bann, Plünderung,
  Köder, Glück des Meeres, Wasserläufer, Sog, Treue, Kanalisierung, Mehrfachschuss,
  Durchschuss, Schnellladen, Seelenläufer, Dichte, Bresche, Windstoß, Fluch der Bindung).
- Neue Effekte: Sanfter Fall, Böses Omen, Überfalls-Omen, Prüfungs-Omen, Held des
  Dorfes, Windgeladen, Weben, Schleimig, Befallen.
- Funktionierender Amboss (Reparieren, Kombinieren, Umbenennen) und Schleifstein.
- Pinsel und Archäologie (seltsamer Sand und Kies), Fisch-Eimer, Pferderüstung,
  Wolfsrüstung, Pulverschnee-Eimer, Windkugeln, bunte Eier.
- Karten: Karten, die sich beim Laufen zeichnen, Kartentisch (Vergrößern, Kopieren,
  Sperren, Umbenennen), Schatzkarten und Entdeckerkarten vom Kartografen.

### Java-Edition-Spieler (Java-Bridge)
- Java-Spieler joinen direkt: Beim ersten Start lädt BetterPMMP Java 21 (falls keins
  installiert ist) und ViaProxy, startet es im Hintergrund auf Port 25565, startet es nach
  Abstürzen neu und beendet es mit dem Server. Java-Konten prüft Mojang, Namen bekommen
  das Präfix `J_`. Einstellungen: `java-bridge` in `betterpmmp.yml`.
- Formulare von Plugins bekommen Java-Spieler als Chat-Menü (Zahl schreiben).

### Bienen und Höhlenblöcke
- Bienen fliegen zu Blumen, sammeln Nektar, bestäuben Pflanzen und füllen Bienenstöcke
  und -nester mit Honig; Schere gibt Honigwaben, Glasflasche gibt Honigflaschen; ohne
  Lagerfeuer darunter werden Bienen wütend, stechen (Gift) und sterben danach.
- Pulverschnee (Einsinken, Erfrieren, Lederstiefel schützen, mit Eimer aufnehmen),
  Honigblock (langsam, klebrig, weniger Fallschaden), spitzer Tropfstein (doppelter
  Fallschaden, tropft Wasser und Lava in Kessel), Schildkröteneier (schlüpfen auf Sand,
  können zertrampelt werden), Gerüst (Stabilität, Säulen, ungestützte Teile fallen).

### Wetter und Welt
- Vanilla-Wetterzyklus: Regen, Schnee, Gewitter, Blitze (geladene Creeper, Hexen,
  Zombie-Piglins), Schneeschichten und Eis, Regen löscht Feuer, Schlafen beendet Gewitter.
- Befehle `/weather` und `/toggledownfall`.

### Server-Einstellungen
- `betterpmmp.yml`: festlegen, in welchen Welten Mobs spawnen, Mob-Kategorien an- und
  ausschalten, Mob-Obergrenzen, Rechenbudget für die Wegfindung, Karten-Diagnose.
- Läuft neben Welt-Plugins wie MultiWorld.
