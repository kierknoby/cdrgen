#!/bin/bash
#
# cdrgen install script
#
# Makes cdrgen.php executable and symlinks it into /usr/local/bin/cdrgen
# so it can be invoked as 'cdrgen' from anywhere.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="${SCRIPT_DIR}/cdrgen.php"
LINK="/usr/local/bin/cdrgen"

if [ ! -f "${TARGET}" ]; then
    echo "Error: cdrgen.php not found at ${TARGET}" >&2
    exit 1
fi

chmod +x "${TARGET}"

if [ "$(id -u)" -ne 0 ]; then
    echo "Error: install.sh must be run as root (use sudo)" >&2
    exit 1
fi

ln -sf "${TARGET}" "${LINK}"

echo "cdrgen installed."
echo "  Source:   ${TARGET}"
echo "  Symlink:  ${LINK}"
echo
echo "Run 'cdrgen' to start."
