#!/usr/bin/env bash
# Zi-on VMS site agent installer.
#
#   curl -fsSL @@API_URL@@/install-agent.sh | sudo bash -s -- ABCD-EFGH
#
# Prefer to read it first? Download, inspect, then run:
#   curl -fsSLo install-agent.sh @@API_URL@@/install-agent.sh && less install-agent.sh && sudo bash install-agent.sh ABCD-EFGH
#
# Options:
#   ABCD-EFGH       enrollment code from the dashboard (asked for if missing on a new install)
#   --upgrade       update the agent software only (keeps enrollment)
#   --uninstall     stop and remove the agent
#
# Supports 64-bit Raspberry Pi OS / Debian 12+ / Ubuntu 24.04+ (x86_64 or arm64) with systemd.
# Everything downloaded is checked against SHA-256 hashes embedded below by the server.
set -euo pipefail

API_URL="@@API_URL@@"
AGENT_VERSION="@@AGENT_VERSION@@"
WHEEL="@@WHEEL@@"
WHEEL_SHA256="@@WHEEL_SHA256@@"
CONSTRAINTS_SHA256="@@CONSTRAINTS_SHA256@@"
PYTHON_MIN="@@PYTHON_MIN@@"
FRP_VERSION="@@FRP_VERSION@@"
FRP_SHA256_amd64="@@FRP_SHA256_AMD64@@"
FRP_SHA256_arm64="@@FRP_SHA256_ARM64@@"

# ZION_ROOT installs under another directory and skips system changes (users, apt, systemd).
# Used for testing; leave unset on real devices.
ROOT="${ZION_ROOT:-}"
SANDBOX=0; [ -n "$ROOT" ] && SANDBOX=1
OPT="$ROOT/opt/zion-agent"
CONF="$ROOT/etc/zion-agent"
STATE="$ROOT/var/lib/zion-agent"
UNIT="$ROOT/etc/systemd/system/zion-agent.service"
SVC_USER="zion-agent"

# ---------- output ----------
if [ -t 1 ]; then B=$'\e[1m'; G=$'\e[32m'; R=$'\e[31m'; Y=$'\e[33m'; N=$'\e[0m'; else B=; G=; R=; Y=; N=; fi
step() { printf '%s==>%s %s\n' "$B" "$N" "$*"; }
ok()   { printf '    %s✓%s %s\n' "$G" "$N" "$*"; }
warn() { printf '    %s!%s %s\n' "$Y" "$N" "$*"; }
die()  { printf '\n%sError:%s %s\n' "$R" "$N" "$*" >&2; exit 1; }

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

# ---------- arguments ----------
CODE=""; MODE=install
for a in "$@"; do
  case "$a" in
    --upgrade) MODE=upgrade ;;
    --uninstall) MODE=uninstall ;;
    -h|--help) sed -n '2,16p' "$0" 2>/dev/null || true; exit 0 ;;
    -*) die "unknown option $a" ;;
    *) CODE="$a" ;;
  esac
done

# ---------- checks ----------
preflight() {
  [ "$SANDBOX" = 1 ] || [ "$(id -u)" = 0 ] || die "run with sudo:  curl -fsSL $API_URL/install-agent.sh | sudo bash -s -- YOUR-CODE"
  [ "$(uname -s)" = Linux ] || die "the site agent runs on Linux (Raspberry Pi OS, Debian or Ubuntu)."
  case "$(uname -m)" in
    x86_64|amd64) ARCH=amd64 ;;
    aarch64|arm64) ARCH=arm64 ;;
    armv7l|armv6l) die "this is 32-bit ARM. Install the 64-bit version of Raspberry Pi OS (Raspberry Pi 3/4/5) and run this again." ;;
    *) die "unsupported CPU: $(uname -m)" ;;
  esac
  if [ "$SANDBOX" = 0 ]; then
    command -v systemctl >/dev/null && [ -d /run/systemd/system ] || die "systemd is required."
  fi
  command -v curl >/dev/null || die "curl is required (apt install curl)."
}

install_packages() {
  if [ "$SANDBOX" = 1 ]; then return; fi
  step "Installing system packages"
  if command -v apt-get >/dev/null; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq || warn "apt update failed; continuing with what's installed"
    apt-get install -y -qq python3 python3-venv ca-certificates curl >/dev/null || die "could not install python3 / python3-venv."
    apt-get install -y -qq nut-client >/dev/null 2>&1 && ok "UPS support (nut-client)" || warn "nut-client not installed: UPS power alerts unavailable (optional)"
  else
    command -v python3 >/dev/null || die "python3 not found and this isn't a Debian/Ubuntu system: install Python $PYTHON_MIN+ first."
  fi
  ok "packages ready"
}

check_python() {
  PYTHON="$(command -v python3 || true)"
  [ -n "$PYTHON" ] || die "python3 not found."
  "$PYTHON" -c "import sys; v=tuple(map(int,'$PYTHON_MIN'.split('.'))); sys.exit(0 if sys.version_info[:2] >= v else 1)" \
    || die "Python $PYTHON_MIN or newer is needed (found $("$PYTHON" -V 2>&1)). Use Raspberry Pi OS Bookworm, Debian 12 or Ubuntu 24.04."
  "$PYTHON" -c "import venv, ensurepip" 2>/dev/null || die "python3-venv is missing (apt install python3-venv)."
}

reach_server() {
  step "Checking connection to $API_URL"
  curl -fsS --max-time 15 -o /dev/null "$API_URL/up" || die "can't reach $API_URL. Check the site's internet connection and DNS."
  ok "server reachable"
}

download() { # url dest sha256
  curl -fsSL --retry 3 --retry-delay 2 --max-time 300 -o "$2" "$1" || die "download failed: $1"
  echo "$3  $2" | sha256sum -c --status - || die "checksum mismatch for $(basename "$2") — refusing to install. Try again; if it repeats, contact support."
}

# ---------- install / upgrade ----------
create_user_and_dirs() {
  if [ "$SANDBOX" = 0 ] && ! id "$SVC_USER" >/dev/null 2>&1; then
    useradd --system --home-dir /var/lib/zion-agent --shell /usr/sbin/nologin "$SVC_USER"
    ok "created service user $SVC_USER"
  fi
  install -d -m 755 "$OPT" "$OPT/bin"
  install -d -m 700 "$CONF" "$STATE"
}

install_agent() {
  step "Installing Zi-on agent $AGENT_VERSION"
  download "$API_URL/downloads/agent/$WHEEL" "$TMP/$WHEEL" "$WHEEL_SHA256"
  download "$API_URL/downloads/agent/constraints.txt" "$TMP/constraints.txt" "$CONSTRAINTS_SHA256"
  ok "agent package verified"
  if [ ! -x "$OPT/venv/bin/python" ]; then "$PYTHON" -m venv "$OPT/venv"; fi
  "$OPT/venv/bin/pip" install -q --disable-pip-version-check --prefer-binary -c "$TMP/constraints.txt" "$TMP/$WHEEL" \
    || die "installing Python packages failed (see above). Check internet access to pypi.org."
  # Always replace the agent itself (pip skips a wheel whose version is already installed)
  "$OPT/venv/bin/pip" install -q --disable-pip-version-check --force-reinstall --no-deps "$TMP/$WHEEL" \
    || die "installing the agent package failed."
  ok "agent installed ($("$OPT/venv/bin/zion-agent" --version))"
}

install_frpc() {
  step "Installing tunnel client (frp $FRP_VERSION)"
  if [ -x "$OPT/bin/frpc" ] && [ "$("$OPT/bin/frpc" -v 2>/dev/null)" = "$FRP_VERSION" ]; then ok "already installed"; return; fi
  local sum_var="FRP_SHA256_$ARCH" tgz="frp_${FRP_VERSION}_linux_${ARCH}.tar.gz"
  download "https://github.com/fatedier/frp/releases/download/v${FRP_VERSION}/${tgz}" "$TMP/$tgz" "${!sum_var}"
  tar -xzf "$TMP/$tgz" -C "$TMP" "frp_${FRP_VERSION}_linux_${ARCH}/frpc"
  install -m 755 "$TMP/frp_${FRP_VERSION}_linux_${ARCH}/frpc" "$OPT/bin/frpc"
  ok "frpc verified and installed"
}

enroll() {
  if [ -f "$CONF/agent.json" ] && [ -z "$CODE" ]; then ok "already enrolled (keeping it)"; return; fi
  if [ -z "$CODE" ]; then
    [ -r /dev/tty ] || die "enrollment code missing. Run: curl -fsSL $API_URL/install-agent.sh | sudo bash -s -- YOUR-CODE"
    printf 'Enrollment code from the dashboard (e.g. K7QM-9XWD): '; read -r CODE </dev/tty
  fi
  step "Enrolling with $API_URL"
  VMS_AGENT_CONF="$CONF" "$OPT/venv/bin/zion-agent" enroll "$CODE" --api "$API_URL" \
    || die "enrollment failed (message above). Codes work once and expire after 24 h — get a new one from the dashboard."
  chmod 600 "$CONF/agent.key" "$CONF/agent.json"
  ok "enrolled"
}

install_service() {
  if [ "$SANDBOX" = 0 ]; then chown -R "$SVC_USER:$SVC_USER" "$CONF" "$STATE"; fi
  install -d "$(dirname "$UNIT")"
  cat > "$UNIT" <<UNIT_EOF
[Unit]
Description=Zi-on VMS site agent
Documentation=$API_URL/help
After=network-online.target
Wants=network-online.target

[Service]
User=$SVC_USER
Group=$SVC_USER
Environment=VMS_AGENT_CONF=/etc/zion-agent
Environment=VMS_BUFFER_PATH=/var/lib/zion-agent/events.db
Environment=VMS_FRPC_BIN=/opt/zion-agent/bin/frpc
ExecStart=/opt/zion-agent/venv/bin/zion-agent run
Restart=always
RestartSec=5
# Come back quickly after brownouts
StartLimitIntervalSec=0
# Hardening: the agent only needs its own config and state
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
LockPersonality=yes
ReadWritePaths=/etc/zion-agent /var/lib/zion-agent

[Install]
WantedBy=multi-user.target
UNIT_EOF
  if [ "$SANDBOX" = 1 ]; then ok "service file written (sandbox: not started)"; return; fi
  systemctl daemon-reload
  systemctl enable zion-agent >/dev/null 2>&1
  systemctl restart zion-agent
  sleep 4
  systemctl is-active --quiet zion-agent || die "the agent service didn't start. See: journalctl -u zion-agent -n 50"
  ok "service running and set to start on boot"
}

uninstall() {
  step "Removing the Zi-on agent"
  if [ "$SANDBOX" = 0 ]; then
    systemctl disable --now zion-agent >/dev/null 2>&1 || true
  fi
  rm -f "$UNIT"; [ "$SANDBOX" = 0 ] && systemctl daemon-reload || true
  rm -rf "$OPT" "$CONF" "$STATE"
  [ "$SANDBOX" = 0 ] && userdel "$SVC_USER" >/dev/null 2>&1 || true
  ok "removed. Also use 'Replace agent' in the dashboard if this device was stolen or lost."
}

main() {
  printf '%sZi-on VMS site agent installer%s (agent %s)\n\n' "$B" "$N" "$AGENT_VERSION"
  preflight
  if [ "$MODE" = uninstall ]; then uninstall; exit 0; fi
  if [ "$MODE" = upgrade ] && [ ! -f "$CONF/agent.json" ]; then die "not installed yet; run with your enrollment code instead of --upgrade."; fi
  install_packages
  check_python
  reach_server
  create_user_and_dirs
  install_agent
  install_frpc
  [ "$MODE" = upgrade ] || enroll
  install_service
  printf '\n%s✓ Done.%s The site should show the agent as %sOnline%s in the dashboard within a minute.\n' "$G" "$N" "$B" "$N"
  printf '  Logs:      journalctl -u zion-agent -f\n  Upgrade:   curl -fsSL %s/install-agent.sh | sudo bash -s -- --upgrade\n' "$API_URL"
}

main
