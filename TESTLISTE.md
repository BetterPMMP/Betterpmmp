# BetterPMMP – Testliste

Alles hier ist eingebaut, aber noch nicht auf einem echten Server getestet. Bitte der Reihe nach
ausprobieren und bei jedem Punkt ✅ oder ❌ eintragen. Bei ❌ kurz schreiben, was passiert ist
(Screenshot, Konsolenausgabe).

Für Bedrock **und** Java testen, wo „B/J“ steht.

## Welt

- [ ] Neue Welt (oder neue Chunks) erkunden: Höhlen teilweise mit Wasser gefüllt, flache Wasserspiegel auf verschiedenen Höhen, tief unten Lava-Seen
- [ ] In gefluteten Höhlen läuft kein Wasser in trockene Gänge (keine Wasserfälle aus Wänden)
- [ ] Generieren geht genauso schnell wie vorher (kein Lag beim Fliegen)

## Redstone

- [ ] Repeater Stufe 1–4 schalten unterschiedlich schnell, kurze Impulse werden verlängert
- [ ] Repeater sperren (zweiter Repeater von der Seite) hält den Zustand
- [ ] Takter aus Fackel + Repeater läuft dauerhaft (brennt nicht durch)
- [ ] Fackel, die sich ohne Verzögerung selbst versorgt, brennt durch (Rauch) und geht nach ~8 s wieder an
- [ ] Komparator liest Truhe, Kuchen, Kessel, Komposter, Plattenspieler, Lesepult, Seelenanker, Endportalrahmen
- [ ] Komparator subtrahieren (Klick) funktioniert
- [ ] Observer gibt einen kurzen Impuls
- [ ] Regale mit Redstone: 3 Regale nebeneinander versorgen → eins benutzen tauscht die ganze Hotbar
- [ ] Sculk-Sensor + Komparator zeigt die Frequenz (Schritte 1, Block abbauen 12, setzen 13)
- [ ] Kalibrierter Sculk-Sensor mit Redstone-Signal an einer Seite reagiert nur auf diese Frequenz (welche Seite der Eingang ist, bitte notieren)

## Trichter und Werfer

- [ ] Trichter über Komposter füllt ihn, Trichter darunter holt Knochenmehl
- [ ] Trichter in Braustand: von oben Zutat, von der Seite Lohenstaub und Flaschen, unten kommen Tränke heraus
- [ ] Werfer legt Rüstung an (Spieler/Zombie davor), setzt Boot auf Wasser, Lore auf Schiene

## Bogen und Armbrust (B/J)

- [ ] Bogen schießt normal, voll gespannt mit Kritisch-Partikeln
- [ ] Getränkte Pfeile herstellen (8 Pfeile + Verweiltrank) und verschießen → Wirkung beim Getroffenen
- [ ] Unendlichkeit: normale Pfeile werden nicht verbraucht, getränkte schon
- [ ] Armbrust: laden, schießen, Mehrfachschuss, Durchschuss, Feuerwerk in der Nebenhand

## Tiere

- [ ] Esel/Maultier zähmen, Truhe draufsetzen, Schleichen + Benutzen öffnet das Inventar (B/J)
- [ ] Beim Reiten Inventar-Taste → Tier-Inventar, Sattel herausnehmen/einsetzen
- [ ] Pferd: Rüstung über das Inventar wechseln
- [ ] Lama: mit Weizen/Heu füttern, mit leerer Hand zähmen, Truhe + Teppich, Inventar
- [ ] Tier stirbt → Truhe und Inhalt fallen heraus
- [ ] Nautilus im Ozean finden, mit Kugelfisch zähmen, Sattel, unter Wasser reiten, nicht ertrinken
- [ ] Zombie-Nautilus mit Ertrunkenem im Ozean
- [ ] Wüste nachts: Kamel-Wüstenzombie mit Wüstenzombie (Speer) und Parched
- [ ] Parched brennt nicht am Tag, Pfeile geben Schwäche
- [ ] Wüstenzombie-Treffer macht hungrig
- [ ] Mob stirbt neben Sculk-Katalysator: Sculk breitet sich aus, manchmal wächst ein Sensor

## Java-Spieler (J)

- [ ] Join mit verschiedenen Versionen (z. B. 1.8.9, 1.12.2, 1.16.5, 1.20.1, neueste)
- [ ] Villager-Handel: Fenster öffnet sich, Angebote stimmen, Klick auf Angebot legt Bezahlung hinein, Shift-Klick handelt mehrfach
- [ ] Amboss, Zaubertisch, Schmiedetisch, Schleifstein, Kartentisch
- [ ] Tier-Inventar (Pferd, Esel, Lama)

## Ältere Punkte (seit Längerem eingebaut, noch nie getestet)

- [ ] Skelette schießen auf alle Entfernungen und treffen
- [ ] Schmiedetisch für Bedrock (Netherit-Aufwertung, Rüstungsbesätze)
- [ ] Knochenmehl auf Stein/Erde macht Moos, Moosteppich, Azaleen
- [ ] `/structure save` und `/structure load`
- [ ] `/function` mit einer `.mcfunction`-Datei
- [ ] `/tickingarea add` hält einen Bereich geladen
- [ ] Bewohner von Strukturen: Shulker in End-Städten, Großwächter im Monument, Hexe in der Hütte
- [ ] Hühnerjockeys (Babyzombie auf Huhn)
- [ ] Betten im Nether/End explodieren
