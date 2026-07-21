import axios from 'axios';

const base = '/app/crm';

const CrmApi = {
    async references() { const r = await axios.get(`${base}/references`); return r.data; },
    async dashboard(params = {}) { const r = await axios.get(`${base}/dashboard`, { params }); return r.data; },
    async leads(params = {}) { const r = await axios.get(`${base}/leads/list`, { params }); return r.data; },
    async saveLead(payload, id = null) { const r = id ? await axios.put(`${base}/leads/${id}`, payload) : await axios.post(`${base}/leads`, payload); return r.data; },
    async assignLead(id, payload) { const r = await axios.post(`${base}/leads/${id}/assign`, payload); return r.data; },
    async bulkAssign(payload) { const r = await axios.post(`${base}/leads/bulk-assign`, payload); return r.data; },
    async qualifyLead(id, payload) { const r = await axios.post(`${base}/leads/${id}/qualify`, payload); return r.data; },
    async convertLead(id, payload = {}) { const r = await axios.post(`${base}/leads/${id}/convert`, payload); return r.data; },
    async opportunities(params = {}) { const r = await axios.get(`${base}/opportunities/list`, { params }); return r.data; },
    async saveOpportunity(payload, id = null) { const r = id ? await axios.put(`${base}/opportunities/${id}`, payload) : await axios.post(`${base}/opportunities`, payload); return r.data; },
    async moveOpportunity(id, payload) { const r = await axios.post(`${base}/opportunities/${id}/move`, payload); return r.data; },
    async opportunityQuotation(id) { const r = await axios.post(`${base}/opportunities/${id}/quotation`); return r.data; },
    async activities(params = {}) { const r = await axios.get(`${base}/activities/list`, { params }); return r.data; },
    async saveActivity(payload, id = null) { const r = id ? await axios.put(`${base}/activities/${id}`, payload) : await axios.post(`${base}/activities`, payload); return r.data; },
    async kanban(params = {}) { const r = await axios.get(`${base}/kanban`, { params }); return r.data; },
    async calendar(params = {}) { const r = await axios.get(`${base}/calendar`, { params }); return r.data; },
    async reports(params = {}) { const r = await axios.get(`${base}/reports`, { params }); return r.data; },
    async saveMaster(type, payload, id = null) { const r = id ? await axios.put(`${base}/masters/${type}/${id}`, payload) : await axios.post(`${base}/masters/${type}`, payload); return r.data; },
};

export default CrmApi;
