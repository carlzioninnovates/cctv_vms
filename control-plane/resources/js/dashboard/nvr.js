import { BRANDS, errorMessage, FIX, nvrStatus, ONBOARDING_STEPS, poll, timeAgo } from './ui';

export function nvrPage(nvrId) {
    return {
        loading: true, error: null, nvr: null,
        busy: false, actionError: null,
        creds: { username: 'admin', password: '' }, showPassword: false,
        elapsed: 0,
        BRANDS, FIX, nvrStatus, timeAgo, ONBOARDING_STEPS,

        async init() {
            await this.load();
            this.loading = false;
            poll(() => this.load(), 3000, () => this.nvr?.status === 'onboarding');
            setInterval(() => { if (this.nvr?.status === 'onboarding') this.elapsed++; }, 1000);
        },
        async load() {
            try {
                const prev = this.nvr?.status;
                this.nvr = (await axios.get(`/api/nvrs/${nvrId}`)).data;
                if (prev === 'onboarding' && this.nvr.status !== 'onboarding') this.elapsed = 0;
                this.error = null;
            } catch (e) { this.error = errorMessage(e); }
        },
        get fix() { return this.nvr?.error ? (FIX[this.nvr.error.code] ?? FIX.internal_error) : null; },
        get slow() { return this.elapsed > 45; },
        stepState(key) {
            const order = ONBOARDING_STEPS.map(s => s.key);
            const cur = order.indexOf(this.nvr?.stage);
            const i = order.indexOf(key);
            if (cur === -1) return i === 0 ? 'waiting' : 'todo';   // queued, job not started yet
            return i < cur ? 'done' : (i === cur ? 'active' : 'todo');
        },

        async retry() {
            this.busy = true; this.actionError = null;
            try { this.nvr = (await axios.post(`/api/nvrs/${nvrId}/retry`)).data; this.restartPolling(); }
            catch (e) { this.actionError = errorMessage(e); }
            this.busy = false;
        },
        async saveCredentials() {
            this.busy = true; this.actionError = null;
            try {
                this.nvr = (await axios.put(`/api/nvrs/${nvrId}/credentials`, this.creds)).data;
                this.creds.password = '';
                this.restartPolling();
            } catch (e) { this.actionError = errorMessage(e); }
            this.busy = false;
        },
        restartPolling() {
            this.elapsed = 0;
            poll(() => this.load(), 3000, () => this.nvr?.status === 'onboarding');
        },
        codecLabel(c) {
            return { h264: 'H.264', h265: 'H.265', mjpeg: 'MJPEG', none: '—', unknown: '?' }[c] ?? c;
        },
        viewModeLabel(m) {
            return { sub: 'Substream', main: 'Main stream', snapshot: 'Snapshots (low data)' }[m] ?? m;
        },
    };
}
