# {app} user guide

{app} lets you watch and manage Hikvision, Dahua and Uniview NVRs from anywhere — a phone in
Cabanatuan can watch a store in Manila. No port forwarding, no public IP, and it works behind
PLDT, Globe, Converge and other CGNAT internet connections.

> **Setting up a new site takes 3 steps:** add the site → install the site agent → add the NVR.
> Most installs take about 15 minutes once you're on site.

## Before you go to the site

Bring or confirm:

- **The NVR's local username and password.** This is the login on the NVR itself, not the
  Hik-Connect, DMSS or EZCloud app account. If you only have app access, ask the owner for the
  local password.
- **A device for the site agent** that stays on 24/7 on the same network as the NVR: a Raspberry
  Pi 4, a mini PC, or an always-on computer. Plug it into the same router or switch as the NVR.
- **The owner's permission** to connect their cameras to the cloud. You'll confirm this when you
  add the NVR (Data Privacy Act).
- **Upload speed at the site.** Each camera you watch live uses about 0.5–1 Mbps of the site's
  upload (SD). A 5 Mbps upload handles a few cameras at once comfortably.

## Sign in

Open {app} in your browser and sign in with the email and password your company gave you.
Too many wrong passwords locks sign-in for a minute.

The top bar has **Sites** (everything you manage) and **Help** (this guide).

## Add a client and a site

1. Go to **Sites**.
2. Installer companies: click **New client** and enter the business name (e.g. *Reyes Hardware*).
3. Click **New site**, pick the client, and enter a name like *Main branch · Quezon City*.
4. Optional but useful: the internet provider and whether the site has a **UPS**. With a UPS,
   {app} can tell a power outage apart from an internet outage straight away.

## Install the site agent

The site agent is a small program that connects the NVR to the cloud. It only connects
**outward**, so you never need to open ports on the router.

### What you need

- A **Raspberry Pi 3/4/5** with **64-bit** Raspberry Pi OS (Bookworm or newer), or any PC / mini PC
  with **Debian 12+** or **Ubuntu 24.04+**.
- Plugged into the **same network as the NVR** (same router or switch), on 24/7. A small UPS is
  worth it: it keeps the agent up during brownouts and lets {app} report power outages.
- Internet on that network, and a keyboard/screen or SSH access to the device for the first setup.

### Install (about 2 minutes)

1. In {app}, open the site and click **Set up site agent**.
2. Copy the one-line command shown under the enrollment code. It looks like:

   ```
   curl -fsSL https://your-zi-on-address/install-agent.sh | sudo bash -s -- K7QM-9XWD
   ```

3. Paste it into the device's terminal and press Enter. It asks for the device's password (sudo),
   then installs everything, connects with the code, and starts the agent.
4. Keep the dashboard open. Within a minute the agent shows **Online**.

The code works **once** and expires after **24 hours**. If it expired, click **Show a new code**.

The agent starts by itself after every reboot or power cut. Everything it downloads is checked
against fingerprints from your {app} server before installing.

### Useful commands on the device

| Task | Command |
|---|---|
| See what the agent is doing | `journalctl -u zion-agent -f` |
| Is it running? | `systemctl status zion-agent` |
| Update the agent | `curl -fsSL https://your-zi-on-address/install-agent.sh \| sudo bash -s -- --upgrade` |
| Remove the agent | `curl -fsSL https://your-zi-on-address/install-agent.sh \| sudo bash -s -- --uninstall` |

### If the install fails

| Message | What to do |
|---|---|
| **this is 32-bit ARM** | Reinstall the device with the **64-bit** Raspberry Pi OS. |
| **Python 3.11 or newer is needed** | Use Raspberry Pi OS Bookworm, Debian 12 or Ubuntu 24.04 (Ubuntu 22.04 is too old). |
| **can't reach …** | The device has no internet, or DNS isn't working. Check the network cable and router. |
| **enrollment failed … expire after 24 h** | The code was already used or expired. Get a new one from the dashboard and run the command again. |
| **checksum mismatch** | A download was corrupted or tampered with. Run it again; if it repeats, contact support. |
| **agent service didn't start** | Run `journalctl -u zion-agent -n 50` and send the output to support. |

### Replacing an agent

Broken device or new hardware: open the site → **Replace agent**. The old agent is disconnected
immediately and you get a new code for the new device.

## Add an NVR

You can add an NVR once the site agent is **Online**.

### Option A — Scan the network

1. On the site page, click **Scan network**.
2. Found devices appear with their IP and brand. Click **Add** next to the NVR.

Hikvision NVRs only show up in scans when ONVIF is enabled. If yours doesn't appear, use option B.

### Option B — Add manually

1. Click **Add manually**.
2. **Pick the brand.** The form shows where to find the IP address on that brand's menu.
3. **Enter the NVR's IP address**, e.g. `192.168.1.64`.
4. **Enter the NVR username and password.** We recommend a separate NVR user just for the cloud
   (see below), not `admin`.
5. **Ports:** leave them alone unless the NVR uses non-default ports (HTTP 80, RTSP 554).
6. **Tick the owner authorization box** and click **Add & connect**.

{app} then shows live progress:

1. *Connecting through the site agent*
2. *Logging in to the NVR and reading cameras*
3. *Saving cameras and settings*

This usually takes 10–40 seconds. When it's done, the NVR shows **Online** with its cameras listed.

### Create a separate NVR user (recommended)

A dedicated user (e.g. `vmscloud`) keeps the admin password on site, and the owner can remove
cloud access at any time by deleting that user. Give it live view, playback and PTZ rights only.

| Brand | Where (menu paths vary slightly by firmware) |
|---|---|
| Hikvision | Configuration → System → User Management → Add (Operator). For PTZ also enable ONVIF: Configuration → Network → Advanced → Integration Protocol, and add the same user there. |
| Dahua | Main Menu → Account → Add User (group: user). |
| Uniview | Menu → System → User → Add (Operator). |

### Does the brand's cloud app need to share the device?

No. {app} connects to the NVR **locally through the site agent**, using the NVR's own login.
Hik-Connect, DMSS and EZCloud sharing settings don't apply, and the owner can keep using those
apps as before.

## If adding an NVR fails

The NVR page explains what went wrong and shows a button to fix it.

| Message | What to do |
|---|---|
| **Site agent is offline** | Check the agent device has power and internet. Retry when it shows Online. |
| **Agent can't reach the NVR** | Wrong IP, NVR off, or NVR on a different network/VLAN from the agent. Check the IP on the NVR's network menu. |
| **Wrong username or password** | Enter the correct NVR login in the form shown. Retry is blocked until you do, because NVRs lock the account after a few wrong attempts. |
| **Device didn't answer as expected** | The brand you picked doesn't match, or the HTTP port is wrong. |
| **Could not open a connection** | Usually temporary. Retry in a minute. |

**RTSP port is closed** appears as a warning, not an error: the NVR is added, but live video
won't work until RTSP is enabled on the NVR (usually Network → Port / RTSP).

## Watch live video

Open a site and click **Live view**.

- **Grid size:** choose 1, 4 or 9 cameras per page. Use the NVR filter if the site has several.
- **Click a camera** to open it full size. The grid pauses while you watch one camera, to save data.
- **SD / HD:** SD uses the camera's substream (less data). HD uses the main stream. If the
  browser can't play HD (some cameras use H.265), {app} switches back to SD by itself.
- **Full screen:** the corner button in the full-size view.
- **PTZ cameras:** press and hold the arrows to move, **+ / −** to zoom. Release to stop.
- **Real-time vs. a few seconds' delay:** the full-size view says *real-time* (WebRTC) or *HLS*.
  HLS is used automatically when the network or the camera's format needs it.

### Save mobile data

- **Low data** mode shows a still picture every few seconds instead of video: about
  0.7 MB per minute per camera instead of about 4.5 MB.
  It turns on by itself on slow (2G/3G) connections or when your phone's data saver is on.
- Only cameras on your screen play. Scroll away and they stop.
- Switching to another app or tab stops all video.
- The top of the page shows roughly how many MB per minute you're using.
- At the site, a camera only uploads while someone is watching it. 15 seconds after the last
  viewer leaves, it stops.

## Watching from anywhere

Yes: you can be anywhere with internet. The site agent keeps the site connected to the cloud,
and your phone or laptop connects to the cloud too. Quality depends on:

1. **The site's upload speed** (usually the limit),
2. **your own connection**, and
3. the site agent being online.

If a site loses power or internet, its card on **Sites** says why (for example *Power outage at
the site*) and when it started.

## Warnings on the NVR page

- **Firmware update needed** (red): the NVR's firmware has a known security problem. Update it from
  the manufacturer's website as soon as possible.
- **Main streams are H.265:** HD may not play in every browser. Setting the camera to H.264 fixes it.
- **Clock is off:** the NVR's time is wrong, which makes recordings hard to find. Turn on NTP in
  the NVR's time settings.
- **No substream:** that camera's grid tile uses snapshots to save data.

## Who can do what

| Role | Can do |
|---|---|
| Owner / Admin | Everything for their company: sites, agents, NVRs, users |
| Installer | Add clients, sites, agents and NVRs; watch; move PTZ |
| Operator | Watch and move PTZ cameras |
| Viewer | Watch only |

An installer company can manage all of its clients. A client only sees its own sites.

## Privacy

- NVR passwords are encrypted and never shown again, not even to administrators.
- Every time someone opens a camera, {app} records who and when (Data Privacy Act).
- Video links expire after 30 minutes and only work for the camera they were made for.
- Put up CCTV notices at the site; the owner remains responsible for that.
