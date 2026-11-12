# BetterPMMP

GitHub: https://github.com/BetterPMMP/Betterpmmp

PocketMine-MP 5 (API 5.51.0) based server for Minecraft: Bedrock Edition, built on the
Axolotl-MP dependency set. Goal: vanilla gameplay parity, implemented system by system.

Current state: see `BETTERPMMP-REDSTONE.md` and `VANILLA-PARITY.md`.

## Running from source

```
php src/PocketMine.php
```

Use the PocketMine PHP binary (PHP 8.1+ with the pmmp extensions). `vendor/` is committed
because several dependencies are Axolotl-MP forks.

Licensed under LGPL-3.0-or-later, like PocketMine-MP.
