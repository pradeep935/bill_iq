<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import CrmApi from './CrmApi';

const props = defineProps({
    page: { type: String, default: 'crm' },
    title: { type: String, default: 'CRM' },
    initial_section: { type: String, default: 'dashboard' },
    endpoints: { type: Object, default: () => ({}) },
});
const today = new Date().toISOString().slice(0, 10);
const tabs = ['dashboard', 'leads', 'opportunities', 'kanban', 'activities', 'calendar', 'reports', 'masters'];
const query = () => new URLSearchParams(window.location.search);
const validSection = (value) => tabs.includes(value) ? value : 'dashboard';
const tab = ref(validSection(query().get('section') || props.initial_section));
const loading = ref(false);
const saving = ref(false);
const errors = ref({});
const refs = ref({ sources: [], statuses: [], lost_reasons: [], campaigns: [], teams: [], pipelines: [], stages: [], users: [], branches: [], customers: [], products: [], settings: {} });
const dashboard = ref({});
const leads = ref([]);
const opportunities = ref([]);
const activities = ref([]);
const kanban = ref([]);
const calendar = ref({ activities: [], opportunity_closings: [], quotation_expiries: [] });
const reports = ref({});
const selectedLeads = ref([]);
const filters = reactive({ search: query().get('search') || '', status_id: query().get('status_id') || '', assigned_to: query().get('assigned_to') || '', lead_source_id: query().get('lead_source_id') || '', stage_id: query().get('stage_id') || '', priority: query().get('priority') || '', date_from: query().get('date_from') || '', date_to: query().get('date_to') || '', branch_id: query().get('branch_id') || '' });

const lead = reactive({ lead_type: 'individual', branch_id: '', company_name: '', contact_person_name: '', first_name: '', last_name: '', email: '', mobile: '', whatsapp_number: '', lead_source_id: '', campaign_id: '', assigned_to: '', assigned_team_id: '', status_id: '', qualification_status: 'unqualified', priority: 'medium', estimated_value: 0, expected_closing_date: '', requirement_summary: '', city: '', gstin: '', pan: '', do_not_call: false, do_not_email: false, do_not_whatsapp: false, next_follow_up_at: '', status: 'active', contacts: [{ contact_name: '', designation: '', email: '', mobile: '', whatsapp_number: '', is_primary: true, notes: '' }] });
const opportunity = reactive({ lead_id: '', customer_id: '', pipeline_id: '', stage_id: '', opportunity_name: '', description: '', owner_id: '', sales_team_id: '', source_id: '', campaign_id: '', estimated_value: 0, probability_percent: '', expected_closing_date: '', priority: 'medium', next_step: '', next_follow_up_at: '', status: 'open', lost_reason_id: '', lost_notes: '', won_reason: '', items: [{ product_id: '', description: '', quantity: 1, estimated_unit_price: 0, estimated_discount: 0, estimated_tax: 0, notes: '' }] });
const activity = reactive({ activity_type: 'follow_up', subject: '', description: '', related_type: 'lead', related_id: '', lead_id: '', opportunity_id: '', customer_id: '', assigned_to: '', activity_date: today, start_at: '', end_at: '', direction: '', outcome: '', next_action: '', next_follow_up_at: '', status: 'planned', priority: 'medium', location: '', meeting_mode: '', reminder_at: '', reminder_channel: 'in_app' });
const qualification = reactive({ lead_id: '', budget_status: 'confirmed', authority_status: 'confirmed', need_status: 'confirmed', timeline_status: 'confirmed', budget_amount: 0, decision_maker_name: '', expected_purchase_date: '', pain_points: '', requirement_details: '' });
const source = reactive({ source_code: '', source_name: '', source_type: 'direct', status: 'active', is_system: false });
const campaign = reactive({ campaign_code: '', campaign_name: '', campaign_type: 'digital', start_date: today, end_date: '', budget_amount: 0, actual_cost: 0, target_leads: '', target_revenue: 0, status: 'active', description: '' });

CrmApi.configure(props.endpoints);

const money = (v) => `₹${Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const capture = (e) => { errors.value = e?.response?.data?.errors || { form: [e?.response?.data?.message || 'Unable to save.'] }; };
const clearErrors = () => { errors.value = {}; };
const firstError = computed(() => errors.value.form?.[0] || Object.values(errors.value)[0]?.[0] || '');
const add = (rows, row) => rows.push({ ...row });
const leadName = (row) => row.company_name || row.contact_person_name;
const selectedPipeline = computed(() => refs.value.pipelines.find((p) => Number(p.id) === Number(opportunity.pipeline_id)) || refs.value.pipelines[0]);
const stages = computed(() => selectedPipeline.value?.stages || refs.value.stages || []);
const productChanged = (item) => { const p = refs.value.products.find((x) => Number(x.id) === Number(item.product_id)); if (p) { item.description = p.name; item.estimated_unit_price = Number(p.selling_price || 0); } };
const currentParams = () => Object.fromEntries(Object.entries({ section: tab.value, ...filters }).filter(([, value]) => value !== '' && value !== null && value !== undefined));
const syncUrl = (replace = false) => {
    const params = new URLSearchParams(currentParams());
    const next = `${window.location.pathname}?${params.toString()}`;
    window.history[replace ? 'replaceState' : 'pushState']({}, '', next);
};
const setTab = async (section) => {
    tab.value = validSection(section);
    syncUrl();
    await load();
};
const applyFilters = async () => { syncUrl(); await load(); };
const clearFilters = async () => {
    Object.assign(filters, { search: '', status_id: '', assigned_to: '', lead_source_id: '', stage_id: '', priority: '', date_from: '', date_to: '', branch_id: '' });
    syncUrl();
    await load();
};
const fromUrl = () => {
    const params = query();
    tab.value = validSection(params.get('section'));
    Object.keys(filters).forEach((key) => { filters[key] = params.get(key) || ''; });
    load();
};

const load = async () => {
    loading.value = true;
    try {
        refs.value = await CrmApi.references();
        if (!lead.status_id && refs.value.statuses[0]) lead.status_id = refs.value.statuses[0].id;
        if (!opportunity.pipeline_id && refs.value.pipelines[0]) opportunity.pipeline_id = refs.value.pipelines[0].id;
        if (!opportunity.stage_id && stages.value[0]) opportunity.stage_id = stages.value[0].id;
        dashboard.value = await CrmApi.dashboard(filters);
        leads.value = (await CrmApi.leads(filters)).leads || [];
        opportunities.value = (await CrmApi.opportunities(filters)).opportunities || [];
        activities.value = (await CrmApi.activities(filters)).activities || [];
        kanban.value = (await CrmApi.kanban(filters)).stages || [];
        calendar.value = await CrmApi.calendar(filters);
        reports.value = await CrmApi.reports(filters);
    } finally {
        loading.value = false;
    }
};

const run = async (callback) => {
    saving.value = true;
    clearErrors();
    try { await callback(); await load(); } catch (e) { capture(e); } finally { saving.value = false; }
};
const saveLead = () => run(() => CrmApi.saveLead({ ...lead, contacts: lead.contacts.filter((c) => c.contact_name) }));
const assignSelected = () => run(() => CrmApi.bulkAssign({ lead_ids: selectedLeads.value, assigned_to: lead.assigned_to, assigned_team_id: lead.assigned_team_id, assignment_reason: 'Bulk assignment' }));
const convertLead = (row) => run(() => CrmApi.convertLead(row.id, { create_opportunity: true, opportunity_name: `Opportunity - ${leadName(row)}` }));
const saveQualification = () => run(() => CrmApi.qualifyLead(qualification.lead_id, { ...qualification }));
const saveOpportunity = () => run(() => CrmApi.saveOpportunity({ ...opportunity, items: opportunity.items.filter((i) => i.description) }));
const moveOpportunity = (row, stageId) => run(() => CrmApi.moveOpportunity(row.id, { stage_id: stageId, lost_reason_id: row.lost_reason_id || opportunity.lost_reason_id, lost_notes: row.lost_notes || opportunity.lost_notes, won_reason: row.won_reason || opportunity.won_reason }));
const markWon = (row) => run(() => CrmApi.markOpportunityWon(row.id, { won_reason: row.won_reason || opportunity.won_reason }));
const markLost = (row) => run(() => CrmApi.markOpportunityLost(row.id, { lost_reason_id: row.lost_reason_id || opportunity.lost_reason_id, lost_notes: row.lost_notes || opportunity.lost_notes }));
const quoteOpportunity = (row) => run(() => CrmApi.opportunityQuotation(row.id));
const saveActivity = () => run(() => CrmApi.saveActivity({ ...activity }));
const completeActivity = (row) => run(() => CrmApi.completeActivity(row.id, { outcome: row.outcome || 'Completed from CRM activity list' }));
const cancelActivity = (row) => run(() => CrmApi.cancelActivity(row.id, { outcome: row.outcome || 'Cancelled' }));
const saveSource = () => run(() => CrmApi.saveMaster('source', { ...source }));
const saveCampaign = () => run(() => CrmApi.saveMaster('campaign', { ...campaign }));
const exportCsv = (rows, filename) => {
    const safeRows = rows || [];
    const headers = Object.keys(safeRows[0] || { empty: '' }).filter((h) => typeof safeRows[0]?.[h] !== 'object');
    const csv = [headers.join(','), ...safeRows.map((row) => headers.map((h) => `"${String(row[h] ?? '').replaceAll('"', '""')}"`).join(','))].join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    link.download = filename;
    link.click();
};

onMounted(() => { syncUrl(true); load(); window.addEventListener('popstate', fromUrl); });
onUnmounted(() => window.removeEventListener('popstate', fromUrl));
</script>

<template>
    <Layout :page="props.page" :title="props.title">
        <template #topbar-title>
            <div class="bill-page-title"><span>CRM</span><h1>Leads, Pipeline & Follow-ups</h1><p>Pre-sales relationship layer connected to customers, quotations, sales orders and invoices.</p></div>
        </template>
        <div class="crm-page">
            <div class="page-toolbar"><button :disabled="loading" @click="load">{{ loading ? 'Refreshing...' : 'Refresh' }}</button></div>
            <div class="filters">
                <label><span>Search</span><input v-model="filters.search" placeholder="Lead, mobile, company, opportunity" @keyup.enter="applyFilters" /></label>
                <label><span>Owner</span><select v-model="filters.assigned_to"><option value="">All owners</option><option value="unassigned">Unassigned</option><option v-for="u in refs.users" :key="u.id" :value="u.id">{{ u.name }}</option></select></label>
                <label><span>Source</span><select v-model="filters.lead_source_id"><option value="">All sources</option><option v-for="s in refs.sources" :key="s.id" :value="s.id">{{ s.source_name }}</option></select></label>
                <label><span>Status</span><select v-model="filters.status_id"><option value="">All lead statuses</option><option v-for="s in refs.statuses" :key="s.id" :value="s.id">{{ s.status_name }}</option></select></label>
                <label><span>Stage</span><select v-model="filters.stage_id"><option value="">All stages</option><option v-for="s in refs.stages" :key="s.id" :value="s.id">{{ s.stage_name }}</option></select></label>
                <label><span>From</span><input v-model="filters.date_from" type="date" /></label>
                <label><span>To</span><input v-model="filters.date_to" type="date" /></label>
                <button @click="applyFilters">Apply</button><button @click="clearFilters">Clear Filters</button>
            </div>
            <div class="tabs"><button v-for="t in tabs" :key="t" :class="{ active: tab === t }" @click="setTab(t)">{{ t }}</button></div>
            <div v-if="firstError" class="alert">{{ firstError }}</div>

            <section v-if="tab === 'dashboard'" class="panel cards">
                <div><span>New Leads</span><strong>{{ dashboard.new_leads || 0 }}</strong></div><div><span>Unassigned</span><strong>{{ dashboard.unassigned_leads || 0 }}</strong></div><div><span>Qualified</span><strong>{{ dashboard.qualified_leads || 0 }}</strong></div><div><span>Converted</span><strong>{{ dashboard.leads_converted || 0 }}</strong></div><div><span>Open Opps</span><strong>{{ dashboard.open_opportunities || 0 }}</strong></div><div><span>Pipeline</span><strong>{{ money(dashboard.pipeline_value) }}</strong></div><div><span>Weighted</span><strong>{{ money(dashboard.weighted_pipeline_value) }}</strong></div><div><span>Due Today</span><strong>{{ dashboard.activities_due_today || 0 }}</strong></div><div><span>Overdue</span><strong>{{ dashboard.overdue_followups || 0 }}</strong></div><div><span>Won Revenue</span><strong>{{ money(dashboard.won_revenue) }}</strong></div><div><span>Lost Opps</span><strong>{{ dashboard.lost_opportunities || 0 }}</strong></div><div><span>Quote Conv.</span><strong>{{ dashboard.quotation_conversion_rate || 0 }}%</strong></div>
            </section>

            <section v-if="tab === 'leads'" class="panel">
                <div class="form-grid">
                    <label><span>Lead Type *</span><select v-model="lead.lead_type"><option>individual</option><option>business</option></select></label>
                    <label><span>Company Name</span><input v-model="lead.company_name" /></label>
                    <label><span>Contact Person *</span><input v-model="lead.contact_person_name" /></label>
                    <label><span>Mobile *</span><input v-model="lead.mobile" /></label>
                    <label><span>Email</span><input v-model="lead.email" type="email" /></label>
                    <label><span>Source</span><select v-model="lead.lead_source_id"><option value="">Select source</option><option v-for="s in refs.sources" :key="s.id" :value="s.id">{{ s.source_name }}</option></select></label>
                    <label><span>Status</span><select v-model="lead.status_id"><option v-for="s in refs.statuses" :key="s.id" :value="s.id">{{ s.status_name }}</option></select></label>
                    <label><span>Owner</span><select v-model="lead.assigned_to"><option value="">Auto assign</option><option v-for="u in refs.users" :key="u.id" :value="u.id">{{ u.name }}</option></select></label>
                    <label><span>Priority *</span><select v-model="lead.priority"><option>low</option><option>medium</option><option>high</option><option>urgent</option></select></label>
                    <label><span>Estimated Value</span><input v-model.number="lead.estimated_value" type="number" step="0.01" /></label>
                    <label><span>Expected Conversion</span><input v-model="lead.expected_closing_date" type="date" /></label>
                    <label><span>Requirement Summary</span><textarea v-model="lead.requirement_summary"></textarea></label>
                    <label class="check"><input v-model="lead.do_not_call" type="checkbox" /> Do Not Call</label>
                    <label class="check"><input v-model="lead.do_not_email" type="checkbox" /> Do Not Email</label>
                    <label class="check"><input v-model="lead.do_not_whatsapp" type="checkbox" /> Do Not WhatsApp</label>
                </div>
                <div v-for="(c, i) in lead.contacts" :key="i" class="line-grid contact-row">
                    <label><span>Contact Name</span><input v-model="c.contact_name" /></label><label><span>Designation</span><input v-model="c.designation" /></label><label><span>Mobile</span><input v-model="c.mobile" /></label><label><span>Email</span><input v-model="c.email" type="email" /></label><label class="check"><input v-model="c.is_primary" type="checkbox" /> Primary</label><button @click="lead.contacts.splice(i,1)" :disabled="lead.contacts.length === 1">Remove</button>
                </div>
                <div class="actions"><button @click="add(lead.contacts, { contact_name: '', designation: '', email: '', mobile: '', whatsapp_number: '', is_primary: false, notes: '' })">Add Contact</button><button :disabled="saving" @click="saveLead">{{ saving ? 'Saving...' : 'Save Lead' }}</button><button :disabled="!selectedLeads.length || saving" @click="assignSelected">Bulk Assign</button></div>
                <div v-if="!leads.length" class="empty"><strong>No leads found.</strong><button @click="setTab('leads')">Add First Lead</button></div>
                <div v-else class="table-wrapper"><table><thead><tr><th></th><th>No.</th><th>Name</th><th>Mobile</th><th>Source</th><th>Status</th><th>Owner</th><th>Value</th><th>Score</th><th>Next Follow-up</th><th>Actions</th></tr></thead><tbody><tr v-for="l in leads" :key="l.id"><td><input v-model="selectedLeads" type="checkbox" :value="l.id" /></td><td>{{ l.lead_number }}</td><td>{{ leadName(l) }}</td><td>{{ l.mobile }}</td><td>{{ l.source?.source_name }}</td><td>{{ l.status_model?.status_name }}</td><td>{{ l.owner?.name || 'Unassigned' }}</td><td>{{ money(l.estimated_value) }}</td><td>{{ l.score ? l.score : 'Not Scored' }}</td><td>{{ l.next_follow_up_at || '-' }}</td><td><button @click="qualification.lead_id = l.id; setTab('activities')">Follow-up</button><button @click="convertLead(l)">Convert</button></td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'opportunities'" class="panel">
                <div class="form-grid"><label><span>Lead</span><select v-model="opportunity.lead_id"><option value="">Select lead</option><option v-for="l in leads" :key="l.id" :value="l.id">{{ l.lead_number }} - {{ leadName(l) }}</option></select></label><label><span>Customer</span><select v-model="opportunity.customer_id"><option value="">Select customer</option><option v-for="c in refs.customers" :key="c.id" :value="c.id">{{ c.customer_name }}</option></select></label><label><span>Pipeline</span><select v-model="opportunity.pipeline_id"><option v-for="p in refs.pipelines" :key="p.id" :value="p.id">{{ p.pipeline_name }}</option></select></label><label><span>Stage *</span><select v-model="opportunity.stage_id"><option v-for="s in stages" :key="s.id" :value="s.id">{{ s.stage_name }}</option></select></label><label><span>Opportunity Name *</span><input v-model="opportunity.opportunity_name" /></label><label><span>Owner</span><select v-model="opportunity.owner_id"><option value="">Current user</option><option v-for="u in refs.users" :key="u.id" :value="u.id">{{ u.name }}</option></select></label><label><span>Value *</span><input v-model.number="opportunity.estimated_value" type="number" step="0.01" /></label><label><span>Probability %</span><input v-model.number="opportunity.probability_percent" type="number" min="0" max="100" step="0.01" /></label><label><span>Expected Close</span><input v-model="opportunity.expected_closing_date" type="date" /></label><label><span>Priority *</span><select v-model="opportunity.priority"><option>low</option><option>medium</option><option>high</option><option>urgent</option></select></label><label><span>Next Step</span><textarea v-model="opportunity.next_step"></textarea></label><label><span>Lost Reason</span><select v-model="opportunity.lost_reason_id"><option value="">Select if marking lost</option><option v-for="r in refs.lost_reasons" :key="r.id" :value="r.id">{{ r.reason_name }}</option></select></label></div>
                <div v-for="(item, i) in opportunity.items" :key="i" class="line-grid"><label><span>Product</span><select v-model="item.product_id" @change="productChanged(item)"><option value="">Custom item</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select></label><label><span>Description *</span><input v-model="item.description" /></label><label><span>Qty *</span><input v-model.number="item.quantity" type="number" step="0.001" /></label><label><span>Rate *</span><input v-model.number="item.estimated_unit_price" type="number" step="0.01" /></label><label><span>Discount</span><input v-model.number="item.estimated_discount" type="number" step="0.01" /></label><button @click="opportunity.items.splice(i,1)" :disabled="opportunity.items.length === 1">Remove</button></div>
                <div class="actions"><button @click="add(opportunity.items, { product_id: '', description: '', quantity: 1, estimated_unit_price: 0, estimated_discount: 0, estimated_tax: 0, notes: '' })">Add Item</button><button :disabled="saving" @click="saveOpportunity">{{ saving ? 'Saving...' : 'Save Opportunity' }}</button></div>
                <div v-if="!opportunities.length" class="empty"><strong>No opportunities found.</strong><button @click="setTab('opportunities')">Create Opportunity</button></div>
                <div v-else class="table-wrapper"><table><thead><tr><th>No.</th><th>Name</th><th>Stage</th><th>Owner</th><th>Value</th><th>Weighted</th><th>Status</th><th>Expected Close</th><th>Actions</th></tr></thead><tbody><tr v-for="o in opportunities" :key="o.id"><td>{{ o.opportunity_number }}</td><td>{{ o.opportunity_name }}</td><td>{{ o.stage?.stage_name }}</td><td>{{ o.owner?.name }}</td><td>{{ money(o.estimated_value) }}</td><td>{{ money(o.weighted_value) }}</td><td>{{ o.status }}</td><td>{{ o.expected_closing_date || '-' }}</td><td><button @click="quoteOpportunity(o)">Quotation</button><button @click="markWon(o)">Won</button><button @click="markLost(o)">Lost</button></td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'kanban'" class="kanban">
                <div v-for="col in kanban" :key="col.stage.id" class="kanban-col">
                    <h3>{{ col.stage.stage_name }} <span>{{ col.count }}</span></h3><p>{{ money(col.total_value) }} / {{ money(col.weighted_value) }}</p>
                    <div v-for="card in col.cards" :key="card.id" class="opp-card"><strong>{{ card.opportunity_number }}</strong><b>{{ card.opportunity_name }}</b><span>{{ money(card.estimated_value) }} / {{ card.probability_percent }}%</span><small>{{ card.customer?.customer_name || card.lead?.contact_person_name }}</small><select @change="moveOpportunity(card, Number($event.target.value))"><option value="">Move</option><option v-for="s in refs.stages" :key="s.id" :value="s.id">{{ s.stage_name }}</option></select></div>
                </div>
            </section>

            <section v-if="tab === 'activities'" class="panel">
                <div class="form-grid"><select v-model="activity.activity_type"><option>call</option><option>meeting</option><option>task</option><option>follow_up</option><option>email</option><option>whatsapp</option><option>note</option><option>demo</option><option>site_visit</option></select><input v-model="activity.subject" placeholder="Subject" /><select v-model="activity.related_type"><option>lead</option><option>opportunity</option><option>customer</option></select><input v-model.number="activity.related_id" type="number" placeholder="Related ID" /><select v-model="activity.assigned_to"><option value="">Assigned user</option><option v-for="u in refs.users" :key="u.id" :value="u.id">{{ u.name }}</option></select><input v-model="activity.activity_date" type="date" /><input v-model="activity.start_at" type="datetime-local" /><input v-model="activity.next_follow_up_at" type="datetime-local" /><select v-model="activity.status"><option>planned</option><option>in_progress</option><option>completed</option><option>cancelled</option><option>missed</option><option>overdue</option></select><select v-model="activity.priority"><option>low</option><option>medium</option><option>high</option><option>urgent</option></select><textarea v-model="activity.description" placeholder="Notes"></textarea><textarea v-model="activity.outcome" placeholder="Outcome"></textarea></div>
                <div class="form-grid"><select v-model="qualification.lead_id"><option value="">Qualify lead</option><option v-for="l in leads" :key="l.id" :value="l.id">{{ l.lead_number }} - {{ leadName(l) }}</option></select><input v-model.number="qualification.budget_amount" type="number" step="0.01" placeholder="Budget" /><input v-model="qualification.decision_maker_name" placeholder="Decision maker" /><button :disabled="saving || !qualification.lead_id" @click="saveQualification">Save Qualification</button></div>
                <div class="actions"><button :disabled="saving" @click="saveActivity">Save Activity</button></div>
                <div class="table-wrapper"><table><thead><tr><th>Date</th><th>Type</th><th>Subject</th><th>Related</th><th>Owner</th><th>Status</th><th>Outcome</th></tr></thead><tbody><tr v-for="a in activities" :key="a.id"><td>{{ a.activity_date }}</td><td>{{ a.activity_type }}</td><td>{{ a.subject }}</td><td>{{ a.related_type }} #{{ a.related_id }}</td><td>{{ a.assignee?.name }}</td><td>{{ a.status }}</td><td>{{ a.outcome }}</td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'calendar'" class="panel">
                <div class="cards"><div><span>Activities</span><strong>{{ calendar.activities?.length || 0 }}</strong></div><div><span>Closing Dates</span><strong>{{ calendar.opportunity_closings?.length || 0 }}</strong></div><div><span>Quote Expiry</span><strong>{{ calendar.quotation_expiries?.length || 0 }}</strong></div></div>
                <div class="table-wrapper"><table><thead><tr><th>Date</th><th>Type</th><th>Title</th><th>Status</th></tr></thead><tbody><tr v-for="a in calendar.activities" :key="'a'+a.id"><td>{{ a.activity_date }}</td><td>{{ a.activity_type }}</td><td>{{ a.subject }}</td><td>{{ a.status }}</td></tr><tr v-for="o in calendar.opportunity_closings" :key="'o'+o.id"><td>{{ o.expected_closing_date }}</td><td>closing</td><td>{{ o.opportunity_name }}</td><td>{{ o.status }}</td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'reports'" class="panel">
                <div class="actions"><button @click="exportCsv(reports.lead_register, 'crm-leads.csv')">Export Leads</button><button @click="exportCsv(reports.opportunity_register, 'crm-opportunities.csv')">Export Opportunities</button><button @click="exportCsv(reports.follow_up_report, 'crm-followups.csv')">Export Follow-ups</button></div>
                <div class="table-wrapper"><table><thead><tr><th>Report</th><th>Count</th></tr></thead><tbody><tr><td>Lead Register</td><td>{{ reports.lead_register?.length || 0 }}</td></tr><tr><td>Opportunity Register</td><td>{{ reports.opportunity_register?.length || 0 }}</td></tr><tr><td>Follow-up Report</td><td>{{ reports.follow_up_report?.length || 0 }}</td></tr><tr><td>Sales Forecast Rows</td><td>{{ reports.sales_forecast?.length || 0 }}</td></tr></tbody></table></div>
            </section>

            <section v-if="tab === 'masters'" class="panel">
                <div class="form-grid"><input v-model="source.source_code" placeholder="Source code" /><input v-model="source.source_name" placeholder="Source name" /><select v-model="source.source_type"><option>organic</option><option>paid</option><option>referral</option><option>partner</option><option>offline</option><option>direct</option><option>internal</option></select><button :disabled="saving" @click="saveSource">Save Source</button></div>
                <div class="form-grid"><input v-model="campaign.campaign_code" placeholder="Campaign code" /><input v-model="campaign.campaign_name" placeholder="Campaign name" /><select v-model="campaign.campaign_type"><option>digital</option><option>email</option><option>whatsapp</option><option>sms</option><option>event</option><option>referral</option><option>offline</option><option>partner</option><option>other</option></select><input v-model="campaign.start_date" type="date" /><input v-model.number="campaign.budget_amount" type="number" step="0.01" /><button :disabled="saving" @click="saveCampaign">Save Campaign</button></div>
                <div class="table-wrapper"><table><thead><tr><th>Sources</th><th>Type</th><th>Status</th></tr></thead><tbody><tr v-for="s in refs.sources" :key="s.id"><td>{{ s.source_name }}</td><td>{{ s.source_type }}</td><td>{{ s.status }}</td></tr></tbody></table></div>
            </section>
        </div>
    </Layout>
</template>

<style scoped>
.crm-page{padding:4px 0 28px}.page-heading,.tabs,.actions,.filters{display:flex;align-items:center;gap:12px}.page-heading{justify-content:space-between;margin-bottom:18px}.page-heading span{color:#2457d6;font-size:10px;font-weight:800;letter-spacing:1.2px}.page-heading h1{margin:0;color:#142139}.page-heading p{margin:6px 0 0;color:#758197;font-size:13px}.filters,.tabs{flex-wrap:wrap;margin-bottom:14px}.filters label,.form-grid label,.line-grid label{display:grid;gap:5px;min-width:0}.filters label{min-width:170px}.filters span,.form-grid span,.line-grid span{color:#69758a;font-size:10px;font-weight:800;text-transform:uppercase}.check{display:flex!important;align-items:center;gap:8px;color:#344159;font-size:12px}.check input{min-height:auto}.tabs button.active{background:#173b77;color:#fff;border-color:#173b77}.panel{margin-bottom:18px;padding:18px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.cards div{padding:14px;border:1px solid #edf1f5;border-radius:8px}.cards span{display:block;color:#69758a;font-size:11px}.cards strong{display:block;margin-top:6px;color:#142139;font-size:18px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.line-grid{display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr .7fr .7fr;gap:8px;align-items:end;margin-bottom:8px}.contact-row{grid-template-columns:1fr 1fr 1fr 1fr .7fr .7fr}.actions{justify-content:flex-end;flex-wrap:wrap;margin:12px 0}input,select,textarea,button{min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}textarea{min-height:38px}button{font-weight:750;cursor:pointer}button:disabled{cursor:not-allowed;opacity:.65}.alert,.empty{padding:10px 12px;margin-bottom:12px;border-radius:8px;font-size:12px}.alert{background:#fff4f4;color:#b42318;border:1px solid #ffd5d5}.empty{display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f8fafc;color:#69758a;border:1px dashed #cbd5e1}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{padding:11px 10px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap;font-size:12px}th{color:#69758a;background:#f8fafc;font-size:10px;text-transform:uppercase}.kanban{display:grid;grid-template-columns:repeat(4,minmax(240px,1fr));gap:12px;overflow-x:auto}.kanban-col{min-width:240px;background:#fff;border:1px solid #dfe6ef;border-radius:8px;padding:12px}.kanban-col h3{display:flex;justify-content:space-between;margin:0;color:#142139;font-size:14px}.kanban-col p{margin:4px 0 10px;color:#69758a;font-size:12px}.opp-card{display:grid;gap:5px;padding:10px;margin-bottom:10px;border:1px solid #edf1f5;border-radius:8px;background:#fbfdff}.opp-card strong{font-size:11px;color:#2457d6}.opp-card b{font-size:13px;color:#142139}.opp-card span,.opp-card small{font-size:12px;color:#69758a}@media(max-width:1000px){.cards,.form-grid,.line-grid,.contact-row,.kanban{grid-template-columns:1fr}.page-heading{align-items:stretch;flex-direction:column}.filters label{min-width:100%}.empty{align-items:stretch;flex-direction:column}}
</style>
