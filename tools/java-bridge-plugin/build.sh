#!/bin/sh
# Builds BetterPMMP-Bridge.jar (ViaProxy plugin) into resources/java-bridge/.
# Usage: tools/java-bridge-plugin/build.sh /path/to/ViaProxy.jar
set -e
VIAPROXY="$1"
[ -f "$VIAPROXY" ] || { echo "usage: $0 /path/to/ViaProxy.jar"; exit 1; }
DIR=$(cd "$(dirname "$0")" && pwd)
OUT=$(mktemp -d)
javac --release 17 -nowarn -cp "$VIAPROXY" -d "$OUT" $(find "$DIR/src/main/java" -name '*.java')
cp "$DIR/src/main/resources/viaproxy.yml" "$OUT/"
(cd "$OUT" && jar --create --file "$DIR/../../resources/java-bridge/BetterPMMP-Bridge.jar" .)
rm -rf "$OUT"
echo "built resources/java-bridge/BetterPMMP-Bridge.jar"
