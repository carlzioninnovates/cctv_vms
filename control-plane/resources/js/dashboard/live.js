import Alpine from 'alpinejs';
import { errorMessage, poll } from './ui';
import { LivePlayer } from './player';

const SUB_MBPS = 0.6;     // typical NVR substream
const MAIN_MBPS = 3;
const SNAP_KB = 60;

function slowConnection() {
    const c = navigator.connection;
    return !!c && (c.saveData || ['slow-2g', '2g', '3g'].includes(c.effectiveType));
}

/** Page: grid of cameras for a site. Shared state lives in Alpine.store('live'). */
export function livePage(siteId) {
    return {
        loading: true, error: null, site: null, cameras: [], canPtz: false,
        page: 0, nvrFilter: '',

        async init() {
            const narrow = window.matchMedia('(max-width: 640px)').matches;
            Alpine.store('live', {
                mode: slowConnection() ? 'lowdata' : 'live',
                layout: narrow ? 1 : 4,
                focused: null,
                hidden: document.hidden,
                siteId,
            });
            document.addEventListener('visibilitychange', () => { this.store.hidden = document.hidden; });
            try {
                const r = (await axios.get(`/api/sites/${siteId}/cameras`)).data;
                this.site = r.site; this.cameras = r.cameras; this.canPtz = r.can_ptz;
                if (this.cameras.length && this.cameras.every(c => c.default_view_mode === 'snapshot')) this.store.mode = 'lowdata';
            } catch (e) { this.error = errorMessage(e); }
            this.loading = false;
            // Snapshot links are signed for 30 min; refresh the list before they expire
            poll(async () => {
                const r = (await axios.get(`/api/sites/${siteId}/cameras`)).data;
                const byId = Object.fromEntries(r.cameras.map(c => [c.id, c]));
                this.cameras.forEach(c => { if (byId[c.id]) c.snapshot = byId[c.id].snapshot; });
            }, 20 * 60 * 1000);
            window.addEventListener('keydown', (e) => { if (e.key === 'Escape' && this.store.focused) this.store.focused = null; });
        },
        get store() { return Alpine.store('live'); },
        get nvrs() { return [...new Map(this.cameras.map(c => [c.nvr_id, c.nvr_name])).entries()]; },
        get filtered() { return this.cameras.filter(c => !this.nvrFilter || c.nvr_id === +this.nvrFilter); },
        get pages() { return Math.max(1, Math.ceil(this.filtered.length / this.store.layout)); },
        get visible() {
            const p = Math.min(this.page, this.pages - 1);
            return this.filtered.slice(p * this.store.layout, (p + 1) * this.store.layout);
        },
        get gridClass() {
            return { 1: 'grid-cols-1', 4: 'grid-cols-1 sm:grid-cols-2', 9: 'grid-cols-2 sm:grid-cols-3' }[this.store.layout];
        },
        get dataHint() {
            const n = this.visible.length;
            if (!n) return '';
            if (this.store.mode === 'lowdata') {
                const perMin = this.visible.reduce((s, c) => s + 60 / c.snapshot_interval, 0) * SNAP_KB / 1024;
                return `≈ ${perMin.toFixed(1)} MB/min`;
            }
            return `≈ ${(n * SUB_MBPS * 60 / 8).toFixed(0)} MB/min`;
        },
        setLayout(n) { this.store.layout = n; this.page = 0; },
        focus(cam) { this.store.focused = cam; },
        get focusedHint() { return `≈ ${(MAIN_MBPS * 60 / 8).toFixed(0)} MB/min in HD`; },
    };
}

/** One tile (grid) or the focused view. Starts/stops its stream as visibility and mode change. */
export function cameraTile(cam, opts = {}) {
    return {
        cam,
        focusView: !!opts.focus,
        quality: opts.focus && !slowConnection() && cam.main_codec === 'h264' ? 'main' : 'sub',
        state: 'idle', via: null, errorText: null, errorKind: null,
        snapTick: Date.now(), snapFailed: false,
        player: null, retryIn: 0, attempts: 0, inView: false,
        _timers: [],

        init() {
            this.player = new LivePlayer(this.$refs.video, (state, err, via) => {
                this.state = state; this.via = via ?? this.via;
                if (state === 'live') { this.attempts = 0; this.errorText = null; }
                if (state === 'error') this.onError(err);
            });
            const io = new IntersectionObserver(([e]) => { this.inView = e.isIntersecting; this.sync(); }, { threshold: 0.1 });
            io.observe(this.$el);
            this._io = io;
            this.$watch('$store.live.mode', () => this.sync());
            this.$watch('$store.live.hidden', () => this.sync());
            this.$watch('$store.live.focused', () => this.sync());
            this._timers.push(setInterval(() => {
                if (this.shouldSnapshot) this.snapTick = Date.now();
            }, Math.max(2, cam.snapshot_interval) * 1000));
        },
        destroy() {
            this._io?.disconnect();
            this._timers.forEach(clearInterval);
            clearTimeout(this._retry);
            this.player?.stop(false);
        },

        get store() { return Alpine.store('live'); },
        /** Grid tiles pause while a camera is open full-size, to save data. */
        get active() {
            if (this.store.hidden || !this.inView) return false;
            return this.focusView ? true : !this.store.focused;
        },
        get shouldStream() {
            return this.active && this.cam.nvr_online && (this.focusView || this.store.mode === 'live');
        },
        get shouldSnapshot() { return this.active && !this.shouldStream && this.cam.nvr_online; },
        get snapshotSrc() {
            return this.cam.snapshot.url + '&t=' + this.snapTick;
        },

        sync() {
            if (this.shouldStream) {
                if (['idle', 'stopped'].includes(this.state)) this.connect();
            } else if (!['idle', 'stopped'].includes(this.state)) {
                clearTimeout(this._retry);
                this.player.stop();
            }
        },
        async connect() {
            clearTimeout(this._retry);
            this.state = 'connecting'; this.errorText = null;
            try {
                const info = (await axios.post(`/api/channels/${this.cam.id}/stream`, { quality: this.quality })).data;
                if (!this.shouldStream) return this.player.stop();
                await this.player.start(info);
            } catch (e) {
                this.onError({ kind: e.response?.status === 409 ? 'offline' : 'network', message: errorMessage(e) });
            }
        },
        onError(err) {
            this.state = 'error';
            this.errorKind = err?.kind;
            this.errorText = err?.message || 'Could not play';
            if (err?.kind === 'codec' && this.quality === 'main') {   // HD is H.265: drop to SD automatically
                this.quality = 'sub';
                this.errorText = null;
                return this.connect();
            }
            if (['codec', 'unsupported'].includes(err?.kind) || !this.shouldStream) return;
            // Reconnect with backoff: 2, 4, 8 … 30 s (auth errors fetch a fresh token on retry)
            this.attempts++;
            const wait = Math.min(30, 2 ** this.attempts);
            this.retryIn = wait;
            const tick = () => {
                if (!this.shouldStream) return;
                if (--this.retryIn <= 0) return this.connect();
                this._retry = setTimeout(tick, 1000);
            };
            this._retry = setTimeout(tick, 1000);
        },
        retryNow() { this.attempts = 0; this.connect(); },
        setQuality(q) {
            if (q === this.quality) return;
            this.quality = q;
            if (this.shouldStream) this.connect();
        },
        fullscreen() {
            const el = this.$root;
            (document.fullscreenElement ? document.exitFullscreen() : el.requestFullscreen?.())?.catch?.(() => {});
        },

        // ---- PTZ: press and hold ----
        async ptz(action) {
            try { await axios.post(`/api/channels/${this.cam.id}/ptz`, { action, speed: 4 }); }
            catch (e) { this.errorText = errorMessage(e); }
        },
        ptzStart(action, ev) { ev?.preventDefault(); this._moving = action; this.ptz(action); },
        ptzStop() { if (this._moving) { this._moving = null; this.ptz('stop'); } },
    };
}
