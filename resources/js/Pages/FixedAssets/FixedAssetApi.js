import axios from 'axios';

const base = '/app/fixed-assets';

const FixedAssetApi = {
    async references() { const r = await axios.get(`${base}/references`); return r.data; },
    async dashboard(params = {}) { const r = await axios.get(`${base}/dashboard`, { params }); return r.data; },
    async reports() { const r = await axios.get(`${base}/reports`); return r.data; },
    async saveSettings(payload) { const r = await axios.post(`${base}/settings`, payload); return r.data; },
    async categories(params = {}) { const r = await axios.get(`${base}/categories/list`, { params }); return r.data; },
    async saveCategory(payload, id = null) { const r = id ? await axios.put(`${base}/categories/${id}`, payload) : await axios.post(`${base}/categories`, payload); return r.data; },
    async locations(params = {}) { const r = await axios.get(`${base}/locations/list`, { params }); return r.data; },
    async saveLocation(payload, id = null) { const r = id ? await axios.put(`${base}/locations/${id}`, payload) : await axios.post(`${base}/locations`, payload); return r.data; },
    async assets(params = {}) { const r = await axios.get(`${base}/assets/list`, { params }); return r.data; },
    async saveAsset(payload, id = null) { const r = id ? await axios.put(`${base}/assets/${id}`, payload) : await axios.post(`${base}/assets`, payload); return r.data; },
    async acquisitions(params = {}) { const r = await axios.get(`${base}/acquisitions/list`, { params }); return r.data; },
    async saveAcquisition(payload, id = null) { const r = id ? await axios.put(`${base}/acquisitions/${id}`, payload) : await axios.post(`${base}/acquisitions`, payload); return r.data; },
    async postAcquisition(id) { const r = await axios.post(`${base}/acquisitions/${id}/post`); return r.data; },
    async capitalize(payload) { const r = await axios.post(`${base}/capitalizations`, payload); return r.data; },
    async depreciationRuns(params = {}) { const r = await axios.get(`${base}/depreciation-runs/list`, { params }); return r.data; },
    async saveDepreciationRun(payload) { const r = await axios.post(`${base}/depreciation-runs`, payload); return r.data; },
    async postDepreciation(id) { const r = await axios.post(`${base}/depreciation-runs/${id}/post`); return r.data; },
    async assign(payload) { const r = await axios.post(`${base}/assignments`, payload); return r.data; },
    async transfer(payload, id = null) { const r = id ? await axios.put(`${base}/transfers/${id}`, payload) : await axios.post(`${base}/transfers`, payload); return r.data; },
    async maintenance(payload, id = null) { const r = id ? await axios.put(`${base}/maintenance/${id}`, payload) : await axios.post(`${base}/maintenance`, payload); return r.data; },
    async simple(type, payload) { const r = await axios.post(`${base}/simple/${type}`, payload); return r.data; },
    async revalue(payload) { const r = await axios.post(`${base}/revaluations`, payload); return r.data; },
    async impair(payload) { const r = await axios.post(`${base}/impairments`, payload); return r.data; },
    async dispose(payload) { const r = await axios.post(`${base}/disposals`, payload); return r.data; },
    async verification(payload) { const r = await axios.post(`${base}/verifications`, payload); return r.data; },
};

export default FixedAssetApi;
