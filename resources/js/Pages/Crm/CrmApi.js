import axios from 'axios';

let endpoints = {};
const fallbackBase = () => `${window.location.pathname.split('/crm')[0]}/crm`;
const url = (key, fallback) => endpoints[key] || `${fallbackBase()}${fallback}`;
const withId = (key, fallback, id) => url(key, fallback).replace('__ID__', id);

const CrmApi = {
    configure(routes = {}) { endpoints = routes || {}; },
    exportUrl(key, params = {}) {
        const target = new URL(url(key, key === 'opportunityExport' ? '/exports/opportunities' : key === 'followUpExport' ? '/exports/follow-ups' : '/exports/leads'), window.location.origin);
        Object.entries(params).forEach(([name, value]) => { if (value !== '' && value !== null && value !== undefined) target.searchParams.set(name, value); });
        return target.pathname + target.search;
    },
    async references() { const r = await axios.get(url('references', '/references')); return r.data; },
    async dashboard(params = {}) { const r = await axios.get(url('dashboard', '/dashboard'), { params }); return r.data; },
    async leads(params = {}) { const r = await axios.get(url('leads', '/leads/list'), { params }); return r.data; },
    async saveLead(payload, id = null) { const r = id ? await axios.put(withId('leadUpdate', '/leads/__ID__', id), payload) : await axios.post(url('leadStore', '/leads'), payload); return r.data; },
    async assignLead(id, payload) { const r = await axios.post(withId('leadAssign', '/leads/__ID__/assign', id), payload); return r.data; },
    async bulkAssign(payload) { const r = await axios.post(url('leadBulkAssign', '/leads/bulk-assign'), payload); return r.data; },
    async qualifyLead(id, payload) { const r = await axios.post(withId('leadQualify', '/leads/__ID__/qualify', id), payload); return r.data; },
    async convertLead(id, payload = {}) { const r = await axios.post(withId('leadConvert', '/leads/__ID__/convert', id), payload); return r.data; },
    async opportunities(params = {}) { const r = await axios.get(url('opportunities', '/opportunities/list'), { params }); return r.data; },
    async saveOpportunity(payload, id = null) { const r = id ? await axios.put(withId('opportunityUpdate', '/opportunities/__ID__', id), payload) : await axios.post(url('opportunityStore', '/opportunities'), payload); return r.data; },
    async moveOpportunity(id, payload) { const r = await axios.post(withId('opportunityMove', '/opportunities/__ID__/move', id), payload); return r.data; },
    async markOpportunityWon(id, payload = {}) { const r = await axios.post(withId('opportunityMarkWon', '/opportunities/__ID__/mark-won', id), payload); return r.data; },
    async markOpportunityLost(id, payload = {}) { const r = await axios.post(withId('opportunityMarkLost', '/opportunities/__ID__/mark-lost', id), payload); return r.data; },
    async opportunityQuotation(id) { const r = await axios.post(withId('opportunityQuotation', '/opportunities/__ID__/quotation', id)); return r.data; },
    async activities(params = {}) { const r = await axios.get(url('activities', '/activities/list'), { params }); return r.data; },
    async saveActivity(payload, id = null) { const r = id ? await axios.put(withId('activityUpdate', '/activities/__ID__', id), payload) : await axios.post(url('activityStore', '/activities'), payload); return r.data; },
    async completeActivity(id, payload) { const r = await axios.post(withId('activityComplete', '/activities/__ID__/complete', id), payload); return r.data; },
    async cancelActivity(id, payload = {}) { const r = await axios.post(withId('activityCancel', '/activities/__ID__/cancel', id), payload); return r.data; },
    async kanban(params = {}) { const r = await axios.get(url('kanban', '/kanban'), { params }); return r.data; },
    async calendar(params = {}) { const r = await axios.get(url('calendar', '/calendar'), { params }); return r.data; },
    async reports(params = {}) { const r = await axios.get(url('reports', '/reports'), { params }); return r.data; },
    async saveMaster(type, payload, id = null) { const r = id ? await axios.put(`${fallbackBase()}/masters/${type}/${id}`, payload) : await axios.post(`${fallbackBase()}/masters/${type}`, payload); return r.data; },
};

export default CrmApi;
