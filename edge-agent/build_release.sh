#!/usr/bin/env bash
# Builds the agent release the installer downloads:
#   control-plane/storage/app/agent-release/{zion_agent-<ver>-py3-none-any.whl, constraints.txt, manifest.json}
# Run after changing the agent:  edge-agent/build_release.sh
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
OUT="$HERE/../control-plane/storage/app/agent-release"
PY="${PYTHON:-python3}"

# frp (tunnel client). Pinned + checksums from the official frp_sha256_checksums.txt.
FRP_VERSION="0.71.1"
FRP_SHA256_AMD64="1d9875210972a5d1a62b2dc4c48df45ab0af05d3ca1b5e494a014428259df803"
FRP_SHA256_ARM64="8edf590b0c396fa42f9c147121a76af83cfc2002b08f5aef0ea1008c43482019"

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
mkdir -p "$OUT"
VERSION="$("$PY" -c "import re,sys; print(re.search(r'__version__ = \"(.+)\"', open('$HERE/agent/__init__.py').read()).group(1))")"

echo "building zion-agent $VERSION"
"$PY" -m venv "$WORK/venv"
"$WORK/venv/bin/pip" install -q --upgrade pip
"$WORK/venv/bin/pip" wheel -q --no-deps -w "$WORK/dist" "$HERE"
WHEEL="$(basename "$(ls "$WORK"/dist/zion_agent-*.whl)")"

# Exact versions of every runtime dependency, resolved in a clean venv
"$WORK/venv/bin/pip" install -q "$WORK/dist/$WHEEL"
"$WORK/venv/bin/pip" freeze --exclude zion-agent | grep -v '^-e ' > "$WORK/constraints.txt"

rm -f "$OUT"/zion_agent-*.whl
cp "$WORK/dist/$WHEEL" "$WORK/constraints.txt" "$OUT/"
cat > "$OUT/manifest.json" <<JSON
{
  "version": "$VERSION",
  "wheel": "$WHEEL",
  "wheel_sha256": "$(sha256sum "$OUT/$WHEEL" | cut -d' ' -f1)",
  "constraints_sha256": "$(sha256sum "$OUT/constraints.txt" | cut -d' ' -f1)",
  "python_min": "3.11",
  "frp": {
    "version": "$FRP_VERSION",
    "sha256": { "amd64": "$FRP_SHA256_AMD64", "arm64": "$FRP_SHA256_ARM64" }
  },
  "built_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON
echo "release in $OUT:"; ls -1 "$OUT"; echo "dependencies pinned: $(wc -l < "$OUT/constraints.txt")"
