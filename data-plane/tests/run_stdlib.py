"""Runs the stdlib-only tests without pytest (for machines without pip)."""
import importlib
import sys
import traceback
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
failed = passed = 0
for mod in ["tests.test_rtsp", "tests.test_normalizer", "tests.test_offline_ring_security_parsers"]:
    m = importlib.import_module(mod)
    for name in sorted(dir(m)):
        if name.startswith("test_"):
            try:
                getattr(m, name)()
                passed += 1
            except Exception:
                failed += 1
                print(f"FAIL {mod}.{name}")
                traceback.print_exc()
print(f"{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
