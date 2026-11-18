#!/usr/bin/env bash
# End-to-end tunnel test against the running live lab (infra/dev/live_lab.py) and Laravel on :8000.
#   install agent -> online -> real frp tunnel -> video through MediaMTX -> revoke (timed)
#   -> "Replace agent" on the same device -> tunnels restored automatically -> video again
# Uses the "Warehouse · Pasig" demo site's agent and the Lab NVR (id 4). Sandbox install only.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"; cd "$ROOT"
SB="${SB:-$(mktemp -d)/agent-root}"; LOG="$(dirname "$SB")/agent.log"; mkdir -p "$SB"
PY=data-plane/.venv/bin/python; ART="php control-plane/artisan"
PASS=0; FAIL=0
ok()   { echo "  PASS  $*"; PASS=$((PASS+1)); }
bad()  { echo "  FAIL  $*"; FAIL=$((FAIL+1)); }
tinker() { $ART tinker --execute="$1" 2>/dev/null | grep -vE '^(Warning|$)' | tail -1; }
code()  { tinker 'echo App\Models\Site::where("name","Warehouse · Pasig")->first()->edgeAgents()->first()->issueEnrollmentCode();'; }
start_agent() {
  VMS_AGENT_CONF="$SB/etc/zion-agent" VMS_BUFFER_PATH="$SB/var/lib/zion-agent/events.db" \
  VMS_FRPC_BIN="$SB/opt/zion-agent/bin/frpc" VMS_PIN_CA=1 "$SB/opt/zion-agent/venv/bin/zion-agent" run >>"$LOG" 2>&1 &
  AGENT_PID=$!
}
stop_agent() { [ -n "${AGENT_PID:-}" ] && kill "$AGENT_PID" 2>/dev/null && wait "$AGENT_PID" 2>/dev/null; AGENT_PID=; }
trap stop_agent EXIT
uid()   { python3 -c "import json; print(json.load(open('$SB/etc/zion-agent/agent.json'))['agent_uid'])"; }
wait_for() { # seconds description command...
  local n=$1 d=$2; shift 2; local t0=$SECONDS
  while (( SECONDS - t0 < n )); do "$@" >/dev/null 2>&1 && { WAITED=$((SECONDS - t0)); return 0; }; sleep 1; done; return 1; }
online()   { $PY -c "import redis,sys; sys.exit(0 if redis.Redis(port=6390).exists('vms:agent:$(uid):hb') else 1)"; }
tunnel_ok(){ timeout 8 ffprobe -v error -rtsp_transport tcp -show_entries stream=codec_name -of csv=p=0 \
               "rtsp://admin:Sim%40Pass%231@127.0.0.1:${RTSP_PORT:?}/Streaming/Channels/102" | grep -q h264; }
tunnel_dead() { ! tunnel_ok; }
full_chain() {
  local P T
  P=$($PY - <<'PY'
import json, time, httpx, sys
sys.path.insert(0, "data-plane")
from app.core.security import sign
b = json.dumps({"stream": "sub"}).encode()
r = httpx.post("http://127.0.0.1:8001/internal/nvrs/4/channels/1/live", content=b,
               headers={"X-VMS-Signature": sign("change-me", int(time.time()), b), "Content-Type": "application/json"})
print(r.json().get("path", ""))
PY
)
  T=$(tinker "echo app(App\Services\Media\ViewerToken::class)->issue(1, '$P')['token'];")
  local out
  out=$(timeout 30 ffprobe -v error -rtsp_transport tcp -show_entries stream=codec_name -of csv=p=0 "rtsp://viewer:$T@127.0.0.1:8554/$P" 2>&1)
  echo "$out" | grep -q h264 || { echo "        ffprobe said: $(echo "$out" | sed -E "s/viewer:[^@]+@/viewer:***@/" | head -2 | tr '\n' ' ')"; return 1; }
}

echo "1. Install + enroll (agent $(grep -oE '"version": "[^"]+"' control-plane/storage/app/agent-release/manifest.json | cut -d'"' -f4))"
C=$(code); curl -fsSL http://localhost:8000/install-agent.sh | ZION_ROOT="$SB" bash -s -- "$C" >/dev/null 2>&1 && ok "installed and enrolled" || bad "install"
start_agent
wait_for 30 online online && ok "agent online after ${WAITED}s" || bad "agent never came online"

echo "2. Open a real tunnel (Laravel -> data plane -> agent -> frpc -> frps)"
R=$(tinker "echo app(App\Services\DataPlane\DataPlaneClient::class)->expose('$(uid)', '127.0.0.1', [['port' => 80, 'kind' => 'http'], ['port' => 8654, 'kind' => 'rtsp']])->body();")
RTSP_PORT=$(echo "$R" | python3 -c "import json,sys; print(json.load(sys.stdin)['ports']['8654'])" 2>/dev/null)
[ -n "$RTSP_PORT" ] && ok "tunnel ports allocated (RTSP -> frps :$RTSP_PORT): $R" || bad "expose: $R"
$PY - "$(uid)" <<'PY'
import json, sys, redis
r = redis.Redis(port=6390); rec = json.loads(r.get("vms:nvr:4"))
rec.update(agent_id=sys.argv[1], lan_host="127.0.0.1", http_port=80, rtsp_port=8654); r.set("vms:nvr:4", json.dumps(rec))
PY
wait_for 20 tunnel tunnel_ok && ok "RTSP through frps:$RTSP_PORT -> frpc -> NVR" || bad "tunnel carries no video"
full_chain && ok "viewer -> MediaMTX (token) -> tunnel -> NVR" || bad "full chain"

echo "3. Revoke (stolen device): open session must be cut"
tinker "echo app(App\Services\DataPlane\DataPlaneClient::class)->revokeCertificate('$(uid)')->status();" >/dev/null
wait_for 40 "tunnel dead" tunnel_dead && ok "tunnel stopped carrying video ${WAITED}s after revoke" || bad "tunnel still works 40s after revoke"
grep -q "listener is closed: accept tcp 127.0.0.1:$RTSP_PORT" infra/dev/state/logs/frps.log && ok "frps closed the revoked session's tunnel listener" || bad "frps never closed the revoked tunnel"

echo "4. Replace agent on the same device: tunnels must come back without re-adding NVRs"
stop_agent
C=$(code); curl -fsSL http://localhost:8000/install-agent.sh | ZION_ROOT="$SB" bash -s -- "$C" >/dev/null 2>&1 && ok "re-enrolled with a new code" || bad "re-enroll"
start_agent
wait_for 30 online online && ok "agent online after ${WAITED}s" || bad "agent never came online"
wait_for 40 tunnel tunnel_ok && ok "tunnel restored automatically after ${WAITED}s" || bad "tunnel not restored"
full_chain && ok "viewer -> MediaMTX -> tunnel -> NVR works again" || bad "full chain after replace"

stop_agent
echo; echo "$PASS passed, $FAIL failed   (agent log: $LOG)"
[ "$FAIL" = 0 ]
