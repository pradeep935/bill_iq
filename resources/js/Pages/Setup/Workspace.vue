<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import Layout from '../Layout.vue';

const props = defineProps({
    page: String,
    title: String,
    initial_tab: { type: String, default: 'admin' },
    summary: { type: Array, default: () => [] },
    activeSection: { type: String, default: 'admin' },
    sections: { type: Array, default: () => ['admin', 'staff', 'onboarding', 'users', 'settings'] },
    metrics: { type: Array, default: () => [] },
    permissions: { type: Object, default: () => ({}) },
    routes: { type: Object, default: () => ({}) },
    emptyStates: { type: Object, default: () => ({}) },
    businessProfile: { type: Object, default: () => ({}) },
});

const refreshing = ref(false);
const changingSection = ref('');
const savingProfile = ref(false);
const profileErrors = ref({});
const profileSaved = ref('');
const profileForm = reactive({
    name: props.businessProfile.name || '',
    gstin: props.businessProfile.gstin || '',
    state: props.businessProfile.state || '',
    financial_year: props.businessProfile.financial_year || '',
    address: props.businessProfile.address || '',
    logo_path: props.businessProfile.logo_path || '',
    logo_url: props.businessProfile.logo_url || '',
    show_logo_on_invoice: props.businessProfile.show_logo_on_invoice ?? true,
    phone: props.businessProfile.phone || '',
    email: props.businessProfile.email || '',
    bank_name: props.businessProfile.bank_name || '',
    bank_account_number: props.businessProfile.bank_account_number || '',
    bank_ifsc: props.businessProfile.bank_ifsc || '',
    bank_account_holder: props.businessProfile.bank_account_holder || '',
    invoice_terms: props.businessProfile.invoice_terms || '',
    default_print_format: props.businessProfile.default_print_format || 'a4',
});

const cards = computed(() => props.summary || []);
const rows = computed(() => props.metrics || []);
const active = computed(() => props.activeSection || props.initial_tab || 'admin');
const logoUploading = ref(false);
const logoPreview = computed(() => {
    const path = profileForm.logo_url || profileForm.logo_path || '';
    if (!path) return '';
    if (String(path).startsWith('http') || String(path).startsWith('/')) return path;
    return `/storage/${String(path).replace(/^\/+/, '')}`;
});
const titleCopy = computed(() => {
    if (active.value === 'staff') return 'Staff setup metrics and employee access health.';
    if (active.value === 'onboarding') return 'Setup progress for the current business.';
    if (active.value === 'users') return 'Application user and role readiness.';
    if (active.value === 'saas') return 'Subscription and module usage for this business.';
    if (active.value === 'settings') return 'Configuration areas and availability.';
    return 'Operational setup metrics connected to real business data.';
});

const routeUrl = (name, fallback = '#') => props.routes?.[name]?.url || fallback;
const sectionUrl = (section) => `${routeUrl('admin.workspace', '/app/admin/workspace')}?section=${section}`;

const refresh = () => {
    refreshing.value = true;
    router.reload({
        only: ['summary', 'metrics', 'emptyStates'],
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            refreshing.value = false;
        },
    });
};

const openSection = (section) => {
    if (section === active.value) return;
    changingSection.value = section;
    router.visit(sectionUrl(section), {
        preserveScroll: true,
        preserveState: false,
        onFinish: () => {
            changingSection.value = '';
        },
    });
};

const saveBusinessProfile = async () => {
    savingProfile.value = true;
    profileErrors.value = {};
    profileSaved.value = '';
    try {
        const response = await axios.post(routeUrl('app.setup.settings.business-profile', '/app/setup/settings/business-profile'), profileForm);
        Object.assign(profileForm, response.data.businessProfile || profileForm);
        profileSaved.value = response.data.message || 'Business profile saved.';
    } catch (error) {
        if (error.response?.status === 422) {
            profileErrors.value = error.response.data.errors || {};
            return;
        }
        profileErrors.value = { general: [error.response?.data?.message || 'Business profile save nahi ho saka.'] };
    } finally {
        savingProfile.value = false;
    }
};

const uploadLogo = async (event) => {
    const file = event.target.files?.[0];
    if (!file) return;

    logoUploading.value = true;
    profileErrors.value = {};
    profileSaved.value = '';

    try {
        const formData = new FormData();
        formData.append('file', file);
        const response = await axios.post('/uploads/file', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        profileForm.logo_path = response.data.path || '';
        profileForm.logo_url = response.data.url || '';
    } catch (error) {
        profileErrors.value = { logo_path: [error.response?.data?.message || 'Logo upload nahi ho saka.'] };
    } finally {
        logoUploading.value = false;
        event.target.value = '';
    }
};

const removeLogo = () => {
    profileForm.logo_path = '';
    profileForm.logo_url = '';
};

const statusClass = (status) => String(status || '').toLowerCase().replace(/\s+/g, '-');
const showEmpty = computed(() => Object.values(props.emptyStates || {}).some((item) => item?.show));
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title">
                <span>SETUP</span>
                <h1>{{ title }}</h1>
                <p>{{ titleCopy }}</p>
            </div>
        </template>

        <section class="workspace-toolbar">
            <button type="button" :disabled="refreshing" @click="refresh">{{ refreshing ? 'Refreshing...' : 'Refresh' }}</button>
            <a :href="routeUrl('admin.masters.index', '/app/setup/masters')">Open Masters</a>
            <a v-if="permissions['employees.view']" :href="routeUrl('admin.employees.index', '/app/setup/employees')">Employees</a>
        </section>

        <section class="workspace-summary">
            <a
                v-for="card in cards"
                :key="card.key"
                class="workspace-card"
                :class="[`tone-${card.tone || 'info'}`, { disabled: !card.enabled }]"
                :href="card.enabled ? card.href : null"
            >
                <span>{{ card.label }}</span>
                <strong>{{ Number(card.value || 0).toLocaleString('en-IN') }}</strong>
                <small v-if="Number(card.value || 0) === 0">{{ card.empty }}</small>
            </a>
        </section>

        <section v-if="showEmpty" class="workspace-empty-grid">
            <article v-for="item in emptyStates" :key="item.message" v-show="item.show" class="workspace-empty">
                <strong>{{ item.message }}</strong>
                <a v-if="item.enabled && item.href" :href="item.href">{{ item.action }}</a>
                <span v-else>Coming Soon</span>
            </article>
        </section>

        <section class="workspace-panel">
            <div class="workspace-head">
                <div>
                    <h2>{{ title }}</h2>
                    <p>{{ titleCopy }}</p>
                </div>
                <div class="tabs">
                    <button
                        v-for="section in sections"
                        :key="section"
                        type="button"
                        :class="{ active: active === section }"
                        :disabled="Boolean(changingSection)"
                        @click="openSection(section)"
                    >
                        {{ changingSection === section ? 'Loading...' : section }}
                    </button>
                </div>
            </div>

            <div class="workspace-table-wrap">
                <table class="workspace-table">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th>Value</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id || row.metric">
                            <td>{{ row.metric }}</td>
                            <td>{{ row.value }}</td>
                            <td><span class="metric-status" :class="statusClass(row.status)">{{ row.status }}</span></td>
                            <td>
                                <a v-if="row.enabled && row.href" class="workspace-action" :href="row.href">{{ row.action || 'Manage' }}</a>
                                <span v-else class="workspace-muted">{{ row.action === 'Coming Soon' ? 'Coming Soon' : 'Unavailable' }}</span>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="4" class="workspace-empty-cell">No metrics available for this section.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="active === 'settings'" class="settings-panel">
            <div class="settings-head">
                <div>
                    <span>BUSINESS SETTINGS</span>
                    <h2>Business Profile & Invoice Print</h2>
                    <p>Name, address, GSTIN, bank details and default print format invoice/report print par use honge.</p>
                </div>
                <button type="button" :disabled="savingProfile" @click="saveBusinessProfile">{{ savingProfile ? 'Saving...' : 'Save Settings' }}</button>
            </div>

            <div class="settings-grid">
                <label><span>Business Name</span><input v-model="profileForm.name" placeholder="Business name" /></label>
                <label><span>GST Number</span><input v-model="profileForm.gstin" maxlength="15" placeholder="15-character GSTIN" /></label>
                <div class="logo-field span-2">
                    <span>Business Logo / Image</span>
                    <div class="logo-upload-row">
                        <div class="logo-preview">
                            <img v-if="logoPreview" :src="logoPreview" alt="Business logo" />
                            <strong v-else>{{ (profileForm.name || 'BI').slice(0, 2).toUpperCase() }}</strong>
                        </div>
                        <div class="logo-actions">
                            <input type="file" accept="image/*" :disabled="logoUploading" @change="uploadLogo" />
                            <small>{{ logoUploading ? 'Uploading...' : 'Invoice print header mein logo/image use hoga.' }}</small>
                            <button v-if="profileForm.logo_path" type="button" @click="removeLogo">Remove Logo</button>
                        </div>
                    </div>
                </div>
                <label><span>State</span><input v-model="profileForm.state" placeholder="State" /></label>
                <label><span>Financial Year</span><input v-model="profileForm.financial_year" placeholder="2026-27" /></label>
                <label><span>Phone</span><input v-model="profileForm.phone" placeholder="Phone number" /></label>
                <label><span>Email</span><input v-model="profileForm.email" type="email" placeholder="Email address" /></label>
                <label class="span-2"><span>Address</span><textarea v-model="profileForm.address" placeholder="Business address printed on invoice"></textarea></label>
                <label><span>Bank Name</span><input v-model="profileForm.bank_name" placeholder="Bank name" /></label>
                <label><span>Account Number</span><input v-model="profileForm.bank_account_number" placeholder="Account number" /></label>
                <label><span>IFSC Code</span><input v-model="profileForm.bank_ifsc" placeholder="IFSC code" /></label>
                <label><span>Account Holder</span><input v-model="profileForm.bank_account_holder" placeholder="Account holder name" /></label>
                <label><span>Default Print</span><select v-model="profileForm.default_print_format"><option value="a4">A4 Invoice</option><option value="thermal">80mm Thermal</option></select></label>
                <label class="check-field"><input v-model="profileForm.show_logo_on_invoice" type="checkbox" /><span>Show logo on A4 invoice</span></label>
                <label class="span-2"><span>Terms & Conditions</span><textarea v-model="profileForm.invoice_terms" placeholder="Terms shown at invoice footer"></textarea></label>
            </div>

            <div v-if="Object.keys(profileErrors).length" class="settings-error">
                <span v-for="(messages, field) in profileErrors" :key="field">{{ messages[0] }}</span>
            </div>
            <div v-if="profileSaved" class="settings-success">{{ profileSaved }}</div>
        </section>
    </Layout>
</template>

<style scoped>
.workspace-toolbar{display:flex;gap:10px;justify-content:flex-end;margin:-4px 0 14px}.workspace-toolbar button,.workspace-toolbar a,.workspace-action,.workspace-empty a{align-items:center;background:#fff;border:1px solid #d8e0eb;border-radius:8px;color:#344159;display:inline-flex;font-size:12px;font-weight:800;min-height:38px;padding:8px 10px;text-decoration:none}.workspace-toolbar button:disabled,.tabs button:disabled{cursor:not-allowed;opacity:.65}.workspace-summary{display:grid;gap:12px;grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:16px}.workspace-card{background:#fff;border:1px solid #dfe6ef;border-left:4px solid #2563eb;border-radius:8px;box-shadow:0 8px 22px rgba(25,50,84,.035);color:inherit;min-height:92px;padding:13px 14px;text-decoration:none}.workspace-card.disabled{opacity:.65;pointer-events:none}.workspace-card span{color:#7f8da4;display:block;font-size:11px;font-weight:800;margin-bottom:7px}.workspace-card strong{color:#142139;display:block;font-size:22px;font-weight:900;line-height:1.1}.workspace-card small{color:#8490a2;display:block;margin-top:8px}.tone-good{border-left-color:#22c55e}.tone-warn{border-left-color:#f59e0b}.tone-money{border-left-color:#14b8a6}.workspace-empty-grid{display:grid;gap:12px;grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:16px}.workspace-empty{align-items:center;background:#fff;border:1px dashed #cad5e3;border-radius:8px;display:flex;gap:12px;justify-content:space-between;padding:14px}.workspace-empty strong{color:#344159;font-size:13px}.workspace-empty span,.workspace-muted{color:#8490a2;font-size:12px;font-weight:800}.workspace-panel{background:#fff;border:1px solid #dfe6ef;border-radius:8px;margin-top:18px;padding:18px}.workspace-head{align-items:flex-start;display:flex;gap:14px;justify-content:space-between;margin-bottom:14px}.workspace-head h2{color:#142139;font-size:18px;margin:0}.workspace-head p{color:#758197;font-size:12px;margin:4px 0 0}.tabs{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end}.tabs button{align-items:center;background:#fff;border:1px solid #d8e0eb;border-radius:8px;color:#344159;display:inline-flex;font-size:12px;font-weight:800;min-height:38px;padding:8px 10px;text-transform:capitalize}.tabs button.active{background:#142139;color:#fff}.workspace-table-wrap{border:1px solid #edf1f5;border-radius:8px;overflow:auto}.workspace-table{border-collapse:collapse;min-width:760px;width:100%}.workspace-table th,.workspace-table td{border-bottom:1px solid #edf1f5;font-size:12px;padding:12px 10px;text-align:left}.workspace-table th{background:#f8fafc;color:#69758a;font-size:10px;letter-spacing:.04em;text-transform:uppercase}.metric-status{background:#eef4ff;border-radius:999px;color:#2457d6;display:inline-flex;font-size:11px;font-weight:900;padding:5px 9px}.metric-status.completed,.metric-status.configured,.metric-status.active{background:#e7f8ef;color:#15803d}.metric-status.pending,.metric-status.not-configured,.metric-status.attention-required{background:#fff7ed;color:#b45309}.metric-status.coming-soon{background:#f1f5f9;color:#64748b}.workspace-empty-cell{color:#8490a2;text-align:center}@media(max-width:900px){.workspace-toolbar,.workspace-head{align-items:flex-start;flex-direction:column}.workspace-summary,.workspace-empty-grid{grid-template-columns:1fr}.tabs{justify-content:flex-start;overflow-x:auto;width:100%}}
.settings-panel{margin-top:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.settings-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px}.settings-head span{color:#2457d6;font-size:10px;font-weight:900;letter-spacing:.08em}.settings-head h2{margin:2px 0 4px;color:#142139;font-size:18px}.settings-head p{margin:0;color:#758197;font-size:12px}.settings-head button{min-height:40px;padding:9px 14px;color:#fff;background:#2457d6;border:1px solid #2457d6;border-radius:8px;font-size:12px;font-weight:850;cursor:pointer}.settings-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.settings-grid label,.logo-field{display:grid;gap:6px}.settings-grid label span,.logo-field>span{color:#5f6d82;font-size:11px;font-weight:850}.settings-grid input,.settings-grid select,.settings-grid textarea{width:100%;min-height:38px;padding:8px 10px;color:#27344c;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}.settings-grid textarea{min-height:82px;resize:vertical}.logo-upload-row{display:flex;align-items:center;gap:12px;min-height:80px;padding:10px;border:1px solid #d8e0eb;border-radius:8px;background:#fbfcfe}.logo-preview{display:grid;place-items:center;width:68px;height:58px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;overflow:hidden;flex:0 0 auto}.logo-preview img{width:100%;height:100%;object-fit:contain}.logo-preview strong{color:#2457d6;font-size:17px;font-weight:900}.logo-actions{display:grid;gap:6px;min-width:0}.logo-actions input{padding:7px;background:#fff}.logo-actions small{color:#758197;font-size:11px}.logo-actions button{justify-self:start;min-height:30px;padding:6px 10px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;color:#96333a;font-size:11px;font-weight:850;cursor:pointer}.check-field{align-content:center;grid-template-columns:auto 1fr;align-items:center;min-height:38px;padding:8px 10px;border:1px solid #d8e0eb;border-radius:8px;background:#fbfcfe}.check-field input{width:16px;min-height:16px;padding:0}.span-2{grid-column:span 2}.settings-error,.settings-success{display:grid;gap:5px;margin-top:12px;padding:10px;border-radius:8px;font-size:12px;font-weight:750}.settings-error{color:#96333a;background:#fff3f4;border:1px solid #ffd4d8}.settings-success{color:#166534;background:#eefbf4;border:1px solid #bdebd0}@media(max-width:900px){.settings-head{flex-direction:column}.settings-grid{grid-template-columns:1fr}.span-2{grid-column:span 1}.logo-upload-row{align-items:flex-start;flex-direction:column}.logo-actions{width:100%}}
</style>
