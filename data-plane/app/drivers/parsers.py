"""Response parsers for vendor APIs. Stdlib only, unit-tested."""
from __future__ import annotations

import re
import xml.etree.ElementTree as ET


def dahua_kv(text: str) -> dict[str, str]:
    """Dahua CGI returns 'key=value' lines, e.g. 'table.Encode[0].MainFormat[0].Video.Compression=H.265'."""
    out: dict[str, str] = {}
    for line in text.splitlines():
        line = line.strip()
        if not line or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip()] = v.strip()
    return out


_IDX = re.compile(r"\[(\d+)\]")


def dahua_indexed(kv: dict[str, str], prefix: str) -> dict[int, dict[str, str]]:
    """Group 'table.ChannelTitle[3].Name=Gate' into {3: {'Name': 'Gate'}} (0-based index)."""
    groups: dict[int, dict[str, str]] = {}
    for k, v in kv.items():
        if not k.startswith(prefix + "["):
            continue
        m = _IDX.match(k, len(prefix))
        if not m:
            continue
        rest = k[m.end():].lstrip(".")
        groups.setdefault(int(m.group(1)), {})[rest] = v
    return groups


def dahua_event_line(text: str) -> dict[str, str]:
    """'Code=VideoMotion;action=Start;index=0' -> dict. data= part may contain JSON with ';'."""
    out: dict[str, str] = {}
    head, _, data = text.partition(";data=")
    for part in head.strip().split(";"):
        if "=" in part:
            k, v = part.split("=", 1)
            out[k.strip()] = v.strip()
    if data:
        out["data"] = data.strip()
    return out


def xml_strip_ns(xml_text: str | bytes) -> ET.Element:
    """Parse Hikvision ISAPI XML and drop namespaces so .find('serialNumber') just works."""
    root = ET.fromstring(xml_text)
    for el in root.iter():
        if isinstance(el.tag, str) and "}" in el.tag:
            el.tag = el.tag.split("}", 1)[1]
    return root


def xml_text(el: ET.Element, path: str) -> str | None:
    found = el.find(path)
    return found.text.strip() if found is not None and found.text else None


_CJK = re.compile(r"[一-鿿]")


def looks_cn_region(*values: str | None) -> bool:
    """Heuristic: CN-region firmware tends to return Chinese device names/descriptions."""
    return any(v and _CJK.search(v) for v in values)
