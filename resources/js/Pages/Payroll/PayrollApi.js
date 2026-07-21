import axios from 'axios';

const base = '/app/payroll';

const PayrollApi = {
    async references() { const r = await axios.get(`${base}/references`); return r.data; },
    async dashboard() { const r = await axios.get(`${base}/dashboard`); return r.data; },
    async reports() { const r = await axios.get(`${base}/reports`); return r.data; },
    async saveSettings(payload) { const r = await axios.post(`${base}/settings`, payload); return r.data; },
    async employees(params = {}) { const r = await axios.get(`${base}/employees/list`, { params }); return r.data; },
    async saveEmployee(payload, id = null) { const r = id ? await axios.put(`${base}/employees/${id}`, payload) : await axios.post(`${base}/employees`, payload); return r.data; },
    async saveMaster(type, payload, id = null) { const r = id ? await axios.put(`${base}/masters/${type}/${id}`, payload) : await axios.post(`${base}/masters/${type}`, payload); return r.data; },
    async attendance(params = {}) { const r = await axios.get(`${base}/attendance/list`, { params }); return r.data; },
    async saveAttendance(payload, id = null) { const r = id ? await axios.put(`${base}/attendance/${id}`, payload) : await axios.post(`${base}/attendance`, payload); return r.data; },
    async saveSalaryStructure(payload, id = null) { const r = id ? await axios.put(`${base}/salary-structures/${id}`, payload) : await axios.post(`${base}/salary-structures`, payload); return r.data; },
    async assignSalary(payload) { const r = await axios.post(`${base}/salary-assignments`, payload); return r.data; },
    async runs(params = {}) { const r = await axios.get(`${base}/runs/list`, { params }); return r.data; },
    async createRun(payload) { const r = await axios.post(`${base}/runs`, payload); return r.data; },
    async postRun(id) { const r = await axios.post(`${base}/runs/${id}/post`); return r.data; },
    async payslips(id) { const r = await axios.post(`${base}/runs/${id}/payslips`); return r.data; },
    async paymentBatch(payload) { const r = await axios.post(`${base}/payment-batches`, payload); return r.data; },
    async advance(payload) { const r = await axios.post(`${base}/advances`, payload); return r.data; },
    async loan(payload) { const r = await axios.post(`${base}/loans`, payload); return r.data; },
};

export default PayrollApi;
