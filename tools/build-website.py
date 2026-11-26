#!/usr/bin/env python3
"""Builds the BetterPMMP website in docs/ (English and German in one page each). Usage: python3 tools/build-website.py docs"""
import html, os, sys

OUT = sys.argv[1]
BASE = "https://betterpmmp.github.io/Betterpmmp/"
REPO = "https://github.com/BetterPMMP/Betterpmmp"
VERSION = "5.85-vanilla-beta.1"
PHAR = "BetterPMMP-5.85.phar"

GH_ICON = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>'

DOCS = [
    ("install.html", "Installation", "Installation"),
    ("configuration.html", "Configuration", "Konfiguration"),
    ("java.html", "Java players", "Java-Spieler"),
    ("custom-content.html", "Custom content", "Eigene Inhalte"),
    ("commands.html", "Commands", "Befehle"),
    ("plugins.html", "Plugin development", "Plugins entwickeln"),
]


def code(text, lang=""):
    return '<pre><code%s>%s</code></pre>' % (f' class="lang-{lang}"' if lang else "", html.escape(text.strip("\n")))


def both(en, de, tag="div"):
    return f'<{tag} data-lang-block="en">{en}</{tag}><{tag} data-lang-block="de">{de}</{tag}>'


def t(en, de):
    return both(en, de, "span")


def page(name, title_en, title_de, desc, body, current=None):
    menu = [("index.html", "Home", "Start"), ("hub.html", "Plugins", "Plugins"), ("install.html", "Install", "Installation"), ("java.html", "Java", "Java"),
            ("custom-content.html", "Content", "Inhalte"), ("commands.html", "Commands", "Befehle"), ("plugins.html", "Develop", "Entwickeln")]
    active = "hub.html" if name.startswith("plugin-") or name == "submit.html" else name
    links = "".join(f'<a href="{h}"{" aria-current=\"page\"" if h == active else ""}>{t(en, de)}</a>' for h, en, de in menu)
    canonical = BASE + ("" if name == "index.html" else name)
    return f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{html.escape(title_en)}</title>
<meta name="title-en" content="{html.escape(title_en)}">
<meta name="title-de" content="{html.escape(title_de)}">
<meta name="description" content="{html.escape(desc)}">
<meta name="author" content="Leon Development">
<meta name="robots" content="index, follow">
<meta name="theme-color" content="#0b0f14">
<link rel="canonical" href="{canonical}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="BetterPMMP">
<meta property="og:title" content="{html.escape(title_en)}">
<meta property="og:description" content="{html.escape(desc)}">
<meta property="og:url" content="{canonical}">
<meta property="og:image" content="{BASE}banner.png">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/site.css">
{STRUCTURED if name == "index.html" else ""}
</head>
<body>
<header class="top"><div class="wrap">
  <a class="logo" href="index.html"><i></i><span>Better<b>PMMP</b></span></a>
  <nav class="menu" aria-label="Main">{links}</nav>
  <div class="lang" role="group" aria-label="Language">
    <button type="button" data-set-lang="de" title="Deutsch" aria-label="Deutsch">🇩🇪</button>
    <button type="button" data-set-lang="en" title="English" aria-label="English">🇬🇧</button>
  </div>
  <a class="gh" href="{REPO}" title="GitHub" aria-label="GitHub">{GH_ICON}</a>
</div></header>
{body}
<footer><div class="wrap cols">
  <div><b style="color:var(--text)">BetterPMMP</b> © Leon Development<br>
  {t("Licensed under the LGPL-3.0-or-later, based on PocketMine-MP.", "Lizenz LGPL-3.0-or-later, basiert auf PocketMine-MP.")}<br>
  {t("Not affiliated with Mojang Studios or Microsoft.", "Nicht verbunden mit Mojang Studios oder Microsoft.")}</div>
  <nav>
    <a href="install.html">{t("Installation", "Installation")}</a>
    <a href="hub.html">Plugins</a>
    <a href="plugins.html">{t("Plugin development", "Plugins entwickeln")}</a>
    <a href="{REPO}/releases">{t("Download", "Download")}</a>
    <a href="{REPO}">GitHub</a>
    <a href="{REPO}/issues">{t("Report a bug", "Fehler melden")}</a>
  </nav>
</div></footer>
<script src="assets/site.js"></script>
</body>
</html>
'''


STRUCTURED = '''<script type="application/ld+json">
{"@context":"https://schema.org","@type":"SoftwareApplication","name":"BetterPMMP","alternateName":["Better PMMP"],
"applicationCategory":"GameApplication","operatingSystem":"Windows, Linux, macOS","softwareVersion":"%s",
"description":"Server software for Minecraft: Bedrock Edition 1.26.60 based on PocketMine-MP 5, with a vanilla game engine and Java Edition crossplay.",
"url":"%s","downloadUrl":"%s/releases","codeRepository":"%s","license":"https://www.gnu.org/licenses/lgpl-3.0.html",
"offers":{"@type":"Offer","price":"0","priceCurrency":"EUR"},
"author":{"@type":"Organization","name":"Leon Development"},"publisher":{"@type":"Organization","name":"Leon Development"}}
</script>''' % (VERSION, BASE, REPO, REPO)


def doc_page(name, title_en, title_de, desc, en, de):
    idx = [d[0] for d in DOCS].index(name)
    side = '<p>' + t("Documentation", "Dokumentation") + '</p>' + "".join(
        f'<a href="{h}"{" aria-current=\"page\"" if h == name else ""}>{t(a, b)}</a>' for h, a, b in DOCS)
    pager = '<div class="pager">'
    if idx > 0:
        h, a, b = DOCS[idx - 1]
        pager += f'<a href="{h}"><small>{t("Previous", "Zurück")}</small>{t(a, b)}</a>'
    if idx < len(DOCS) - 1:
        h, a, b = DOCS[idx + 1]
        pager += f'<a class="next" href="{h}"><small>{t("Next", "Weiter")}</small>{t(a, b)}</a>'
    pager += '</div>'
    body = f'<main class="wrap docs"><nav class="side" aria-label="Documentation">{side}</nav><article class="doc">{both(en, de)}{pager}</article></main>'
    return page(name, title_en + " – BetterPMMP", title_de + " – BetterPMMP", desc, body)


pages = {}

# ---------------------------------------------------------------- index
INDEX_EN = f'''
<section class="hero"><div class="wrap">
 <div class="hero-grid">
  <div>
   <span class="badge">Version {VERSION}</span>
   <h1>Vanilla Minecraft.<br>On <b>PocketMine</b> speed.</h1>
   <p class="lead">BetterPMMP is server software for Minecraft: Bedrock Edition 1.26.60. It keeps the fast core and the plugin API of PocketMine-MP 5 and adds a real vanilla game engine on top: world generation, redstone, mob AI, the Nether and the End. Java Edition players can join too.</p>
   <div class="btns">
    <a class="btn main" href="{REPO}/releases">Download</a>
    <a class="btn" href="install.html">Get started</a>
    <a class="btn" href="plugins.html">Write a plugin</a>
   </div>
   <p class="by">Free and open source · developed by <b style="color:var(--text)">Leon Development</b></p>
  </div>
  <img class="shot" src="banner.png" alt="BetterPMMP banner" width="2560" height="1280">
 </div>
 <div class="stats">
  <div><b>1.26.60</b><span>Minecraft Bedrock</span></div>
  <div><b>Java + Bedrock</b><span>crossplay built in</span></div>
  <div><b>API 5.51.0</b><span>PocketMine plugins work</span></div>
  <div><b>38</b><span>vanilla game rules</span></div>
 </div>
</div></section>
'''
INDEX_DE = f'''
<section class="hero"><div class="wrap">
 <div class="hero-grid">
  <div>
   <span class="badge">Version {VERSION}</span>
   <h1>Vanilla-Minecraft.<br>Mit <b>PocketMine</b>-Tempo.</h1>
   <p class="lead">BetterPMMP ist Serversoftware für Minecraft: Bedrock Edition 1.26.60. Es behält den schnellen Kern und die Plugin-API von PocketMine-MP 5 und setzt eine echte Vanilla-Engine obendrauf: Weltgenerierung, Redstone, Mob-KI, Nether und End. Auch Java-Edition-Spieler können mitspielen.</p>
   <div class="btns">
    <a class="btn main" href="{REPO}/releases">Herunterladen</a>
    <a class="btn" href="install.html">Loslegen</a>
    <a class="btn" href="plugins.html">Plugin schreiben</a>
   </div>
   <p class="by">Kostenlos und Open Source · entwickelt von <b style="color:var(--text)">Leon Development</b></p>
  </div>
  <img class="shot" src="banner.png" alt="BetterPMMP Banner" width="2560" height="1280">
 </div>
 <div class="stats">
  <div><b>1.26.60</b><span>Minecraft Bedrock</span></div>
  <div><b>Java + Bedrock</b><span>Crossplay eingebaut</span></div>
  <div><b>API 5.51.0</b><span>PocketMine-Plugins laufen</span></div>
  <div><b>38</b><span>Vanilla-Spielregeln</span></div>
 </div>
</div></section>
'''

def features(lang):
    items = {
        "en": [
            ("🌍", "World generation", "1.18+ terrain from −64 to 319, 50+ biomes, cheese and spaghetti caves, lush caves, deep dark, villages, strongholds, ancient cities, trial chambers."),
            ("🔥", "Nether and End", "Five Nether biomes in 3D, fortresses and bastions. The End with pillars, end cities, end ships, the ender dragon and the wither."),
            ("⚡", "Redstone", "Dust, repeaters, comparators, pistons, observers, hoppers, dispensers, rails with minecart physics, crafter and sculk sensors."),
            ("🧟", "Mob AI", "A* pathfinding in worker threads, vanilla spawning, melee and ranged combat, breeding, taming, riding and villager trading."),
            ("☕", "Java crossplay", "Java Edition players join the same world through the built-in bridge. Java and the proxy download themselves."),
            ("⌨️", "Vanilla commands", "Target selectors, /execute, /scoreboard, /gamerule, /fill, /clone, /summon, /structure, /camera and more."),
            ("🎨", "Custom content", "Add items, blocks and creatures with a few lines of YAML. The resource pack is built and sent automatically."),
            ("🚀", "Performance", "World generation, chunk sending and mob pathfinding run in worker threads, so the game tick stays smooth."),
        ],
        "de": [
            ("🌍", "Weltgenerierung", "Terrain wie ab 1.18 von −64 bis 319, über 50 Biome, Käse- und Spaghetti-Höhlen, üppige Höhlen, Tiefes Dunkel, Dörfer, Festungen, antike Städte, Prüfungskammern."),
            ("🔥", "Nether und End", "Fünf Nether-Biome in 3D, Festungen und Bastionen. Das End mit Säulen, End-Städten, End-Schiffen, Enderdrache und Wither."),
            ("⚡", "Redstone", "Staub, Verstärker, Komparatoren, Kolben, Beobachter, Trichter, Spender, Schienen mit Loren-Physik, Crafter und Sculk-Sensoren."),
            ("🧟", "Mob-KI", "A*-Wegfindung in eigenen Threads, Vanilla-Spawning, Nah- und Fernkampf, Züchten, Zähmen, Reiten und Dorfbewohner-Handel."),
            ("☕", "Java-Crossplay", "Java-Edition-Spieler kommen über die eingebaute Bridge in dieselbe Welt. Java und der Proxy laden sich selbst herunter."),
            ("⌨️", "Vanilla-Befehle", "Zielauswahl, /execute, /scoreboard, /gamerule, /fill, /clone, /summon, /structure, /camera und mehr."),
            ("🎨", "Eigene Inhalte", "Items, Blöcke und Wesen mit ein paar Zeilen YAML. Das Resource-Pack wird automatisch gebaut und verschickt."),
            ("🚀", "Performance", "Weltgenerierung, Chunk-Versand und Wegfindung laufen in eigenen Threads, der Spiel-Tick bleibt flüssig."),
        ],
    }[lang]
    return '<div class="grid">' + "".join(f'<div class="card"><div class="ic">{i}</div><h3>{h}</h3><p>{p}</p></div>' for i, h, p in items) + '</div>'


COMPARE = {
    "en": [("Plugin API (PocketMine-MP 5)", "✓", "✓"), ("Vanilla world generation (1.18+)", "✓", "flat / simple"), ("Structures (villages, strongholds, cities …)", "✓", "–"),
           ("Redstone", "✓", "–"), ("Mob AI and natural spawning", "✓", "–"), ("Nether and End with bosses", "✓", "–"),
           ("Vanilla commands and game rules", "✓", "few"), ("Java Edition players", "✓ built in", "–"), ("Custom items, blocks, creatures", "✓ YAML", "plugin needed")],
    "de": [("Plugin-API (PocketMine-MP 5)", "✓", "✓"), ("Vanilla-Weltgenerierung (ab 1.18)", "✓", "flach / einfach"), ("Strukturen (Dörfer, Festungen, Städte …)", "✓", "–"),
           ("Redstone", "✓", "–"), ("Mob-KI und natürliches Spawnen", "✓", "–"), ("Nether und End mit Bossen", "✓", "–"),
           ("Vanilla-Befehle und Spielregeln", "✓", "wenige"), ("Java-Edition-Spieler", "✓ eingebaut", "–"), ("Eigene Items, Blöcke, Wesen", "✓ YAML", "Plugin nötig")],
}

def compare(lang):
    head = ("Feature", "BetterPMMP", "PocketMine-MP") if lang == "en" else ("Funktion", "BetterPMMP", "PocketMine-MP")
    rows = "".join(f'<tr><td>{a}</td><td class="y">{b}</td><td class="n">{c}</td></tr>' for a, b, c in COMPARE[lang])
    return f'<div class="scroll"><table class="table"><thead><tr><th>{head[0]}</th><th>{head[1]}</th><th>{head[2]}</th></tr></thead><tbody>{rows}</tbody></table></div>'


def quickstart(lang):
    if lang == "en":
        s = [("Get the PHP binary", 'Download the PocketMine PHP binary for your system from <a href="https://github.com/pmmp/PHP-Binaries/releases">pmmp/PHP-Binaries</a> and unpack it into an empty folder.'),
             ("Download BetterPMMP", f'Put <code>{PHAR}</code> from the <a href="{REPO}/releases">releases</a> next to the <code>bin</code> folder.'),
             ("Start and join", 'Start the phar with the PHP binary. Bedrock players join on port 19132, Java players on 25565.')]
    else:
        s = [("PHP-Binary holen", 'Die PocketMine-PHP-Binary für dein System von <a href="https://github.com/pmmp/PHP-Binaries/releases">pmmp/PHP-Binaries</a> herunterladen und in einen leeren Ordner entpacken.'),
             ("BetterPMMP herunterladen", f'<code>{PHAR}</code> aus den <a href="{REPO}/releases">Releases</a> neben den Ordner <code>bin</code> legen.'),
             ("Starten und beitreten", 'Die Phar mit der PHP-Binary starten. Bedrock-Spieler verbinden sich über Port 19132, Java-Spieler über 25565.')]
    return '<div class="steps">' + "".join(f'<div class="step"><h3>{h}</h3><p>{p}</p></div>' for h, p in s) + '</div>'


def doclinks(lang):
    desc = {
        "en": {"install.html": "PHP binary, first start, moving from PocketMine.", "configuration.html": "Every option in betterpmmp.yml explained.",
               "java.html": "Let Java Edition players join your server.", "custom-content.html": "Items, blocks and creatures from YAML files.",
               "commands.html": "Selectors, /execute, /scoreboard, game rules.", "plugins.html": "Write your first plugin and use the BetterPMMP APIs."},
        "de": {"install.html": "PHP-Binary, erster Start, Umstieg von PocketMine.", "configuration.html": "Alle Einstellungen in betterpmmp.yml erklärt.",
               "java.html": "Java-Edition-Spieler auf deinen Server lassen.", "custom-content.html": "Items, Blöcke und Wesen aus YAML-Dateien.",
               "commands.html": "Zielauswahl, /execute, /scoreboard, Spielregeln.", "plugins.html": "Dein erstes Plugin und die BetterPMMP-Schnittstellen."},
    }[lang]
    more = "Read more →" if lang == "en" else "Weiterlesen →"
    return '<div class="grid">' + "".join(f'<a class="card" href="{h}"><h3>{a if lang == "en" else b}</h3><p>{desc[h]}</p><span class="more">{more}</span></a>' for h, a, b in DOCS) + '</div>'


def index_sections(lang):
    if lang == "en":
        return f'''
<section><div class="wrap"><p class="kicker">Features</p><h2>Everything survival needs</h2><p class="sub">PocketMine-MP is a fast server core, but as a game it is mostly empty. BetterPMMP fills it with the vanilla systems players expect.</p>{features("en")}</div></section>
<section><div class="wrap"><p class="kicker">Comparison</p><h2>BetterPMMP and PocketMine-MP</h2><p class="sub">Same core, same plugins, but a game you can actually play.</p>{compare("en")}</div></section>
<section><div class="wrap"><p class="kicker">Quick start</p><h2>Running in three steps</h2><p class="sub">No compiling, no extra tools. If you have run PocketMine-MP before, you already know how.</p>{quickstart("en")}<p style="margin-top:18px"><a href="install.html">Full installation guide →</a></p></div></section>
<section><div class="wrap"><p class="kicker">Documentation</p><h2>Learn BetterPMMP</h2><p class="sub">Guides for server owners and plugin developers.</p>{doclinks("en")}</div></section>
<section><div class="wrap"><div class="card" style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;justify-content:space-between;padding:26px"><div><h2 style="margin:0 0 4px">Ready to play vanilla?</h2><p class="sub" style="margin:0">Download BetterPMMP and start your server in a few minutes.</p></div><div class="btns"><a class="btn main" href="{REPO}/releases">Download</a><a class="btn" href="{REPO}">View on GitHub</a></div></div></div></section>
'''
    return f'''
<section><div class="wrap"><p class="kicker">Funktionen</p><h2>Alles, was Survival braucht</h2><p class="sub">PocketMine-MP ist ein schneller Serverkern, aber als Spiel fast leer. BetterPMMP füllt ihn mit den Vanilla-Systemen, die Spieler erwarten.</p>{features("de")}</div></section>
<section><div class="wrap"><p class="kicker">Vergleich</p><h2>BetterPMMP und PocketMine-MP</h2><p class="sub">Gleicher Kern, gleiche Plugins, aber ein Spiel, das man wirklich spielen kann.</p>{compare("de")}</div></section>
<section><div class="wrap"><p class="kicker">Schnellstart</p><h2>In drei Schritten startklar</h2><p class="sub">Nichts kompilieren, keine Zusatz-Tools. Wer PocketMine-MP schon einmal gestartet hat, kennt den Ablauf.</p>{quickstart("de")}<p style="margin-top:18px"><a href="install.html">Zur ausführlichen Anleitung →</a></p></div></section>
<section><div class="wrap"><p class="kicker">Dokumentation</p><h2>BetterPMMP kennenlernen</h2><p class="sub">Anleitungen für Serverbetreiber und Plugin-Entwickler.</p>{doclinks("de")}</div></section>
<section><div class="wrap"><div class="card" style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;justify-content:space-between;padding:26px"><div><h2 style="margin:0 0 4px">Bereit für Vanilla?</h2><p class="sub" style="margin:0">BetterPMMP herunterladen und den Server in wenigen Minuten starten.</p></div><div class="btns"><a class="btn main" href="{REPO}/releases">Herunterladen</a><a class="btn" href="{REPO}">Auf GitHub ansehen</a></div></div></div></section>
'''

pages["index.html"] = page("index.html",
    "BetterPMMP – Vanilla Minecraft Bedrock Server Software by Leon Development",
    "BetterPMMP – Vanilla-Serversoftware für Minecraft Bedrock von Leon Development",
    "BetterPMMP by Leon Development: free server software for Minecraft Bedrock Edition 1.26.60 based on PocketMine-MP 5, with vanilla world generation, redstone, mob AI, the Nether and End, and Java Edition players through a built-in bridge.",
    both(INDEX_EN + index_sections("en"), INDEX_DE + index_sections("de")))

# ---------------------------------------------------------------- install
START_LINUX = f"./bin/php7/bin/php {PHAR}"
START_WIN = f"bin\\php\\php.exe {PHAR}"
pages["install.html"] = doc_page("install.html", "Installation", "Installation",
    "How to install and start BetterPMMP, the vanilla server software for Minecraft Bedrock Edition.",
f'''<h1>Installation</h1>
<p class="intro">BetterPMMP runs wherever PocketMine-MP runs: 64-bit Windows, Linux and macOS. You need the PocketMine PHP binary and the BetterPMMP phar, nothing else.</p>
<h2>1. Get the PHP binary</h2>
<p>BetterPMMP uses the same PHP build as PocketMine-MP, with the pmmp extensions compiled in. Download the archive for your system from <a href="https://github.com/pmmp/PHP-Binaries/releases">pmmp/PHP-Binaries</a> and unpack it into an empty folder. You should get a folder called <code>bin</code>.</p>
<h2>2. Download BetterPMMP</h2>
<p>Download <code>{PHAR}</code> from the <a href="{REPO}/releases">latest release</a> and put it next to the <code>bin</code> folder:</p>
{code(f"my-server/\n├── bin/\n└── {PHAR}")}
<h2>3. Start the server</h2>
<p>Linux and macOS:</p>
{code(START_LINUX, "bash")}
<p>Windows (Command Prompt in the server folder):</p>
{code(START_WIN)}
<p>On the first start a setup wizard asks for the language, the server name and the port. Then the server creates <code>server.properties</code>, <code>pocketmine.yml</code>, <code>betterpmmp.yml</code> and the folders <code>worlds</code> and <code>plugins</code>.</p>
<h2>4. Join</h2>
<ul>
<li><b>Bedrock Edition:</b> your IP or domain, port <b>19132</b> (UDP).</li>
<li><b>Java Edition:</b> your IP or domain, port <b>25565</b> (TCP). See <a href="java.html">Java players</a>.</li>
</ul>
<p>On a home connection, forward these ports in your router. On a VPS, open them in the firewall.</p>
<h2>Moving from PocketMine-MP</h2>
<ol>
<li>Stop the server and back up the <code>worlds</code> folder.</li>
<li>Replace the PocketMine phar with <code>{PHAR}</code>.</li>
<li>Start the server. Plugins and worlds stay where they are.</li>
</ol>
<div class="note"><p>For the new terrain, biomes and structures, create a new world. Java (Anvil) worlds are converted the first time they are loaded.</p></div>
<h2>Running from source</h2>
{code("git clone https://github.com/BetterPMMP/Betterpmmp.git\ncd Betterpmmp\nphp src/PocketMine.php", "bash")}
<p>Use the PocketMine PHP binary for <code>php</code>. The <code>vendor</code> folder is part of the repository, you do not need Composer.</p>
''',
f'''<h1>Installation</h1>
<p class="intro">BetterPMMP läuft überall, wo PocketMine-MP läuft: 64-Bit-Windows, Linux und macOS. Du brauchst nur die PocketMine-PHP-Binary und die BetterPMMP-Phar.</p>
<h2>1. PHP-Binary holen</h2>
<p>BetterPMMP nutzt dasselbe PHP wie PocketMine-MP, mit den pmmp-Erweiterungen. Lade das Archiv für dein System von <a href="https://github.com/pmmp/PHP-Binaries/releases">pmmp/PHP-Binaries</a> herunter und entpacke es in einen leeren Ordner. Danach gibt es dort einen Ordner <code>bin</code>.</p>
<h2>2. BetterPMMP herunterladen</h2>
<p>Lade <code>{PHAR}</code> aus dem <a href="{REPO}/releases">neuesten Release</a> herunter und lege sie neben den Ordner <code>bin</code>:</p>
{code(f"mein-server/\n├── bin/\n└── {PHAR}")}
<h2>3. Server starten</h2>
<p>Linux und macOS:</p>
{code(START_LINUX, "bash")}
<p>Windows (Eingabeaufforderung im Serverordner):</p>
{code(START_WIN)}
<p>Beim ersten Start fragt ein Einrichtungsassistent nach Sprache, Servername und Port. Danach legt der Server <code>server.properties</code>, <code>pocketmine.yml</code>, <code>betterpmmp.yml</code> und die Ordner <code>worlds</code> und <code>plugins</code> an.</p>
<h2>4. Beitreten</h2>
<ul>
<li><b>Bedrock Edition:</b> deine IP oder Domain, Port <b>19132</b> (UDP).</li>
<li><b>Java Edition:</b> deine IP oder Domain, Port <b>25565</b> (TCP). Siehe <a href="java.html">Java-Spieler</a>.</li>
</ul>
<p>Zu Hause gibst du die Ports im Router frei, auf einem VPS in der Firewall.</p>
<h2>Umstieg von PocketMine-MP</h2>
<ol>
<li>Server stoppen und den Ordner <code>worlds</code> sichern.</li>
<li>Die PocketMine-Phar durch <code>{PHAR}</code> ersetzen.</li>
<li>Server starten. Plugins und Welten bleiben, wo sie sind.</li>
</ol>
<div class="note"><p>Für das neue Terrain, die Biome und Strukturen eine neue Welt anlegen. Java-Welten (Anvil) werden beim ersten Laden umgewandelt.</p></div>
<h2>Aus dem Quellcode starten</h2>
{code("git clone https://github.com/BetterPMMP/Betterpmmp.git\ncd Betterpmmp\nphp src/PocketMine.php", "bash")}
<p>Als <code>php</code> die PocketMine-PHP-Binary verwenden. Der Ordner <code>vendor</code> ist im Repository enthalten, Composer brauchst du nicht.</p>
''')

# ---------------------------------------------------------------- configuration
CFG = '''graphics:
  vibrant-visuals: true        # allow Vibrant Visuals for Bedrock players

camera:
  presets: true                # camera presets for /camera set

mob-spawning:
  worlds: ["default"]          # "*" = all worlds, "default" = main world
  disabled-worlds: []          # never spawn here, e.g. ["lobby"]
  monsters: true
  animals: true
  max-monsters-per-player: 70

mob-ai:
  pathfinding-ms-per-tick: 6   # main-thread budget for pathfinding
  async-pathfinding: true      # pathfinding in worker threads

hand-swap:
  double-sneak: true           # sneak twice to swap hands'''
CFG_DE = '''graphics:
  vibrant-visuals: true        # Vibrant Visuals für Bedrock-Spieler erlauben

camera:
  presets: true                # Kamera-Voreinstellungen für /camera set

mob-spawning:
  worlds: ["default"]          # "*" = alle Welten, "default" = Hauptwelt
  disabled-worlds: []          # hier nie, z. B. ["lobby"]
  monsters: true
  animals: true
  max-monsters-per-player: 70

mob-ai:
  pathfinding-ms-per-tick: 6   # Zeitbudget im Hauptthread für Wegfindung
  async-pathfinding: true      # Wegfindung in eigenen Threads

hand-swap:
  double-sneak: true           # zweimal schleichen tauscht die Hände'''
pages["configuration.html"] = doc_page("configuration.html", "Configuration", "Konfiguration",
    "The BetterPMMP configuration file betterpmmp.yml explained.",
f'''<h1>Configuration</h1>
<p class="intro">Everything BetterPMMP adds is set in <code>betterpmmp.yml</code> in the server folder. The file is created on the first start and explains every option in its comments. Changes apply after a restart.</p>
<h2>The most important options</h2>
{code(CFG, "yaml")}
<h2>Mob spawning</h2>
<p>By default mobs spawn naturally only in the main world, so lobbies and minigame worlds stay empty. Nether and End worlds always behave like vanilla. Every mob category can be switched off on its own.</p>
<h2>Game rules</h2>
<p>Game rules are not in the config file. Set them in game with <code>/gamerule</code>, per world, like on the official server. They are saved in the world's <code>level.dat</code> under the vanilla names, so a world keeps its rules when you move it.</p>
{code("/gamerule keepInventory true\n/gamerule randomTickSpeed 3\n/gamerule playersSleepingPercentage 50")}
<h2>Java bridge</h2>
<p>The <code>java-bridge</code> section is explained on the <a href="java.html">Java players</a> page.</p>
''',
f'''<h1>Konfiguration</h1>
<p class="intro">Alles, was BetterPMMP hinzufügt, stellst du in <code>betterpmmp.yml</code> im Serverordner ein. Die Datei wird beim ersten Start angelegt und erklärt jede Einstellung in Kommentaren. Änderungen gelten nach einem Neustart.</p>
<h2>Die wichtigsten Einstellungen</h2>
{code(CFG_DE, "yaml")}
<h2>Mob-Spawning</h2>
<p>Standardmäßig spawnen Mobs nur in der Hauptwelt natürlich, Lobbys und Minispiel-Welten bleiben leer. Nether- und End-Welten verhalten sich immer wie Vanilla. Jede Mob-Gruppe lässt sich einzeln abschalten.</p>
<h2>Spielregeln</h2>
<p>Spielregeln stehen nicht in der Config. Du setzt sie im Spiel mit <code>/gamerule</code>, pro Welt, wie auf dem offiziellen Server. Sie werden unter den Vanilla-Namen in der <code>level.dat</code> der Welt gespeichert und bleiben erhalten, wenn du die Welt verschiebst.</p>
{code("/gamerule keepInventory true\n/gamerule randomTickSpeed 3\n/gamerule playersSleepingPercentage 50")}
<h2>Java-Bridge</h2>
<p>Den Abschnitt <code>java-bridge</code> erklärt die Seite <a href="java.html">Java-Spieler</a>.</p>
''')

# ---------------------------------------------------------------- java
JB = '''java-bridge:
  enabled: true              # false = no Java players
  port: 25565                # Java port (TCP)
  auto-download: true        # download Java and ViaProxy automatically
  online-mode: true          # only bought Java accounts
  name-prefix: "J_"          # Java players appear as J_Steve
  memory: "512M"             # memory for ViaProxy
  resource-pack-port: 25566  # resource packs for Java, 0 = off
  public-address: ""         # empty = detect the public IP
  chat-forms: false          # true = forms as a chat menu
  real-light: true           # send real light (good for shaders)'''
JB_DE = '''java-bridge:
  enabled: true              # false = keine Java-Spieler
  port: 25565                # Java-Port (TCP)
  auto-download: true        # Java und ViaProxy automatisch laden
  online-mode: true          # nur gekaufte Java-Konten
  name-prefix: "J_"          # Java-Spieler heißen z. B. J_Steve
  memory: "512M"             # Arbeitsspeicher für ViaProxy
  resource-pack-port: 25566  # Resource-Packs für Java, 0 = aus
  public-address: ""         # leer = öffentliche IP automatisch
  chat-forms: false          # true = Formulare als Chat-Menü
  real-light: true           # echtes Licht schicken (gut für Shader)'''
pages["java.html"] = doc_page("java.html", "Java players", "Java-Spieler",
    "Let Minecraft Java Edition players join your BetterPMMP Bedrock server through the built-in bridge.",
f'''<h1>Java players</h1>
<p class="intro">BetterPMMP has a built-in bridge for Minecraft: Java Edition, based on <a href="https://github.com/ViaVersion/ViaProxy">ViaProxy</a> and ViaBedrock. Java and Bedrock players play on the same world at the same time.</p>
<h2>How it works</h2>
<ol>
<li>On the first start BetterPMMP downloads Java 21 (if none is installed) and ViaProxy into the folder <code>java-bridge</code>.</li>
<li>ViaProxy starts together with the server, restarts after a crash and stops with the server.</li>
<li>Java players connect to your address on port <b>25565</b>. The bridge translates between Java and Bedrock.</li>
</ol>
<h2>Ports</h2>
<ul>
<li><b>25565 TCP</b>: the game.</li>
<li><b>25566 TCP</b>: resource packs for Java players (textures of custom content and the server's packs).</li>
</ul>
<h2>Settings</h2>
{code(JB, "yaml")}
<h2>What Java players can do</h2>
<p>Building, fighting, crafting, furnaces, brewing stands, hoppers, chests, dispensers and droppers, minecarts and boats with chests, the smithing table, fireworks and dyed armour. Names above mobs and holograms are shown, forms open as windows or as a chat menu.</p>
<div class="note warn"><p>Not yet available for Java players: anvil, enchanting table, grindstone, stonecutter, loom, cartography table, crafter, beacon and villager trading.</p></div>
''',
f'''<h1>Java-Spieler</h1>
<p class="intro">BetterPMMP hat eine eingebaute Bridge für Minecraft: Java Edition, auf Basis von <a href="https://github.com/ViaVersion/ViaProxy">ViaProxy</a> und ViaBedrock. Java- und Bedrock-Spieler spielen gleichzeitig in derselben Welt.</p>
<h2>So funktioniert es</h2>
<ol>
<li>Beim ersten Start lädt BetterPMMP Java 21 (falls keins installiert ist) und ViaProxy in den Ordner <code>java-bridge</code>.</li>
<li>ViaProxy startet mit dem Server, startet nach einem Absturz neu und stoppt mit dem Server.</li>
<li>Java-Spieler verbinden sich mit deiner Adresse auf Port <b>25565</b>. Die Bridge übersetzt zwischen Java und Bedrock.</li>
</ol>
<h2>Ports</h2>
<ul>
<li><b>25565 TCP</b>: das Spiel.</li>
<li><b>25566 TCP</b>: Resource-Packs für Java-Spieler (Texturen eigener Inhalte und die Packs des Servers).</li>
</ul>
<h2>Einstellungen</h2>
{code(JB_DE, "yaml")}
<h2>Was Java-Spieler können</h2>
<p>Bauen, Kämpfen, Craften, Öfen, Braustände, Trichter, Truhen, Spender und Werfer, Loren und Boote mit Truhe, der Schmiedetisch, Feuerwerk und gefärbte Rüstung. Namen über Mobs und Hologramme werden angezeigt, Formulare öffnen sich als Fenster oder als Chat-Menü.</p>
<div class="note warn"><p>Für Java-Spieler noch nicht verfügbar: Amboss, Zaubertisch, Schleifstein, Steinsäge, Webstuhl, Kartentisch, Crafter, Leuchtfeuer und Handel mit Dorfbewohnern.</p></div>
''')

# ---------------------------------------------------------------- custom content
ITEMS = '''items:
  myserver:ruby:
    name: "Ruby"
    texture: ruby.png
  myserver:ruby_sword:
    name: "Ruby Sword"
    tool:
      type: sword
      tier: diamond
      attack: 8
      durability: 2000
  myserver:golden_bread:
    name: "Golden Bread"
    food:
      nutrition: 8
      saturation: 12.8
      can-always-eat: true'''
BLOCKS = '''blocks:
  myserver:ruby_block:
    name: "Ruby Block"
    texture: ruby_block.png
    hardness: 5
    tool: pickaxe
  myserver:crystal_lamp:
    name: "Crystal Lamp"
    texture: crystal_lamp.png
    light: 15'''
ENTS = '''entities:
  myserver:goblin:
    name: "Goblin"
    behaviour: hostile        # or passive
    texture: goblin.png
    model: goblin.geo.json    # Blockbench model (optional)
    health: 24
    damage: 4
    drops:
      myserver:ruby: [0, 2]
    spawn:
      biomes: [plains, forest]
      group: [1, 3]
      max-nearby: 4
      chance: 0.3
      time: night'''
pages["custom-content.html"] = doc_page("custom-content.html", "Custom content", "Eigene Inhalte",
    "Add custom items, blocks and creatures to BetterPMMP with YAML files, without a plugin.",
f'''<h1>Custom content</h1>
<p class="intro">Add your own items, blocks and creatures without writing a plugin. Describe them in a YAML file, put the textures next to it and restart. BetterPMMP builds a resource pack and sends it to every player automatically.</p>
<div class="scroll"><table class="table"><thead><tr><th>File</th><th>Textures and models</th><th>What you can add</th></tr></thead><tbody>
<tr><td><code>custom_items.yml</code></td><td><code>custom_items/</code></td><td>items, food, tools, armour</td></tr>
<tr><td><code>custom_blocks.yml</code></td><td><code>custom_blocks/</code></td><td>full blocks, one texture or top/side/bottom, light, hardness</td></tr>
<tr><td><code>custom_entities.yml</code></td><td><code>custom_entities/</code></td><td>creatures with Blockbench models, animations and natural spawning</td></tr>
</tbody></table></div>
<p>All three files are created with commented examples on the first start.</p>
<h2>Items</h2>
{code(ITEMS, "yaml")}
<p>Textures are square PNG files, for example 16 × 16 pixels. Give an item with <code>/give @s myserver:ruby_sword</code>.</p>
<h2>Blocks</h2>
{code(BLOCKS, "yaml")}
<p>Use <code>texture-top</code>, <code>texture-bottom</code> and <code>texture-side</code> for different sides. Place a block with <code>/setblock ~ ~ ~ myserver:ruby_block</code>.</p>
<h2>Creatures</h2>
{code(ENTS, "yaml")}
<p>Make the model in <a href="https://www.blockbench.net/">Blockbench</a> as a "Bedrock Entity" and export it as <code>.geo.json</code>. Without a model the creature is a box of its size with the skin wrapped around it. Summon one with <code>/summon myserver:goblin</code>.</p>
<div class="note warn"><p>Never rename or remove a block or creature that already exists in a world. Blocks would turn into unknown blocks, creatures would be deleted.</p></div>
''',
f'''<h1>Eigene Inhalte</h1>
<p class="intro">Füge eigene Items, Blöcke und Wesen hinzu, ganz ohne Plugin. Beschreibe sie in einer YAML-Datei, lege die Texturen daneben und starte neu. BetterPMMP baut daraus ein Resource-Pack und schickt es automatisch an alle Spieler.</p>
<div class="scroll"><table class="table"><thead><tr><th>Datei</th><th>Texturen und Modelle</th><th>Was du hinzufügen kannst</th></tr></thead><tbody>
<tr><td><code>custom_items.yml</code></td><td><code>custom_items/</code></td><td>Items, Essen, Werkzeuge, Rüstung</td></tr>
<tr><td><code>custom_blocks.yml</code></td><td><code>custom_blocks/</code></td><td>volle Blöcke, eine Textur oder oben/seitlich/unten, Licht, Härte</td></tr>
<tr><td><code>custom_entities.yml</code></td><td><code>custom_entities/</code></td><td>Wesen mit Blockbench-Modell, Animationen und natürlichem Spawnen</td></tr>
</tbody></table></div>
<p>Alle drei Dateien werden beim ersten Start mit kommentierten Beispielen angelegt.</p>
<h2>Items</h2>
{code(ITEMS, "yaml")}
<p>Texturen sind quadratische PNG-Dateien, zum Beispiel 16 × 16 Pixel. Ein Item gibst du mit <code>/give @s myserver:ruby_sword</code>.</p>
<h2>Blöcke</h2>
{code(BLOCKS, "yaml")}
<p>Für verschiedene Seiten gibt es <code>texture-top</code>, <code>texture-bottom</code> und <code>texture-side</code>. Setzen mit <code>/setblock ~ ~ ~ myserver:ruby_block</code>.</p>
<h2>Wesen</h2>
{code(ENTS, "yaml")}
<p>Das Modell baust du in <a href="https://www.blockbench.net/">Blockbench</a> als „Bedrock Entity“ und exportierst es als <code>.geo.json</code>. Ohne Modell ist das Wesen ein Kasten in seiner Größe mit dem Skin außen herum. Beschwören mit <code>/summon myserver:goblin</code>.</p>
<div class="note warn"><p>Benenne nie einen Block oder ein Wesen um, das schon in einer Welt vorkommt, und entferne es nicht. Blöcke würden zu unbekannten Blöcken, Wesen würden gelöscht.</p></div>
''')

# ---------------------------------------------------------------- commands
CMDS = [
    ("/execute", "as, at, positioned, anchored, facing, rotated, in, align, if/unless entity, block, score, run", "as, at, positioned, anchored, facing, rotated, in, align, if/unless entity, block, score, run"),
    ("/scoreboard", "objectives, players, sidebar, list and below-name displays", "Ziele, Spieler, Anzeige in Sidebar, Liste und unter dem Namen"),
    ("/gamerule", "all 38 Bedrock game rules, per world", "alle 38 Bedrock-Spielregeln, pro Welt"),
    ("/setblock /fill /clone", "edit the world: replace, hollow, outline, keep, destroy; clone with masked and move", "Welt bearbeiten: replace, hollow, outline, keep, destroy; clone mit masked und move"),
    ("/summon", "every vanilla and custom creature", "alle Vanilla- und eigenen Wesen"),
    ("/tellraw /title", "JSON text with scores and selectors", "JSON-Text mit Punkten und Zielauswahl"),
    ("/function /schedule", ".mcfunction files, delayed functions", ".mcfunction-Dateien, verzögerte Funktionen"),
    ("/structure", "save and load .mcstructure files, rotate and mirror", ".mcstructure speichern und laden, drehen und spiegeln"),
    ("/camera /fog /hud", "camera presets and fades, fog, hide HUD elements", "Kamera-Presets und Blenden, Nebel, HUD-Elemente ausblenden"),
    ("/tag /damage /ride", "entity tags, damage with cause, mount mobs", "Wesen-Tags, Schaden mit Ursache, Mobs reiten"),
    ("/locate /spreadplayers", "find structures, spread players", "Strukturen finden, Spieler verteilen"),
    ("/playsound /stopsound", "vanilla sounds", "Vanilla-Sounds"),
    ("/tickingarea /daylock", "keep areas loaded, stop the day cycle", "Bereiche geladen halten, Tageszeit anhalten"),
]
def cmdtable(lang):
    h = ("Command", "What it does") if lang == "en" else ("Befehl", "Was er macht")
    rows = "".join(f'<tr><td><code>{c}</code></td><td>{e if lang == "en" else d}</td></tr>' for c, e, d in CMDS)
    return f'<div class="scroll"><table class="table"><thead><tr><th>{h[0]}</th><th>{h[1]}</th></tr></thead><tbody>{rows}</tbody></table></div>'
SEL = '''@a          all players
@p          nearest player
@r          random player
@s          the one running the command
@e          all entities
@n          nearest entity

@e[type=zombie,r=10]          zombies within 10 blocks
@a[m=survival,tag=red]        survival players with the tag "red"
@p[x=0,y=64,z=0,rm=5,r=20]    nearest player 5 to 20 blocks from 0 64 0'''
SEL_DE = '''@a          alle Spieler
@p          nächster Spieler
@r          zufälliger Spieler
@s          wer den Befehl ausführt
@e          alle Wesen
@n          nächstes Wesen

@e[type=zombie,r=10]          Zombies im Umkreis von 10 Blöcken
@a[m=survival,tag=rot]        Survival-Spieler mit dem Tag "rot"
@p[x=0,y=64,z=0,rm=5,r=20]    nächster Spieler 5 bis 20 Blöcke von 0 64 0'''
EXE = '''/execute as @a at @s if block ~ ~-1 ~ gold_block run effect @s speed 2 1
/scoreboard objectives add kills dummy "Kills"
/scoreboard objectives setdisplay sidebar kills
/tellraw @a {"rawtext":[{"text":"Top: "},{"score":{"name":"@p","objective":"kills"}}]}'''
pages["commands.html"] = doc_page("commands.html", "Commands", "Befehle",
    "Vanilla commands, target selectors and game rules in BetterPMMP.",
f'''<h1>Commands</h1>
<p class="intro">BetterPMMP adds the vanilla Bedrock commands that PocketMine-MP is missing, with target selectors that also work in plugin commands.</p>
<h2>Target selectors</h2>
{code(SEL)}
<p>Filters: <code>x y z r rm dx dy dz c type name tag m l lm</code>, negation with <code>!</code>. When a command gets <code>@a</code>, it runs once per player, so plugin commands like <code>/money @a 100</code> work too.</p>
<h2>Vanilla commands</h2>
{cmdtable("en")}
<h2>Examples</h2>
{code(EXE)}
<h2>Game rules</h2>
<p><code>/gamerule</code> knows all 38 Bedrock rules, for example <code>keepInventory</code>, <code>doDaylightCycle</code>, <code>doMobSpawning</code>, <code>mobGriefing</code>, <code>randomTickSpeed</code>, <code>playersSleepingPercentage</code>, <code>pvp</code>, <code>showCoordinates</code>.</p>
''',
f'''<h1>Befehle</h1>
<p class="intro">BetterPMMP bringt die Vanilla-Befehle von Bedrock mit, die PocketMine-MP fehlen, dazu eine Zielauswahl, die auch in Plugin-Befehlen funktioniert.</p>
<h2>Zielauswahl</h2>
{code(SEL_DE)}
<p>Filter: <code>x y z r rm dx dy dz c type name tag m l lm</code>, Verneinung mit <code>!</code>. Bekommt ein Befehl <code>@a</code>, läuft er einmal pro Spieler, deshalb funktionieren auch Plugin-Befehle wie <code>/money @a 100</code>.</p>
<h2>Vanilla-Befehle</h2>
{cmdtable("de")}
<h2>Beispiele</h2>
{code(EXE)}
<h2>Spielregeln</h2>
<p><code>/gamerule</code> kennt alle 38 Bedrock-Regeln, zum Beispiel <code>keepInventory</code>, <code>doDaylightCycle</code>, <code>doMobSpawning</code>, <code>mobGriefing</code>, <code>randomTickSpeed</code>, <code>playersSleepingPercentage</code>, <code>pvp</code>, <code>showCoordinates</code>.</p>
''')

# ---------------------------------------------------------------- plugins
PLUGIN_TREE = '''HelloWorld/
├── plugin.yml
└── src/
    └── Main.php'''
PLUGIN_YML = '''name: HelloWorld
main: leondev\\helloworld\\Main
src-namespace-prefix: leondev\\helloworld
version: 1.0.0
api: 5.0.0
author: YourName
description: My first BetterPMMP plugin

commands:
  hello:
    description: Says hello
    usage: "/hello [player]"
    permission: helloworld.command.hello

permissions:
  helloworld.command.hello:
    default: true'''
MAIN_PHP = '''<?php

declare(strict_types=1);

namespace leondev\\helloworld;

use pocketmine\\command\\Command;
use pocketmine\\command\\CommandSender;
use pocketmine\\event\\Listener;
use pocketmine\\event\\player\\PlayerJoinEvent;
use pocketmine\\plugin\\PluginBase;
use pocketmine\\utils\\TextFormat;

final class Main extends PluginBase implements Listener{

	protected function onEnable() : void{
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getLogger()->info("Running on " . $this->getServer()->getName());
	}

	public function onJoin(PlayerJoinEvent $event) : void{
		$event->getPlayer()->sendMessage(TextFormat::GREEN . "Welcome to the server!");
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if($command->getName() === "hello"){
			$sender->sendMessage("Hello, " . ($args[0] ?? $sender->getName()) . "!");
			return true;
		}
		return false;
	}
}'''
DETECT = '''use pocketmine\\VersionInfo;

$isBetterPMMP = $this->getServer()->getName() === "BetterPMMP";
if($isBetterPMMP){
	$this->getLogger()->info("BetterPMMP " . VersionInfo::BETTERPMMP_BUILD);
}'''
GAMERULES = '''use pocketmine\\world\\GameRules;

$world = $this->getServer()->getWorldManager()->getDefaultWorld();

// read (keys are the lowercase vanilla names)
if(GameRules::bool($world, "keepinventory")){
	// ...
}
$speed = GameRules::int($world, "randomtickspeed");

// write: saved in level.dat and sent to the players of the world
GameRules::set($world, "dodaylightcycle", false);
GameRules::set($world, "playerssleepingpercentage", 50);'''
SCORE = '''use pocketmine\\scoreboard\\Scoreboard;

$board = Scoreboard::get();
if(!$board->hasObjective("kills")){
	$board->addObjective("kills", "dummy", "§cKills");
	$board->setDisplay("sidebar", "kills", 1); // 1 = highest first, 0 = lowest first
}

$name = $player->getName();
$board->setScore("kills", $name, ($board->getScore("kills", $name) ?? 0) + 1);'''
SELECTOR = '''use pocketmine\\command\\utils\\TargetSelector;
use pocketmine\\player\\Player;

// in onCommand(): accepts a player name or a selector like @a[r=10]
foreach(TargetSelector::select($sender, $args[0] ?? "@s") as $entity){
	if($entity instanceof Player){
		$entity->sendMessage("You were selected!");
	}
}'''
STRUCT = '''use pocketmine\\world\\structure\\McStructure;
use pocketmine\\math\\Vector3;

// load a .mcstructure file and place it, rotated by 90 degrees
$structure = McStructure::read(file_get_contents($this->getDataFolder() . "house.mcstructure"));
$structure->place($world, new Vector3(100, 64, 100), 1, "none", true, true, 1.0, 0);

// save an area as .mcstructure
$data = McStructure::capture($world, new Vector3(0, 60, 0), new Vector3(15, 80, 15), true, true)
	->write(new Vector3(0, 60, 0));
file_put_contents($this->getDataFolder() . "area.mcstructure", $data);'''
EVENTS = '''use pocketmine\\event\\entity\\EntityDamageByEntityEvent;
use pocketmine\\event\\entity\\EntityDeathEvent;
use pocketmine\\entity\\Living;
use pocketmine\\player\\Player;

public function onDamage(EntityDamageByEntityEvent $event) : void{
	// BetterPMMP mobs are normal PocketMine entities
	if($event->getDamager() instanceof Living && $event->getEntity() instanceof Player){
		$event->setBaseDamage($event->getBaseDamage() * 0.5); // mobs hit half as hard
	}
}

public function onDeath(EntityDeathEvent $event) : void{
	$this->getLogger()->info($event->getEntity()->getName() . " died");
}'''
def plugins(lang):
    if lang == "en":
        return f'''<h1>Plugin development</h1>
<p class="intro">BetterPMMP keeps the plugin API of PocketMine-MP 5 (API 5.51.0). Every plugin written for PocketMine-MP 5 runs on BetterPMMP, and everything you learn here also works there. On top of that, BetterPMMP gives plugins access to game rules, scoreboards, target selectors and structures.</p>
<h2>What you need</h2>
<ul>
<li>A BetterPMMP server for testing (see <a href="install.html">Installation</a>).</li>
<li>An editor with PHP support, for example <a href="https://code.visualstudio.com/">VS Code</a> with the PHP Intelephense extension or PhpStorm.</li>
<li>The <a href="https://poggit.pmmp.io/p/DevTools">DevTools</a> plugin, so the server loads plugins from folders while you develop.</li>
</ul>
<h2>Your first plugin</h2>
<p>A plugin is a folder with a <code>plugin.yml</code> and PHP classes. Put it into the <code>plugins</code> folder of your server:</p>
{code(PLUGIN_TREE)}
<h3>plugin.yml</h3>
{code(PLUGIN_YML, "yaml")}
<p><code>api: 5.0.0</code> means: works with every PocketMine-MP 5 API version, so also with BetterPMMP. With <code>src-namespace-prefix</code> the classes sit directly in <code>src/</code>.</p>
<h3>src/Main.php</h3>
{code(MAIN_PHP, "php")}
<p>Restart the server, join and type <code>/hello</code>. To share the plugin, build a <code>.phar</code> with DevTools (<code>/makeplugin HelloWorld</code>) and give others the file from <code>plugin_data/DevTools</code>.</p>
<h2>Events</h2>
<p>Mobs, items and blocks of BetterPMMP use the normal PocketMine classes, so the usual events work for them: damage, death, block break and place, interact, item use and so on.</p>
{code(EVENTS, "php")}
<h2>BetterPMMP APIs</h2>
<p>These classes only exist on BetterPMMP. If your plugin should also run on plain PocketMine-MP, check first:</p>
{code(DETECT, "php")}
<h3>Game rules</h3>
<p><code>pocketmine\\world\\GameRules</code> reads and changes the 38 Bedrock game rules of a world, exactly like <code>/gamerule</code>.</p>
{code(GAMERULES, "php")}
<h3>Scoreboard</h3>
<p><code>pocketmine\\scoreboard\\Scoreboard</code> is the same scoreboard that <code>/scoreboard</code> uses. It is saved automatically and shown to all players. Display slots are <code>sidebar</code>, <code>list</code> and <code>belowname</code>.</p>
{code(SCORE, "php")}
<h3>Target selectors</h3>
<p><code>pocketmine\\command\\utils\\TargetSelector</code> resolves <code>@a</code>, <code>@p</code>, <code>@e[type=zombie,r=10]</code> and player names to entities.</p>
{code(SELECTOR, "php")}
<h3>Structures</h3>
<p><code>pocketmine\\world\\structure\\McStructure</code> reads, writes and places <code>.mcstructure</code> files, the format of the structure block and of Bedrock add-ons.</p>
{code(STRUCT, "php")}
<h2>Learn more</h2>
<ul>
<li><a href="https://doc.pmmp.io/">PocketMine-MP documentation</a>: plugin basics, events, forms, tasks.</li>
<li><a href="https://github.com/pmmp/PocketMine-MP/tree/stable/src">PocketMine-MP source</a>: the API itself.</li>
<li><a href="{REPO}">BetterPMMP source</a>: the classes shown above live in <code>src/</code>.</li>
</ul>
<div class="note"><p>Questions or ideas for new APIs? Open an <a href="{REPO}/issues">issue on GitHub</a>.</p></div>
'''
    return f'''<h1>Plugins entwickeln</h1>
<p class="intro">BetterPMMP behält die Plugin-API von PocketMine-MP 5 (API 5.51.0). Jedes Plugin für PocketMine-MP 5 läuft auf BetterPMMP, und alles, was du hier lernst, funktioniert auch dort. Zusätzlich gibt BetterPMMP Plugins Zugriff auf Spielregeln, Scoreboards, Zielauswahl und Strukturen.</p>
<h2>Was du brauchst</h2>
<ul>
<li>Einen BetterPMMP-Server zum Testen (siehe <a href="install.html">Installation</a>).</li>
<li>Einen Editor mit PHP-Unterstützung, zum Beispiel <a href="https://code.visualstudio.com/">VS Code</a> mit der Erweiterung PHP Intelephense oder PhpStorm.</li>
<li>Das Plugin <a href="https://poggit.pmmp.io/p/DevTools">DevTools</a>, damit der Server beim Entwickeln Plugins aus Ordnern lädt.</li>
</ul>
<h2>Dein erstes Plugin</h2>
<p>Ein Plugin ist ein Ordner mit einer <code>plugin.yml</code> und PHP-Klassen. Er kommt in den Ordner <code>plugins</code> deines Servers:</p>
{code(PLUGIN_TREE)}
<h3>plugin.yml</h3>
{code(PLUGIN_YML, "yaml")}
<p><code>api: 5.0.0</code> heißt: läuft mit jeder API-Version von PocketMine-MP 5, also auch mit BetterPMMP. Mit <code>src-namespace-prefix</code> liegen die Klassen direkt in <code>src/</code>.</p>
<h3>src/Main.php</h3>
{code(MAIN_PHP, "php")}
<p>Server neu starten, beitreten und <code>/hello</code> eingeben. Zum Weitergeben baust du mit DevTools eine <code>.phar</code> (<code>/makeplugin HelloWorld</code>) und gibst die Datei aus <code>plugin_data/DevTools</code> weiter.</p>
<h2>Events</h2>
<p>Mobs, Items und Blöcke von BetterPMMP nutzen die normalen PocketMine-Klassen, deshalb funktionieren die üblichen Events auch für sie: Schaden, Tod, Blöcke abbauen und setzen, Interagieren, Items benutzen und so weiter.</p>
{code(EVENTS, "php")}
<h2>BetterPMMP-Schnittstellen</h2>
<p>Diese Klassen gibt es nur in BetterPMMP. Soll dein Plugin auch auf normalem PocketMine-MP laufen, prüfe vorher:</p>
{code(DETECT, "php")}
<h3>Spielregeln</h3>
<p><code>pocketmine\\world\\GameRules</code> liest und ändert die 38 Bedrock-Spielregeln einer Welt, genau wie <code>/gamerule</code>.</p>
{code(GAMERULES, "php")}
<h3>Scoreboard</h3>
<p><code>pocketmine\\scoreboard\\Scoreboard</code> ist dasselbe Scoreboard, das <code>/scoreboard</code> benutzt. Es wird automatisch gespeichert und allen Spielern angezeigt. Anzeigeplätze sind <code>sidebar</code>, <code>list</code> und <code>belowname</code>.</p>
{code(SCORE, "php")}
<h3>Zielauswahl</h3>
<p><code>pocketmine\\command\\utils\\TargetSelector</code> wandelt <code>@a</code>, <code>@p</code>, <code>@e[type=zombie,r=10]</code> und Spielernamen in Wesen um.</p>
{code(SELECTOR, "php")}
<h3>Strukturen</h3>
<p><code>pocketmine\\world\\structure\\McStructure</code> liest, schreibt und setzt <code>.mcstructure</code>-Dateien, das Format des Strukturblocks und der Bedrock-Add-ons.</p>
{code(STRUCT, "php")}
<h2>Weiterlernen</h2>
<ul>
<li><a href="https://doc.pmmp.io/">PocketMine-MP-Dokumentation</a>: Plugin-Grundlagen, Events, Formulare, Tasks.</li>
<li><a href="https://github.com/pmmp/PocketMine-MP/tree/stable/src">PocketMine-MP-Quellcode</a>: die API selbst.</li>
<li><a href="{REPO}">BetterPMMP-Quellcode</a>: die Klassen von oben liegen in <code>src/</code>.</li>
</ul>
<div class="note"><p>Fragen oder Ideen für neue Schnittstellen? Öffne ein <a href="{REPO}/issues">Issue auf GitHub</a>.</p></div>
'''
pages["plugins.html"] = doc_page("plugins.html", "Plugin development", "Plugins entwickeln",
    "Write plugins for BetterPMMP and PocketMine-MP 5: plugin.yml, events, commands and the BetterPMMP APIs for game rules, scoreboards, selectors and structures.",
    plugins("en"), plugins("de"))


# ---------------------------------------------------------------- plugin hub
import json
with open(os.path.join(OUT, "hub", "plugins.json"), encoding="utf-8") as f:
    HUB = json.load(f)
CATS = HUB["categories"]
SUBMIT_URL = f"{REPO}/issues/new?template=plugin-submission.yml"


def dl_url(p):
    return p["download"] if p["download"].startswith("http") else "hub/" + p["download"]


def badges(p):
    out = "".join(f'<span class="tag">{t(CATS[c][0], CATS[c][1])}</span>' for c in p["categories"] if c in CATS)
    if p["betterpmmp_only"]:
        out += f'<span class="tag green">{t("BetterPMMP only", "Nur BetterPMMP")}</span>'
    return out


def initials(name):
    return html.escape(name[:2])


def hub_card(p):
    cats = " ".join(p["categories"])
    catnames = " ".join(CATS[c][0] + " " + CATS[c][1] for c in p["categories"] if c in CATS)
    search = html.escape((p["name"] + " " + p["author"] + " " + p["summary"]["en"] + " " + p["summary"]["de"] + " " + catnames).lower())
    return (f'<a class="card plugin" href="plugin-{p["id"]}.html" data-cats="{cats}" data-search="{search}">'
            f'<div class="pl-head"><div class="pl-icon">{initials(p["name"])}</div><div><h3>{html.escape(p["name"])}</h3>'
            f'<small>{t("by", "von")} {html.escape(p["author"])} · {("v" if p["version"][:1].isdigit() else "") + html.escape(p["version"])}</small></div></div>'
            f'<p>{t(html.escape(p["summary"]["en"]), html.escape(p["summary"]["de"]))}</p><div class="tags">{badges(p)}</div></a>')


used = sorted({c for p in HUB["plugins"] for c in p["categories"]}, key=lambda c: list(CATS).index(c))
chips = f'<button type="button" class="chip" data-cat="" aria-pressed="true">{t("All", "Alle")}</button>' + "".join(
    f'<button type="button" class="chip" data-cat="{c}" aria-pressed="false">{t(CATS[c][0], CATS[c][1])}</button>' for c in used)
cards = "".join(hub_card(p) for p in HUB["plugins"])
n = len(HUB["plugins"])
HUB_BODY = (
    '<section class="hero small"><div class="wrap">'
    f'<span class="badge">{t(f"{n} plugins", f"{n} Plugins")}</span>'
    f'<h1>{t("Plugins for <b>BetterPMMP</b>", "Plugins für <b>BetterPMMP</b>")}</h1>'
    f'<p class="lead">{t("Download plugins for your server, or share your own. Every plugin for PocketMine-MP 5 also runs on BetterPMMP.", "Lade Plugins für deinen Server herunter oder teile deine eigenen. Jedes Plugin für PocketMine-MP 5 läuft auch auf BetterPMMP.")}</p>'
    f'<div class="btns"><a class="btn main" href="submit.html">{t("Submit a plugin", "Plugin einreichen")}</a><a class="btn" href="plugins.html">{t("Write a plugin", "Plugin schreiben")}</a></div>'
    '</div></section>'
    '<section style="padding-top:8px"><div class="wrap">'
    f'<div class="hub-bar"><input id="hub-search" type="search" placeholder="Search plugins …" data-ph-de="Plugins suchen …" data-ph-en="Search plugins …" aria-label="Search"><div class="chips">{chips}</div></div>'
    f'<div class="grid" id="hub-grid">{cards}</div>'
    f'<p id="hub-empty" class="sub" hidden>{t("No plugin matches your search.", "Kein Plugin passt zu deiner Suche.")}</p>'
    '</div></section>'
)
pages["hub.html"] = page("hub.html", "Plugins – BetterPMMP", "Plugins – BetterPMMP",
    "Plugins for BetterPMMP and PocketMine-MP 5: download, search by category and submit your own.", HUB_BODY)

for p in HUB["plugins"]:
    is_ext = p["download"].startswith("http")
    dl_label = t("Download on Poggit", "Auf Poggit herunterladen") if "poggit" in p["download"] else t("Download .phar", ".phar herunterladen")
    cmd_rows = "".join(f'<tr><td><code>{html.escape(c)}</code></td><td>{t(html.escape(e), html.escape(d))}</td></tr>' for c, e, d in p.get("commands", []))
    cmds = ""
    if cmd_rows:
        cmds = (f'<h2>{t("Commands", "Befehle")}</h2><div class="scroll"><table class="table"><thead><tr><th>{t("Command", "Befehl")}</th>'
                f'<th>{t("What it does", "Was er macht")}</th></tr></thead><tbody>{cmd_rows}</tbody></table></div>')
    fname = html.escape(p["download"].rsplit("/", 1)[-1])
    what_en = "the plugin" if is_ext else f"<code>{fname}</code>"
    what_de = "Das Plugin" if is_ext else f"<code>{fname}</code>"
    install = t(
        f'<ol><li>Download {what_en}.</li><li>Put the <code>.phar</code> into the <code>plugins</code> folder of your server.</li><li>Restart the server.</li></ol>',
        f'<ol><li>{what_de} herunterladen.</li><li>Die <code>.phar</code> in den Ordner <code>plugins</code> deines Servers legen.</li><li>Server neu starten.</li></ol>')
    req = t("BetterPMMP" if p["betterpmmp_only"] else "BetterPMMP or PocketMine-MP 5", "BetterPMMP" if p["betterpmmp_only"] else "BetterPMMP oder PocketMine-MP 5")
    dl_attr = "" if is_ext else " download"
    body = (
        '<main class="wrap plugin-page">'
        f'<p class="crumb"><a href="hub.html">{t("Plugins", "Plugins")}</a> / {html.escape(p["name"])}</p>'
        f'<div class="pl-top"><div class="pl-icon big">{initials(p["name"])}</div>'
        f'<div style="flex:1;min-width:0"><h1>{html.escape(p["name"])}</h1>'
        f'<p class="sub" style="margin:0">{t(html.escape(p["summary"]["en"]), html.escape(p["summary"]["de"]))}</p>'
        f'<div class="tags" style="margin-top:10px">{badges(p)}</div></div></div>'
        '<div class="pl-layout"><article class="doc">'
        f'<h2 style="margin-top:0">{t("About", "Über das Plugin")}</h2>'
        f'<p>{t(html.escape(p["description"]["en"]), html.escape(p["description"]["de"]))}</p>'
        f'{cmds}<h2>{t("Installation", "Installation")}</h2>{install}'
        '</article><aside class="card pl-side">'
        f'<a class="btn main wide" href="{dl_url(p)}"{dl_attr}>{dl_label}</a>'
        f'<a class="btn wide" href="{p["source"]}">{t("Source code", "Quellcode")}</a>'
        '<dl>'
        f'<dt>{t("Author", "Autor")}</dt><dd>{html.escape(p["author"])}</dd>'
        f'<dt>{t("Version", "Version")}</dt><dd>{html.escape(p["version"])}</dd>'
        f'<dt>{t("Plugin API", "Plugin-API")}</dt><dd>{html.escape(p["api"])}</dd>'
        f'<dt>{t("Runs on", "Läuft auf")}</dt><dd>{req}</dd>'
        f'<dt>{t("Updated", "Aktualisiert")}</dt><dd>{html.escape(p["updated"])}</dd>'
        '</dl></aside></div></main>'
    )
    pages[f'plugin-{p["id"]}.html'] = page(f'plugin-{p["id"]}.html', f'{p["name"]} – BetterPMMP Plugins', f'{p["name"]} – BetterPMMP-Plugins', p["summary"]["en"], body)

SUBMIT_EN = f'''<h1>Submit a plugin</h1>
<p class="intro">Made a plugin for BetterPMMP or PocketMine-MP 5? Share it here. Every submission is checked by hand before it appears in the list.</p>
<h2>Requirements</h2>
<ul>
<li>The plugin runs on BetterPMMP (API 5).</li>
<li>The source code is public, for example on GitHub.</li>
<li>There is a <code>.phar</code> to download, for example as a GitHub release or on Poggit.</li>
<li>No malware, no hidden tracking, no copied plugins under a new name.</li>
</ul>
<h2>How to submit</h2>
<ol>
<li>Open the submission form on GitHub (you need a free GitHub account).</li>
<li>Fill in name, description, link to the source and the download.</li>
<li>After the check the plugin gets its own page in the list.</li>
</ol>
<p><a class="btn main" href="{SUBMIT_URL}">Open the submission form</a></p>
<h2>For developers</h2>
<p>The plugin list is also available as JSON, for tools and launchers: <a href="hub/plugins.json"><code>hub/plugins.json</code></a>.</p>
'''
SUBMIT_DE = f'''<h1>Plugin einreichen</h1>
<p class="intro">Du hast ein Plugin für BetterPMMP oder PocketMine-MP 5 gebaut? Teile es hier. Jede Einreichung wird von Hand geprüft, bevor sie in der Liste erscheint.</p>
<h2>Voraussetzungen</h2>
<ul>
<li>Das Plugin läuft auf BetterPMMP (API 5).</li>
<li>Der Quellcode ist öffentlich, zum Beispiel auf GitHub.</li>
<li>Es gibt eine <code>.phar</code> zum Herunterladen, zum Beispiel als GitHub-Release oder auf Poggit.</li>
<li>Keine Schadsoftware, kein heimliches Tracking, keine kopierten Plugins unter neuem Namen.</li>
</ul>
<h2>So reichst du ein</h2>
<ol>
<li>Das Formular auf GitHub öffnen (du brauchst ein kostenloses GitHub-Konto).</li>
<li>Name, Beschreibung, Link zum Quellcode und zum Download eintragen.</li>
<li>Nach der Prüfung bekommt das Plugin eine eigene Seite in der Liste.</li>
</ol>
<p><a class="btn main" href="{SUBMIT_URL}">Formular öffnen</a></p>
<h2>Für Entwickler</h2>
<p>Die Plugin-Liste gibt es auch als JSON, für Tools und Launcher: <a href="hub/plugins.json"><code>hub/plugins.json</code></a>.</p>
'''
pages["submit.html"] = page("submit.html", "Submit a plugin – BetterPMMP", "Plugin einreichen – BetterPMMP",
    "Submit your plugin to the BetterPMMP plugin list.",
    f'<main class="wrap" style="max-width:820px;padding-top:34px"><article class="doc">{both(SUBMIT_EN, SUBMIT_DE)}</article></main>')


for name, content in pages.items():
    with open(os.path.join(OUT, name), "w", encoding="utf-8") as f:
        f.write(content)

with open(os.path.join(OUT, "sitemap.xml"), "w") as f:
    f.write('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n')
    for name in pages:
        f.write(f'  <url><loc>{BASE}{"" if name == "index.html" else name}</loc><lastmod>2026-11-26</lastmod></url>\n')
    f.write('</urlset>\n')
print("wrote", ", ".join(pages))
