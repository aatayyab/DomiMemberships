#!/usr/bin/env bash
# Builds wp-memberships-<version>.zip containing only the plugin files.
set -e
cd "$(dirname "$0")/.."
VERSION=$(grep -m1 "Version:" wp-memberships.php | sed 's/.*Version:[[:space:]]*//' | tr -d '\r ')
rm -rf build && mkdir -p build/wp-memberships
cp wp-memberships.php uninstall.php readme.txt build/wp-memberships/
(cd build && zip -qr "wp-memberships-${VERSION}.zip" wp-memberships)
echo "Built build/wp-memberships-${VERSION}.zip"
