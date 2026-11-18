/**
 * One camera player. WebRTC (WHEP) first for sub-second latency; HLS when the codec can't go
 * over WebRTC (H.265) or WebRTC can't connect (strict mobile carrier networks).
 * hls.js is loaded only when needed, to keep the first page load small on mobile data.
 */

export class StreamError extends Error {
    constructor(kind, message) { super(message); this.kind = kind; }   // kind: auth | codec | network | timeout | unsupported
}

const FIRST_FRAME_TIMEOUT_MS = 20000;

function parseIceLinks(header) {
    if (!header) return [];
    const out = [];
    const re = /<([^>]+)>;\s*rel="ice-server"(?:;\s*username="([^"]*)")?(?:;\s*credential="([^"]*)")?/g;
    let m;
    while ((m = re.exec(header))) out.push(m[2] ? { urls: m[1], username: m[2], credential: m[3] } : { urls: m[1] });
    return out;
}

function httpError(status) {
    if (status === 401 || status === 403) return new StreamError('auth', 'Access expired');
    if (status === 404) return new StreamError('network', 'Stream not available');
    return new StreamError('network', `Camera not reachable (${status})`);
}

function waitIceGathering(pc, ms) {
    if (pc.iceGatheringState === 'complete') return Promise.resolve();
    return new Promise((resolve) => {
        const t = setTimeout(resolve, ms);   // send what we have; server-side candidates do the rest
        pc.addEventListener('icegatheringstatechange', () => {
            if (pc.iceGatheringState === 'complete') { clearTimeout(t); resolve(); }
        });
    });
}

export class LivePlayer {
    /** @param {HTMLVideoElement} video  @param {(state:string, err?:StreamError, via?:string)=>void} onState */
    constructor(video, onState) {
        this.video = video;
        this.onState = onState;
        this.gen = 0;
        this.state = 'idle';
    }

    set(state, err, via) {
        this.state = state;
        this.onState?.(state, err, via);
    }

    async start(info) {
        this.stop(false);
        const gen = ++this.gen;
        this.set('connecting');
        let err;
        if (info.webrtc_ok && 'RTCPeerConnection' in window) {
            try { await this.startWhep(info.whep_url, gen); return; } catch (e) {
                if (gen !== this.gen) return;
                if (e.kind === 'auth') return this.set('error', e);
                err = e;
                this.teardown();
            }
        }
        try {
            await this.startHls(info.hls_url, gen);
        } catch (e) {
            if (gen !== this.gen) return;
            this.teardown();
            this.set('error', e.kind ? e : (err ?? new StreamError('network', 'Could not play')));
        }
    }

    // ---- WebRTC (WHEP) ----
    async startWhep(url, gen) {
        let ice = [];
        try { ice = parseIceLinks((await fetch(url, { method: 'OPTIONS' })).headers.get('Link')); } catch { /* optional */ }
        const pc = new RTCPeerConnection({ iceServers: ice });
        this.pc = pc;
        pc.addTransceiver('video', { direction: 'recvonly' });
        pc.addTransceiver('audio', { direction: 'recvonly' });
        pc.ontrack = (ev) => {
            if (ev.streams[0] && this.video.srcObject !== ev.streams[0]) this.video.srcObject = ev.streams[0];
        };
        pc.onconnectionstatechange = () => {
            if (gen !== this.gen) return;
            if (pc.connectionState === 'failed') this.dropped();
            if (pc.connectionState === 'disconnected') {
                clearTimeout(this.discTimer);   // brief blips are normal on mobile; give it 6 s
                this.discTimer = setTimeout(() => pc.connectionState !== 'connected' && gen === this.gen && this.dropped(), 6000);
            }
        };
        await pc.setLocalDescription(await pc.createOffer());
        await waitIceGathering(pc, 1500);
        const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/sdp' }, body: pc.localDescription.sdp });
        if (r.status !== 201) throw httpError(r.status);
        const loc = r.headers.get('Location');
        if (loc) {
            const s = new URL(loc, url);
            s.search = new URL(url).search;   // keep the viewer token for DELETE
            this.session = s.toString();
        }
        await pc.setRemoteDescription({ type: 'answer', sdp: await r.text() });
        await this.playAndWait(gen);
        if (gen === this.gen) this.set('live', undefined, 'webrtc');
    }

    // ---- HLS fallback ----
    async startHls(url, gen) {
        const { default: Hls } = await import('hls.js');
        if (gen !== this.gen) return;
        if (Hls.isSupported()) {
            // Auth: MediaMTX checks the ?vt= token on the first playlist request, then ties the rest
            // of the session to a cookie or, cross-origin without cookies, to ?session= in every child
            // URL (verified against MediaMTX v1.21). So request URLs exactly as the playlist gives them.
            const hls = new Hls({ lowLatencyMode: true, backBufferLength: 10 });
            this.hls = hls;
            const fatal = new Promise((_, reject) => {
                hls.on(Hls.Events.ERROR, (_e, d) => {
                    if (!d.fatal) return;
                    const codec = d.details === 'manifestIncompatibleCodecsError' || d.details === 'bufferIncompatibleCodecsError';
                    const err = codec ? new StreamError('codec', "This browser can't play this camera's video format (H.265)")
                        : (d.response?.code === 401 ? new StreamError('auth', 'Access expired') : new StreamError('network', 'Stream interrupted'));
                    if (this.state === 'live' && gen === this.gen) { this.teardown(); this.set('error', err); }
                    reject(err);
                });
            });
            fatal.catch(() => {});
            hls.loadSource(url);
            hls.attachMedia(this.video);
            await Promise.race([this.playAndWait(gen), fatal]);
        } else if (this.video.canPlayType('application/vnd.apple.mpegurl')) {
            this.video.src = url;       // older iOS: native HLS (session handling as above)
            await this.playAndWait(gen);
        } else {
            throw new StreamError('unsupported', "This browser can't play live video");
        }
        if (gen === this.gen) this.set('live', undefined, 'hls');
    }

    playAndWait(gen) {
        const v = this.video;
        v.muted = true;            // autoplay rules: must start muted
        v.playsInline = true;
        v.play().catch(() => {});
        return new Promise((resolve, reject) => {
            if (v.readyState >= 3 && !v.paused) return resolve();
            const t = setTimeout(() => { cleanup(); reject(new StreamError('timeout', 'Camera took too long to respond')); }, FIRST_FRAME_TIMEOUT_MS);
            const ok = () => { cleanup(); gen === this.gen ? resolve() : reject(new StreamError('network', 'stopped')); };
            const cleanup = () => { clearTimeout(t); v.removeEventListener('playing', ok); };
            v.addEventListener('playing', ok);
        });
    }

    dropped() {
        this.teardown();
        this.set('error', new StreamError('network', 'Connection lost'));
    }

    teardown() {
        clearTimeout(this.discTimer);
        if (this.pc) { this.pc.ontrack = this.pc.onconnectionstatechange = null; this.pc.close(); this.pc = null; }
        if (this.session) { fetch(this.session, { method: 'DELETE' }).catch(() => {}); this.session = null; }
        if (this.hls) { this.hls.destroy(); this.hls = null; }
        const v = this.video;
        v.srcObject = null;
        if (v.getAttribute('src')) { v.removeAttribute('src'); v.load(); }
    }

    stop(emit = true) {
        this.gen++;
        this.teardown();
        if (emit) this.set('stopped');
    }
}

export const _test = { parseIceLinks };
