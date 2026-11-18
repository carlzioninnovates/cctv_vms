# NVR Compatibility — what will and won't work

Short answer: it will not work on *every* NVR out of the box. It works on most current
Hikvision, Dahua and Uniview units (and their OEM rebrands) **if** the device is probed
on registration and features are switched on per device. This page lists the known gaps.

## How we handle it

1. **Capability probe on registration** (`data-plane/app/drivers/probe.py`).
   Tries the vendor API first, then ONVIF, and records what actually answered:
   live RTSP, substream, codec, playback, PTZ, event stream, HDD health, snapshot.
2. **Stored per device** in `nvrs.capabilities` (JSON) + `channels.*` columns.
3. **UI and billing read capabilities**, not brand. A feature the device can't do is hidden,
   not broken.
4. **Generic ONVIF driver** is the fallback for anything else (EZVIZ, Tiandy, unbranded).

## Known gaps

| Problem | Who it affects | Workaround |
|---|---|---|
| ONVIF disabled by default | Hikvision (most firmware), some Dahua | Installer enables ONVIF + creates an ONVIF user. Vendor API (ISAPI/CGI) still works without it. |
| H.265 main stream won't play in many browsers over WebRTC | All brands — H.265/H.265+ is the NVR default | Live view uses the **H.264 substream**. Main stream: HLS, or set the channel to H.264. Transcoding is possible but CPU-heavy, so it's a paid tier. |
| Smart codecs (H.264+/H.265+, Dahua Smart Codec, UNV U-Code) | All brands | Usually plays, but can cause long GOPs → slow first frame. Recommend off for cloud channels. |
| ONVIF Profile G (playback) barely implemented on NVRs | Most NVRs | Use vendor playback RTSP URLs (see table below). |
| ONVIF Profile T rare on NVRs | Most NVRs | Analytics come from the vendor event stream instead. |
| ONVIF exposes NVR channels inconsistently (one profile per channel, or per stream, or IP-camera-only) | Dahua XVR, older Hikvision DVR | Prefer vendor channel list; ONVIF only for PTZ. |
| Chinese-region (CN) firmware on grey-market units | Hikvision especially — common in PH | Can't be changed to EN firmware; ISAPI mostly works but language/feature differences. Probe flags `firmware_region`. |
| Old firmware with critical CVEs | Hikvision CVE-2021-36260, Dahua CVE-2021-33044/33045 | Flagged by `firmware_advisories`. Customer told to update. |
| Account lockout after failed logins | All brands (often 5–7 tries → 30 min lock) | Probe stops after the first 401; never retries with guesses. |
| Analog DVR/XVR (HD-TVI/CVI/AHD) | Cheap installs | Usually works with vendor API, limited analytics. |
| Xiongmai/XMeye-based generic NVRs | Very common budget units | ONVIF often partial or broken. "Best effort" tier — live view only. |
| ISUP/EHome (Hikvision call-home), Dahua Auto Register | Needs supported firmware | Edge agent is the default; call-home is optional per device. |

## Stream URLs (built only in `data-plane/app/drivers/rtsp.py`)

| Brand | Live | Playback |
|---|---|---|
| Hikvision | `/Streaming/Channels/{ch*100+stream}` (101 = ch1 main) | `/Streaming/tracks/{ch*100+stream}?starttime=YYYYMMDDTHHMMSSZ&endtime=…` |
| Dahua | `/cam/realmonitor?channel={ch}&subtype={0 main,1 sub}` | `/cam/playback?channel={ch}&starttime=YYYY_MM_DD_HH_MM_SS&endtime=…` |
| Uniview | `/unicast/c{ch}/s{0 main,1 sub}/live` | `/c{ch}/b{unix_start}/e{unix_end}/replay/` ⚠ verify per firmware |

Uniview LAPI endpoints in `drivers/uniview.py` are marked `VERIFY` — check them against a real
unit before release.

## Test lab — needed before launch

Buy or borrow at least one of each, then run `pytest -m hardware` against them:

- Hikvision NVR (current firmware) + one older unit (pre-2021 firmware)
- Hikvision DVR (Turbo HD)
- Dahua NVR + Dahua XVR
- Uniview NVR
- One generic XMeye NVR
