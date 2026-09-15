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
STATE_DIR="/var/lib/cdrgen"

if [ ! -f "${TARGET}" ]; then
    echo "Error: cdrgen.php not found at ${TARGET}" >&2
    exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "Error: install.sh must be run as root (use sudo)" >&2
    exit 1
fi

if [ -L "${STATE_DIR}" ]; then
    echo "Error: state path must not be a symlink: ${STATE_DIR}" >&2
    exit 1
fi
if [ -e "${STATE_DIR}" ] && [ ! -d "${STATE_DIR}" ]; then
    echo "Error: state path must be a directory: ${STATE_DIR}" >&2
    exit 1
fi
if [ ! -e "${STATE_DIR}" ]; then
    mkdir -- "${STATE_DIR}"
fi

chmod +x "${TARGET}"
ln -sf "${TARGET}" "${LINK}"
chown root:root "${STATE_DIR}"
chmod 0700 "${STATE_DIR}"

echo "cdrgen installed."
echo "  Source:   ${TARGET}"
echo "  Symlink:  ${LINK}"
echo "  State:    ${STATE_DIR}"
echo
echo "Run 'cdrgen' to start."
