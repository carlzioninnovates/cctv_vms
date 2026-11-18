import { errorMessage, OFFLINE_CAUSE } from './ui';

export function sitesPage() {
    return {
        loading: true, error: null, q: '',
        sites: [], orgs: [], me: null,
        showNewSite: false, showNewClient: false, saving: false, formError: null,
        newSite: { organization_id: '', name: '', address: '', isp: '', has_ups: false },
        newClient: { name: '' },
        OFFLINE_CAUSE,

        async init() {
            try {
                const [me, orgs] = await Promise.all([axios.get('/api/user'), axios.get('/api/organizations')]);
                this.me = me.data;
                this.orgs = orgs.data.organizations;
                await this.load();
            } catch (e) { this.error = errorMessage(e); }
            this.loading = false;
        },
        async load() {
            const r = await axios.get('/api/sites', { params: { q: this.q || undefined } });
            this.sites = r.data.sites;
        },
        get isInstallerCompany() { return ['reseller', 'platform'].includes(this.me?.organization?.type); },
        get clientOrgs() {
            return this.orgs.filter(o => this.isInstallerCompany ? o.id !== this.me.organization_id : true);
        },
        get groups() {
            const by = {};
            for (const s of this.sites) (by[s.organization.name] ??= []).push(s);
            return Object.entries(by).sort(([a], [b]) => a.localeCompare(b));
        },
        siteHealth(s) {
            if (s.offline_cause) return { cls: 'text-amber-700', text: OFFLINE_CAUSE[s.offline_cause] };
            if (s.agents.total === 0) return { cls: 'text-slate-500', text: 'Agent not installed' };
            if (s.agents.online === 0) return { cls: 'text-slate-500', text: 'Waiting for agent to connect' };
            if (s.nvrs.failed) return { cls: 'text-red-600', text: `${s.nvrs.failed} NVR needs fixing` };
            if (s.nvrs.total === 0) return { cls: 'text-slate-500', text: 'No NVRs yet' };
            return { cls: 'text-emerald-700', text: `${s.nvrs.active}/${s.nvrs.total} NVRs online` };
        },
        openNewSite() {
            this.formError = null;
            this.newSite.organization_id = this.clientOrgs[0]?.id ?? '';
            this.showNewSite = true;
        },
        async createSite() {
            this.saving = true; this.formError = null;
            try {
                const { organization_id, ...body } = this.newSite;
                const r = await axios.post(`/api/organizations/${organization_id}/sites`, body);
                window.location.href = `/sites/${r.data.id}`;
            } catch (e) { this.formError = errorMessage(e); }
            this.saving = false;
        },
        async createClient() {
            this.saving = true; this.formError = null;
            try {
                const r = await axios.post('/api/organizations', this.newClient);
                this.orgs.push({ ...r.data, sites_count: 0 });
                this.newClient.name = '';
                this.showNewClient = false;
                this.openNewSite();
                this.newSite.organization_id = r.data.id;
            } catch (e) { this.formError = errorMessage(e); }
            this.saving = false;
        },
    };
}
