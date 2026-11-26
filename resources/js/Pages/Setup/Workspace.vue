<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import Layout from '../Layout.vue';
import TeamUsers from './TeamUsers.vue';

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
    thermal_paper_width: props.businessProfile.thermal_paper_width || '80mm',
    auto_print_after_payment: props.businessProfile.auto_print_after_payment ?? false,
    show_logo_on_thermal_receipt: props.businessProfile.show_logo_on_thermal_receipt ?? false,
    thermal_footer_text: props.businessProfile.thermal_footer_text || 'Thank You',
    a4_print_options: {
        show_business_info: props.businessProfile.a4_print_options?.show_business_info ?? true,
        show_customer_info: props.businessProfile.a4_print_options?.show_customer_info ?? true,
        show_hsn: props.businessProfile.a4_print_options?.show_hsn ?? true,
        show_tax_summary: props.businessProfile.a4_print_options?.show_tax_summary ?? true,
        show_bank_details: props.businessProfile.a4_print_options?.show_bank_details ?? true,
        show_terms: props.businessProfile.a4_print_options?.show_terms ?? true,
        show_signature: props.businessProfile.a4_print_options?.show_signature ?? true,
    },
    thermal_print_options: {
        show_business_info: props.businessProfile.thermal_print_options?.show_business_info ?? true,
        show_gstin: props.businessProfile.thermal_print_options?.show_gstin ?? true,
        show_customer: props.businessProfile.thermal_print_options?.show_customer ?? true,
        show_item_savings: props.businessProfile.thermal_print_options?.show_item_savings ?? true,
        show_tax_breakup: props.businessProfile.thermal_print_options?.show_tax_breakup ?? true,
        show_payment_details: props.businessProfile.thermal_print_options?.show_payment_details ?? true,
    },
});

const cards = computed(() => props.summary || []);
const active = computed(() => props.activeSection || props.initial_tab || 'admin');
// Items that are not available yet are left out: they only worry people.
const rows = computed(() => (props.metrics || []).filter((row) => row.status !== 'Coming Soon' && row.id !== 'progress'));
const attentionStatuses = ['Not Configured', 'Pending', 'Attention Required'];
const attention = computed(() => rows.value.filter((row) => attentionStatuses.includes(row.status)));
const healthy = computed(() => rows.value.filter((row) => !attentionStatuses.includes(row.status)));
const progress = computed(() => (props.metrics || []).find((row) => row.id === 'progress'));
const logoUploading = ref(false);
const logoPreview = computed(() => {
    const path = profileForm.logo_url || profileForm.logo_path || '';
    if (!path) return '';
    if (String(path).startsWith('http') || String(path).startsWith('/')) return path;
    return `/storage/${String(path).replace(/^\/+/, '')}`;
});
const copy = {
    admin: 'Set up your business and see what still needs your attention.',
    staff: 'Your employees and who can work in the app.',
    onboarding: 'The steps to finish before daily billing.',
    users: 'Who can log in, and what each person may do.',
    saas: 'Your plan and subscription.',
    settings: 'Business name, GSTIN, bank details and how bills are printed.',
};
const titleCopy = computed(() => copy[active.value] || copy.admin);
const tabInfo = {
    admin: ['Overview', 'Business setup'],
    staff: ['Staff', 'Employees'],
    onboarding: ['Setup Steps', 'Getting started'],
    users: ['Users', 'Logins and roles'],
    saas: ['Plan', 'Subscription'],
    settings: ['Settings', 'Profile and print'],
};
// [title, short help, address]
const actionSets = {
    admin: [
        ['Business Profile', 'Name, GSTIN, logo, bank, print', '/app/setup/settings'],
        ['Add User', 'Give a login to your staff', '/app/setup/users'],
        ['Add Product', 'Items you buy and sell', '/app/inventory/products'],
        ['Add Customer', 'People you sell to', '/app/sales/customers'],
        ['Add Supplier', 'People you buy from', '/app/purchase/suppliers'],
        ['Branches', 'Shops or offices', '/app/setup/branches'],
        ['Warehouses', 'Places where stock is kept', '/app/warehouse/warehouses'],
        ['Masters', 'Categories, units, brands, HSN', '/app/setup/masters'],
    ],
    staff: [
        ['Employees', 'Add or edit employee records', '/app/setup/employees'],
        ['Users & Logins', 'Create a login and choose a role', '/app/setup/users'],
        ['Staff Workspace', 'See the screen your staff uses', '/app/staff/workspace'],
    ],
    onboarding: [
        ['Full Setup Checklist', 'Every step with its details', '/app/onboarding'],
        ['Business Profile', 'Name, GSTIN, logo, bank, print', '/app/setup/settings'],
        ['Add Product', 'Items you buy and sell', '/app/inventory/products'],
    ],
};
const actions = computed(() => actionSets[active.value] || []);
const hints = {
    'Branches': 'Shops or offices of your business. At least one is needed.',
    'Warehouses': 'Places where stock is kept. At least one is needed.',
    'Stock Lines': 'Product and warehouse combinations that have stock.',
    'Products': 'Items you can put on a bill.',
    'Categories': 'Groups that make products easy to find.',
    'Brands': 'Optional. Add only if you sell by brand.',
    'Units': 'NOS, KG, LTR and so on.',
    'HSN Codes': 'Tax codes used on GST bills.',
    'GST Rates': 'Optional rate rules. Each product can also keep its own GST %.',
    'Financial Years': 'The accounting year your bills belong to.',
    'Total Employees': 'All employee records.',
    'Active Employees': 'Employees working now.',
    'Inactive Employees': 'Employees marked as not working.',
    'Unassigned Employees': 'Employees who do not have a login yet.',
    'Employees Without Branch': 'Open the employee and choose a branch.',
    'Employees Without Role': 'Choose a role so the right screens open.',
    'Employees Logged In Today': 'People who used the app today.',
    'Business profile completed': 'Fill your business name, GSTIN and address.',
    'Financial year created': 'Needed before entering bills.',
    'At least one branch created': 'Add your shop or office.',
    'At least one warehouse created': 'Add the place where stock is kept.',
    'GST configuration completed': 'Optional if every product already has its GST %.',
    'Product master created': 'Add the items you sell.',
    'Customer or supplier created': 'Add at least one party.',
    'Opening stock entered': 'Enter the stock you have today.',
    'Employee or staff user added': 'Add the people who work with you.',
    'Invoice numbering configured': 'Make your first sales bill.',
};
const optional = ['Brands', 'GST Rates', 'GST configuration completed'];
const todo = computed(() => attention.value.filter((row) => !optional.includes(row.metric)));
const later = computed(() => attention.value.filter((row) => optional.includes(row.metric)));
const shown = (value) => (typeof value === 'number' ? value.toLocaleString('en-IN') : value);

const routeUrl = (name, fallback = '#') => props.routes?.[name]?.url || fallback;
const dedicatedPages = {
    users: ['admin.users.index', '/app/setup/users'],
    saas: ['admin.subscription.show', '/app/setup/saas'],
    settings: ['admin.settings.index', '/app/setup/settings'],
};
const sectionUrl = (section) => {
    if (dedicatedPages[section]) return routeUrl(...dedicatedPages[section]).split('?')[0];
    return `${routeUrl('admin.workspace', '/app/admin/workspace')}?section=${section}`;
};

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
        profileErrors.value = { general: [error.response?.data?.message || 'Could not save the business profile. Please try again.'] };
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
        profileErrors.value = { logo_path: [error.response?.data?.message || 'Could not upload the logo. Please try again.'] };
    } finally {
        logoUploading.value = false;
        event.target.value = '';
    }
};

const removeLogo = () => {
    profileForm.logo_path = '';
    profileForm.logo_url = '';
};

</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title">
                <span>{{ ['users', 'settings', 'saas'].includes(active) ? 'Setup' : 'Admin' }}</span>
                <h1>{{ title }}</h1>
                <p>{{ titleCopy }}</p>
            </div>
        </template>

        <nav class="ws-tabs" aria-label="Admin sections">
            <button
                v-for="section in sections"
                :key="section"
                type="button"
                :class="{ active: active === section }"
                :aria-current="active === section ? 'page' : undefined"
                :disabled="Boolean(changingSection)"
                @click="openSection(section)"
            >
                <strong>{{ changingSection === section ? 'Loading…' : (tabInfo[section]?.[0] || section) }}</strong>
                <small>{{ tabInfo[section]?.[1] }}</small>
            </button>
        </nav>

        <div v-if="!['users', 'settings'].includes(active)" class="ws-page">
            <section v-if="active === 'admin' && cards.length" class="ws-cards">
                <a v-for="card in cards" :key="card.key" class="ws-card" :class="`tone-${card.tone || 'info'}`" :href="card.enabled ? card.href : null">
                    <span>{{ card.label }}</span>
                    <strong>{{ Number(card.value || 0).toLocaleString('en-IN') }}</strong>
                    <small>{{ Number(card.value || 0) === 0 ? card.empty : (hints[card.label] || '') }}</small>
                </a>
            </section>

            <section v-if="actions.length" class="ws-panel">
                <h2>What do you want to do?</h2>
                <div class="ws-actions">
                    <a v-for="action in actions" :key="action[0]" :href="action[2]">
                        <strong>{{ action[0] }}</strong>
                        <small>{{ action[1] }}</small>
                    </a>
                </div>
            </section>

            <section class="ws-panel">
                <div class="ws-panel-head">
                    <h2>Needs your attention</h2>
                    <span class="ws-head-right">
                        <em :class="{ clear: !todo.length }">{{ todo.length ? `${todo.length} to do` : 'All clear' }}</em>
                        <button type="button" :disabled="refreshing" @click="refresh">{{ refreshing ? 'Refreshing…' : 'Refresh' }}</button>
                    </span>
                </div>
                <p v-if="active === 'onboarding' && progress" class="ws-progress">{{ progress.value }}</p>
                <p v-if="!todo.length" class="ws-done">Nothing is pending here.</p>
                <component :is="row.enabled ? 'a' : 'div'" v-for="row in todo" :key="row.id" class="ws-todo" :href="row.enabled ? row.href : null">
                    <span><strong>{{ row.metric }}</strong><small>{{ hints[row.metric] }}</small></span>
                    <b>{{ ['Yes', 'No'].includes(row.value) ? 'Pending' : shown(row.value) }}</b>
                    <i v-if="row.enabled">Fix now ›</i>
                </component>
                <template v-if="later.length">
                    <h3>Optional</h3>
                    <component :is="row.enabled ? 'a' : 'div'" v-for="row in later" :key="row.id" class="ws-todo optional" :href="row.enabled ? row.href : null">
                        <span><strong>{{ row.metric }}</strong><small>{{ hints[row.metric] }}</small></span>
                        <i v-if="row.enabled">Open ›</i>
                    </component>
                </template>
            </section>

            <section v-if="healthy.length" class="ws-panel">
                <h2>{{ active === 'onboarding' ? 'Completed' : 'Your setup' }}</h2>
                <div class="ws-grid">
                    <component :is="row.enabled ? 'a' : 'div'" v-for="row in healthy" :key="row.id" class="ws-item" :href="row.enabled ? row.href : null">
                        <span>{{ row.metric }}</span>
                        <strong>{{ row.value === 'Yes' ? 'Done' : shown(row.value) }}</strong>
                        <small>{{ hints[row.metric] || row.status }}</small>
                    </component>
                </div>
            </section>
        </div>

        <TeamUsers v-if="active === 'users' && permissions['users.view']" />

        <section v-if="active === 'settings'" class="settings-panel">
            <div class="settings-head">
                <div>
                    <span>BUSINESS SETTINGS</span>
                    <h2>Business Profile & Invoice Print</h2>
                    <p>These details appear on your printed bills and reports. Click Save Settings after changing them.</p>
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
                            <small>{{ logoUploading ? 'Uploading...' : 'This logo appears in the header of printed invoices.' }}</small>
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
                <div class="print-option-card span-2">
                    <div class="print-option-head">
                        <strong>A4 Invoice Print</strong>
                        <span>Fields visible on full tax invoice</span>
                    </div>
                    <div class="print-check-grid">
                        <label><input v-model="profileForm.a4_print_options.show_business_info" type="checkbox" /><span>Business header</span></label>
                        <label><input v-model="profileForm.a4_print_options.show_customer_info" type="checkbox" /><span>Customer details</span></label>
                        <label><input v-model="profileForm.a4_print_options.show_hsn" type="checkbox" /><span>HSN/SAC column</span></label>
                        <label><input v-model="profileForm.a4_print_options.show_tax_summary" type="checkbox" /><span>GST tax summary</span></label>
                        <label><input v-model="profileForm.a4_print_options.show_bank_details" type="checkbox" /><span>Bank details</span></label>
                        <label><input v-model="profileForm.a4_print_options.show_terms" type="checkbox" /><span>Terms</span></label>
                        <label><input v-model="profileForm.a4_print_options.show_signature" type="checkbox" /><span>Signature box</span></label>
                    </div>
                </div>
                <div class="print-option-card span-2">
                    <div class="print-option-head">
                        <strong>80mm Thermal Print</strong>
                        <span>Receipt style and details</span>
                    </div>
                    <div class="thermal-controls">
                        <label><span>Paper Width</span><select v-model="profileForm.thermal_paper_width"><option value="80mm">80mm</option><option value="58mm">58mm</option></select></label>
                        <label><span>Footer Text</span><input v-model="profileForm.thermal_footer_text" placeholder="Thank You" /></label>
                    </div>
                    <div class="print-check-grid">
                        <label><input v-model="profileForm.auto_print_after_payment" type="checkbox" /><span>Auto print after payment</span></label>
                        <label><input v-model="profileForm.show_logo_on_thermal_receipt" type="checkbox" /><span>Logo on receipt</span></label>
                        <label><input v-model="profileForm.thermal_print_options.show_business_info" type="checkbox" /><span>Business header</span></label>
                        <label><input v-model="profileForm.thermal_print_options.show_gstin" type="checkbox" /><span>GSTIN</span></label>
                        <label><input v-model="profileForm.thermal_print_options.show_customer" type="checkbox" /><span>Customer</span></label>
                        <label><input v-model="profileForm.thermal_print_options.show_item_savings" type="checkbox" /><span>MRP saving line</span></label>
                        <label><input v-model="profileForm.thermal_print_options.show_tax_breakup" type="checkbox" /><span>Tax breakup</span></label>
                        <label><input v-model="profileForm.thermal_print_options.show_payment_details" type="checkbox" /><span>Payment details</span></label>
                    </div>
                </div>
            </div>

            <div v-if="Object.keys(profileErrors).length" class="settings-error">
                <span v-for="(messages, field) in profileErrors" :key="field">{{ messages[0] }}</span>
            </div>
            <div v-if="profileSaved" class="settings-success">{{ profileSaved }}</div>
        </section>
    </Layout>
</template>

<style scoped>
.ws-tabs{display:flex;gap:8px;margin-bottom:16px;overflow-x:auto;padding-bottom:2px}
.ws-tabs button{display:grid;flex:1 0 128px;gap:2px;min-height:56px;padding:9px 14px;text-align:left;color:#344159;background:#fff;border:1px solid #dfe6ef;border-radius:10px;cursor:pointer}
.ws-tabs strong{font-size:13px}.ws-tabs small{color:#758197;font-size:11px}
.ws-tabs button.active{color:#fff;background:#2457d6;border-color:#2457d6}.ws-tabs button.active small{color:#dbe6ff}
.ws-tabs button:disabled{cursor:wait;opacity:.7}
.ws-page{display:grid;gap:16px}
.ws-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.ws-card{display:grid;gap:4px;padding:14px 16px;color:inherit;background:#fff;border:1px solid #dfe6ef;border-top:4px solid #2563eb;border-radius:12px;text-decoration:none}
.ws-card span{color:#5f6d82;font-size:12px;font-weight:800}.ws-card strong{color:#142139;font-size:26px;font-weight:900}.ws-card small{color:#758197;font-size:11px}
.tone-good{border-top-color:#22c55e}.tone-warn{border-top-color:#f59e0b}.tone-money{border-top-color:#14b8a6}
.ws-panel{padding:16px 18px;background:#fff;border:1px solid #dfe6ef;border-radius:12px}
.ws-panel h2{margin:0 0 12px;color:#142139;font-size:16px;font-weight:800}
.ws-panel h3{margin:16px 0 8px;color:#758197;font-size:11px;font-weight:800;text-transform:uppercase}
.ws-actions{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.ws-actions a{display:grid;gap:3px;align-content:start;min-height:72px;padding:12px 14px;color:#142139;background:#f6f9ff;border:1px solid #d6e3ff;border-radius:10px;text-decoration:none}
.ws-actions a:hover,.ws-card[href]:hover,a.ws-todo:hover,a.ws-item:hover{border-color:#2457d6;box-shadow:0 6px 18px rgba(36,87,214,.1)}
.ws-actions strong{font-size:13px}.ws-actions small{color:#667085;font-size:11px}
.ws-panel-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px}.ws-panel-head h2{margin:0}
.ws-head-right{display:flex;align-items:center;gap:8px}
.ws-head-right em{padding:4px 10px;color:#b45309;background:#fff7ed;border-radius:999px;font-size:11px;font-style:normal;font-weight:800}.ws-head-right em.clear{color:#15803d;background:#e7f8ef}
.ws-head-right button{min-height:34px;padding:6px 12px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px;font-weight:800;cursor:pointer}
.ws-progress{margin:0 0 10px;color:#344159;font-size:13px;font-weight:800}
.ws-done{margin:0;color:#15803d;font-size:13px}
.ws-todo{display:flex;align-items:center;gap:12px;margin-bottom:8px;padding:11px 14px;color:inherit;background:#fffbf3;border:1px solid #fde7c2;border-radius:10px;text-decoration:none}
.ws-todo.optional{background:#f8fafc;border-color:#e3e9f2}
.ws-todo span{display:grid;gap:2px;flex:1;min-width:0}.ws-todo strong{color:#142139;font-size:13px}.ws-todo small{color:#667085;font-size:12px}
.ws-todo b{color:#b45309;font-size:13px}.ws-todo i{color:#2457d6;font-size:12px;font-style:normal;font-weight:800;white-space:nowrap}
.ws-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.ws-item{display:grid;gap:3px;align-content:start;padding:12px 14px;color:inherit;background:#fff;border:1px solid #e3e9f2;border-radius:10px;text-decoration:none}
.ws-item span{color:#5f6d82;font-size:12px;font-weight:800}.ws-item strong{color:#142139;font-size:20px;font-weight:900;overflow-wrap:anywhere}.ws-item small{color:#8490a2;font-size:11px}
@media(max-width:1100px){.ws-actions,.ws-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.ws-cards{grid-template-columns:minmax(0,1fr)}.ws-actions,.ws-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ws-todo{flex-wrap:wrap}}
.settings-panel{margin-top:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.settings-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px}.settings-head span{color:#2457d6;font-size:10px;font-weight:900;letter-spacing:.08em}.settings-head h2{margin:2px 0 4px;color:#142139;font-size:18px}.settings-head p{margin:0;color:#758197;font-size:12px}.settings-head button{min-height:40px;padding:9px 14px;color:#fff;background:#2457d6;border:1px solid #2457d6;border-radius:8px;font-size:12px;font-weight:850;cursor:pointer}.settings-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.settings-grid label,.logo-field{display:grid;gap:6px}.settings-grid label span,.logo-field>span{color:#5f6d82;font-size:11px;font-weight:850}.settings-grid input,.settings-grid select,.settings-grid textarea{width:100%;min-height:38px;padding:8px 10px;color:#27344c;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}.settings-grid textarea{min-height:82px;resize:vertical}.logo-upload-row{display:flex;align-items:center;gap:12px;min-height:80px;padding:10px;border:1px solid #d8e0eb;border-radius:8px;background:#fbfcfe}.logo-preview{display:grid;place-items:center;width:68px;height:58px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;overflow:hidden;flex:0 0 auto}.logo-preview img{width:100%;height:100%;object-fit:contain}.logo-preview strong{color:#2457d6;font-size:17px;font-weight:900}.logo-actions{display:grid;gap:6px;min-width:0}.logo-actions input{padding:7px;background:#fff}.logo-actions small{color:#758197;font-size:11px}.logo-actions button{justify-self:start;min-height:30px;padding:6px 10px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;color:#96333a;font-size:11px;font-weight:850;cursor:pointer}.check-field{align-content:center;grid-template-columns:auto 1fr;align-items:center;min-height:38px;padding:8px 10px;border:1px solid #d8e0eb;border-radius:8px;background:#fbfcfe}.check-field input{width:16px;min-height:16px;padding:0}.print-option-card{display:grid;gap:12px;padding:12px;border:1px solid #dfe6ef;border-radius:8px;background:#fbfcfe}.print-option-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.print-option-head strong{color:#142139;font-size:13px}.print-option-head span{color:#758197;font-size:11px;font-weight:750}.print-check-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 12px}.print-check-grid label{display:grid;grid-template-columns:auto 1fr;align-items:center;gap:7px;min-height:32px;padding:7px 8px;border:1px solid #e4eaf2;border-radius:8px;background:#fff}.print-check-grid input{width:15px;min-height:15px;padding:0}.thermal-controls{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.span-2{grid-column:span 2}.settings-error,.settings-success{display:grid;gap:5px;margin-top:12px;padding:10px;border-radius:8px;font-size:12px;font-weight:750}.settings-error{color:#96333a;background:#fff3f4;border:1px solid #ffd4d8}.settings-success{color:#166534;background:#eefbf4;border:1px solid #bdebd0}@media(max-width:900px){.settings-head{flex-direction:column}.settings-grid{grid-template-columns:1fr}.span-2{grid-column:span 1}.logo-upload-row{align-items:flex-start;flex-direction:column}.logo-actions{width:100%}.print-check-grid,.thermal-controls{grid-template-columns:1fr}.print-option-head{flex-direction:column}}
</style>
