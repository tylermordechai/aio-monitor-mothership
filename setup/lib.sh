# setup/lib.sh: helpers shared by the numbered setup scripts. Sourced, not run.
# Every script prints a banner, does its one job, then runs checks that print
# PASS or FAIL, and ends with "RESULT: PASS" or "RESULT: FAIL" (exit 1).

set -euo pipefail
umask 022

AIO_SETUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AIO_REPO="$(cd "$AIO_SETUP_DIR/.." && pwd)"
# shellcheck source=setup/env.sh
source "$AIO_SETUP_DIR/env.sh"

PASS_COUNT=0
FAIL_COUNT=0

banner() { # banner "what it does" "sudo: yes/no" "reboots: ..."
    echo "=================================================================="
    echo " $(basename "$0")"
    echo " What it does: $1"
    echo " Needs sudo:   $2"
    echo " Reboots:      $3"
    echo " Host: $(hostname)  Time (UTC): $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo " Repo: $AIO_REPO @ $(git -c safe.directory="$AIO_REPO" -C "$AIO_REPO" rev-parse --short HEAD 2>/dev/null || echo 'not a git checkout')"
    echo "=================================================================="
}

info() { printf '==> %s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*"; }
pass() { printf 'PASS: %s\n' "$*"; PASS_COUNT=$((PASS_COUNT + 1)); }
fail() { printf 'FAIL: %s\n' "$*"; FAIL_COUNT=$((FAIL_COUNT + 1)); }
die()  { printf 'STOPPED: %s\n' "$*"; echo "RESULT: FAIL"; exit 1; }

# check "description" command args...  -> PASS if the command succeeds
check() {
    local desc=$1; shift
    if "$@" >/dev/null 2>&1; then pass "$desc"; else fail "$desc"; fi
}

# check_not "description" command args...  -> PASS if the command fails
check_not() {
    local desc=$1; shift
    if "$@" >/dev/null 2>&1; then fail "$desc"; else pass "$desc"; fi
}

need_root() {
    [[ $EUID -eq 0 ]] || { echo "This script needs sudo: sudo bash $0 $*"; exit 2; }
}

no_root() {
    [[ $EUID -ne 0 ]] || { echo "Run this script as aioadmin, without sudo: bash $0"; exit 2; }
}

# file_mode_is path mode owner group
file_mode_is() {
    [[ "$(stat -c '%a %U %G' "$1" 2>/dev/null)" == "$2 $3 $4" ]]
}

# Install apt packages non-interactively; never prompts, keeps local config.
apt_install() {
    DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a apt-get install -y -q \
        -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold "$@"
}

# Render a template from setup/templates, replacing @AIO_IP@, @AIO_REPO@ and
# @PHP@; write it only if it changed. Returns 0 if written, 1 if unchanged.
render() {
    local src="$AIO_SETUP_DIR/templates/$1" dst=$2 mode=${3:-0644} tmp
    tmp=$(mktemp)
    sed -e "s|@AIO_IP@|$AIO_IP|g" -e "s|@AIO_REPO@|$AIO_REPO|g" \
        -e "s|@AIO_LAN@|$AIO_LAN|g" -e "s|@PHP@|$AIO_PHP_VERSION|g" "$src" > "$tmp"
    if [[ -f "$dst" ]] && cmp -s "$tmp" "$dst"; then
        rm -f "$tmp"; return 1
    fi
    install -m "$mode" -o root -g root "$tmp" "$dst"
    rm -f "$tmp"
    info "wrote $dst"
    return 0
}

finish() {
    echo
    echo "Summary: $PASS_COUNT passed, $FAIL_COUNT failed"
    if [[ $FAIL_COUNT -eq 0 ]]; then
        echo "RESULT: PASS"
    else
        echo "RESULT: FAIL"
        exit 1
    fi
}
