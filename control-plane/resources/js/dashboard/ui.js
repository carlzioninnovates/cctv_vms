/** Shared helpers and copy for the installer dashboard. */

/** Per-brand setup help. Menu paths differ a little between firmware versions; these are the usual ones. */
export const BRANDS = {
    hikvision: { label: 'Hikvision', ports: { http: 80, rtsp: 554, onvif: 80 },
        ip: 'On the NVR: Menu → Configuration → Network → General (or the web page: Configuration → Network → Basic Settings → TCP/IP).',
        account: 'Configuration → System → User Management → Add. Level: Operator. Allow Live View, Playback and PTZ.',
        tip: 'For PTZ: also enable ONVIF (Configuration → Network → Advanced → Integration Protocol) and add the same user there.' },
    dahua: { label: 'Dahua', ports: { http: 80, rtsp: 554, onvif: 80 },
        ip: 'On the NVR: Main Menu → Network → TCP/IP.',
        account: 'Main Menu → Account → Add User. Group: user. Allow Live, Playback and PTZ.',
        tip: 'Set each camera\'s substream (Extra Stream) to H.264 for smooth viewing on phones.' },
    uniview: { label: 'Uniview', ports: { http: 80, rtsp: 554, onvif: 80 },
        ip: 'On the NVR: Menu → System → Network → Basic (TCP/IP).',
        account: 'Menu → System → User → Add. Type: Operator. Allow Live View, Playback and PTZ.',
        tip: 'ONVIF is usually on by default. The EZCloud/app sharing settings don\'t matter — we connect locally.' },
    onvif: { label: 'Other (ONVIF)', ports: { http: 80, rtsp: 554, onvif: 80 },
        ip: 'Check the NVR\'s network settings, or use Scan network.',
        account: 'Create a user with live view, playback and PTZ rights, and make sure ONVIF is enabled for it.',
        tip: 'Any brand with ONVIF enabled. Some budget NVRs support live view only.' },
};

/** Onboarding progress shown while an NVR connects (matches OnboardNvr job stages). */
export const ONBOARDING_STEPS = [
    { key: 'tunnel', label: 'Connecting through the site agent' },
    { key: 'probe', label: 'Logging in to the NVR and reading cameras' },
    { key: 'save', label: 'Saving cameras and settings' },
];

const NVR_STATUS = {
    active:     { label: 'Online',      cls: 'bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500' },
    onboarding: { label: 'Connecting',  cls: 'bg-sky-50 text-sky-700',         dot: 'bg-sky-500 animate-pulse' },
    failed:     { label: 'Needs fixing', cls: 'bg-red-50 text-red-700',         dot: 'bg-red-500' },
    offline:    { label: 'Offline',     cls: 'bg-amber-50 text-amber-800',     dot: 'bg-amber-500' },
    pending:    { label: 'Not set up',  cls: 'bg-slate-100 text-slate-600',    dot: 'bg-slate-400' },
};
const AGENT_STATUS = {
    online:   { label: 'Online',          cls: 'bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500' },
    enrolled: { label: 'Starting up',     cls: 'bg-sky-50 text-sky-700',         dot: 'bg-sky-500 animate-pulse' },
    offline:  { label: 'Offline',         cls: 'bg-amber-50 text-amber-800',     dot: 'bg-amber-500' },
    pending:  { label: 'Waiting for install', cls: 'bg-slate-100 text-slate-600', dot: 'bg-slate-400 animate-pulse' },
};
export const nvrStatus = (s) => NVR_STATUS[s] ?? NVR_STATUS.pending;
export const agentStatus = (s) => AGENT_STATUS[s] ?? AGENT_STATUS.pending;

export const OFFLINE_CAUSE = {
    power_loss: 'Power outage at the site',
    site_internet_down: 'Site internet is down',
    site_unreachable: 'Site is unreachable (power or internet)',
    nvr_unreachable: 'NVR not responding',
};

/** What the installer should do for each onboarding failure. */
export const FIX = {
    agent_offline:   { title: 'Site agent is offline', action: 'retry',
        steps: ['Check the agent device has power and internet.', 'Wait for it to show Online, then retry.'] },
    tunnel_failed:   { title: 'Could not open a connection', action: 'retry',
        steps: ['This is usually temporary. Retry in a minute.'] },
    lan_unreachable: { title: "Agent can't reach the NVR", action: 'retry',
        steps: ['Check the NVR IP address (on the NVR: Network settings).', 'Make sure the NVR and agent are on the same network / VLAN.', 'Check the NVR is powered on.'] },
    bad_credentials: { title: 'Wrong username or password', action: 'credentials',
        steps: ['Enter the correct NVR login below.', 'Retry stays blocked until you save a new password.'] },
    probe_failed:    { title: "Device didn't answer as expected", action: 'retry',
        steps: ['Check the brand you picked matches the NVR.', 'Check the HTTP port (default 80).'] },
    internal_error:  { title: 'Something went wrong on our side', action: 'retry',
        steps: ['Retry. If it happens again, contact support.'] },
};

export function errorMessage(err) {
    const r = err?.response;
    if (!r) return 'No connection to the server. Check your internet and try again.';
    if (r.status === 422 && r.data?.errors) return Object.values(r.data.errors).flat().join(' ');
    return r.data?.message || `Request failed (${r.status}).`;
}

export function timeAgo(iso) {
    if (!iso) return 'never';
    const s = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
    if (s < 60) return `${s}s ago`;
    if (s < 3600) return `${Math.round(s / 60)} min ago`;
    if (s < 86400) return `${Math.round(s / 3600)} h ago`;
    return `${Math.round(s / 86400)} d ago`;
}

export function countdown(iso) {
    const s = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    if (s <= 0) return 'expired';
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
    return h ? `${h}h ${m}m left` : `${m} min left`;
}

export async function copy(text) {
    try { await navigator.clipboard.writeText(text); return true; } catch { return false; }
}

/** Poll fn every ms while keepGoing() is true; stops when the page is hidden to save phone data. */
export function poll(fn, ms, keepGoing = () => true) {
    let timer;
    const tick = async () => {
        if (!keepGoing()) return;
        if (!document.hidden) { try { await fn(); } catch { /* next tick */ } }
        timer = setTimeout(tick, ms);
    };
    timer = setTimeout(tick, ms);
    return () => clearTimeout(timer);
}
