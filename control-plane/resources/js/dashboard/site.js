import { agentStatus, BRANDS, copy, countdown, errorMessage, nvrStatus, OFFLINE_CAUSE, poll, timeAgo } from './ui';

export function sitePage(siteId) {
    return {
        loading: true, error: null, site: null,
        // agent setup
        enrollment: null, creatingAgent: false, copied: null,
        // scan
        scanning: false, scanError: null, devices: null,
        // add nvr
        showAdd: false, saving: false, formError: null, showPassword: false, showPorts: false, showPrep: true,
        form: null,
        BRANDS, OFFLINE_CAUSE, agentStatus, nvrStatus, timeAgo, countdown,
        now: Date.now(),

        async init() {
            this.resetForm();
            await this.load();
            this.loading = false;
            poll(() => this.load(), 5000, () => this.needsPolling);
            setInterval(() => { this.now = Date.now(); }, 30000);
        },
        async load() {
            try {
                this.site = (await axios.get(`/api/sites/${siteId}`)).data;
                this.error = null;
            } catch (e) { this.error = errorMessage(e); }
        },
        get agent() { return this.site?.agents[0] ?? null; },
        get agentReady() { return this.agent?.status === 'online'; },
        get ipLooksValid() {
            const v = this.form.lan_host.trim();
            if (!v) return true;
            const m = v.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/);
            return m ? m.slice(1).every(n => +n <= 255) : /^[A-Za-z0-9.-]+$/.test(v);
        },
        get needsPolling() {
            return !this.site || !this.agentReady || this.site.nvrs.some(n => n.status === 'onboarding');
        },
        get step() {
            if (!this.agentReady) return 1;
            return this.site.nvrs.some(n => n.status === 'active') ? 3 : 2;
        },

        // ---- agent ----
        async createAgent() {
            this.creatingAgent = true;
            try {
                this.enrollment = (await axios.post(`/api/sites/${siteId}/agents`, { name: 'Site agent' })).data;
                await this.load();
            } catch (e) { this.error = errorMessage(e); }
            this.creatingAgent = false;
        },
        async newCode() {
            if (this.agent.status !== 'pending' && !confirm('This disconnects the current agent until it is enrolled again. Continue?')) return;
            this.creatingAgent = true;
            try {
                const r = (await axios.post(`/api/agents/${this.agent.id}/enrollment-code`)).data;
                this.enrollment = r;
                await this.load();
            } catch (e) { this.error = errorMessage(e); }
            this.creatingAgent = false;
        },
        async copyText(key, text) {
            if (await copy(text)) { this.copied = key; setTimeout(() => (this.copied = null), 1500); }
        },

        // ---- scan ----
        async scan() {
            this.scanning = true; this.scanError = null; this.devices = null;
            try {
                this.devices = (await axios.post(`/api/agents/${this.agent.id}/discover`)).data.devices;
            } catch (e) { this.scanError = errorMessage(e); }
            this.scanning = false;
        },

        // ---- add NVR ----
        resetForm() {
            this.form = { name: '', brand: 'hikvision', lan_host: '', username: '', password: '',
                http_port: 80, rtsp_port: 554, onvif_port: 80, owner_consent: false };
        },
        openAdd(device = null) {
            this.resetForm();
            this.formError = null;
            if (device) {
                this.form.lan_host = device.ip;
                this.form.brand = device.brand_guess in BRANDS ? device.brand_guess : 'onvif';
                this.form.name = device.name || device.hardware || '';
            }
            this.setBrand(this.form.brand);
            this.showAdd = true;
            this.$nextTick(() => this.$refs.firstField?.focus());
        },
        setBrand(b) {
            this.form.brand = b;
            Object.assign(this.form, {
                http_port: BRANDS[b].ports.http, rtsp_port: BRANDS[b].ports.rtsp, onvif_port: BRANDS[b].ports.onvif,
            });
        },
        async addNvr() {
            this.saving = true; this.formError = null;
            try {
                const body = { ...this.form, edge_agent_id: this.agent.id };
                if (!body.name) body.name = `${BRANDS[body.brand].label} NVR`;
                const r = await axios.post(`/api/sites/${siteId}/nvrs`, body);
                window.location.href = `/nvrs/${r.data.id}`;
            } catch (e) { this.formError = errorMessage(e); this.saving = false; }
        },
    };
}
