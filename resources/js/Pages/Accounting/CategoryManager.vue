<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import AccountingApi from './AccountingApi';
const props = defineProps({ refs: { type: Object, required: true } });
const emit = defineEmits(['saved']);
const kinds = ['expense', 'income'];
const title = (kind) => kind === 'expense' ? 'Expense' : 'Income';
const blank = (kind) => ({ id: null, category_code: '', category_name: '', [`${kind}_account_id`]: '', parent_id: null, description: '', status: 'active' });
const state = reactive(Object.fromEntries(kinds.map(kind => [kind, { form: blank(kind), rows: [], errors: {}, busy: false, message: '', pagination: {} }])));
const headings = ref({});
const api = (kind, save = false) => AccountingApi[`${save ? 'save' + title(kind) + 'Category' : kind + 'Categories'}`];
async function load(kind, page = 1) {
    try { const result = await api(kind)({ page }); state[kind].rows = result.categories; state[kind].pagination = result.pagination; }
    catch (error) { state[kind].errors = { form: [error?.response?.data?.message || 'Unable to load categories.'] }; }
}
function reset(kind) { state[kind].form = blank(kind); state[kind].errors = {}; }
function edit(kind, row) {
    reset(kind);
    Object.keys(state[kind].form).forEach(key => { state[kind].form[key] = row[key] ?? blank(kind)[key]; });
    state[kind].message = '';
    headings.value[kind]?.focus();
}
async function save(kind, row = null) {
    const current = state[kind]; current.busy = true; current.errors = {}; current.message = '';
    try {
        const payload = row ? { ...row, status: row.status === 'active' ? 'inactive' : 'active' } : { ...current.form };
        await api(kind, true)(payload, payload.id);
        if (!row || current.form.id === row.id) reset(kind);
        current.message = row ? 'Category status updated.' : `${title(kind)} category saved.`;
        await load(kind, row ? current.pagination.current_page : 1);
        emit('saved');
    } catch (error) { current.errors = error?.response?.data?.errors || { form: [error?.response?.data?.message || 'Unable to save category.'] }; }
    finally { current.busy = false; }
}
onMounted(() => kinds.forEach(kind => load(kind)));
watch(() => props.refs, () => kinds.forEach(kind => load(kind, state[kind].pagination.current_page || 1)));
</script>

<template>
    <div class="categories">
        <div class="category-forms">
            <form v-for="kind in kinds" :key="kind" class="category-card" @submit.prevent="save(kind)">
                <h3 :ref="el => headings[kind] = el" tabindex="-1">{{ title(kind) }} Categories</h3>
                <p class="helper">{{ kind === 'expense' ? 'Create categories for money going out and map them to an expense ledger.' : 'Create categories for other income and map them to an income ledger.' }}</p>
                <p v-if="state[kind].form.id" class="helper">Editing {{ state[kind].form.category_name }}</p>
                <div v-if="Object.keys(state[kind].errors).length" role="alert" class="error"><p v-for="(messages, field) in state[kind].errors" :key="field">{{ messages[0] }}</p></div>
                <p v-if="state[kind].message" role="status" class="success">{{ state[kind].message }}</p>
                <div class="fields">
                    <label>Code<input v-model="state[kind].form.category_code" required maxlength="50" :disabled="state[kind].busy" /></label>
                    <label>Name<input v-model="state[kind].form.category_name" required maxlength="255" :disabled="state[kind].busy" /></label>
                    <label class="account">{{ title(kind) }} Account<select v-model="state[kind].form[`${kind}_account_id`]" required :disabled="state[kind].busy"><option value="">Select {{ kind }} ledger</option><option v-for="account in props.refs[`category_${kind}_accounts`] || []" :key="account.id" :value="account.id">{{ account.account_code }} — {{ account.account_name }}</option></select></label>
                </div>
                <div class="actions"><button class="primary" :disabled="state[kind].busy">{{ state[kind].busy ? 'Saving…' : `Save ${title(kind)} Category` }}</button><button v-if="state[kind].form.id" type="button" :disabled="state[kind].busy" @click="reset(kind)">Cancel Edit</button></div>
            </form>
        </div>
        <section v-for="kind in kinds" :key="kind" class="category-card">
            <h3>Existing {{ title(kind) }} Categories</h3>
            <div class="table-wrapper"><table><thead><tr><th>Code</th><th>Category Name</th><th>Mapped Account</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                <tr v-for="row in state[kind].rows" :key="row.id"><td>{{ row.category_code }}</td><td>{{ row.category_name }}</td><td>{{ row.account ? `${row.account.account_code} — ${row.account.account_name}` : 'Account unavailable' }}</td><td><span class="badge" :class="row.status">{{ row.status }}</span></td><td class="row-actions"><button :disabled="state[kind].busy" @click="edit(kind, row)">Edit</button><button :disabled="state[kind].busy" @click="save(kind, row)">{{ row.status === 'active' ? 'Deactivate' : 'Activate' }}</button></td></tr>
                <tr v-if="!state[kind].rows.length"><td colspan="5">No {{ kind }} categories yet.</td></tr>
            </tbody></table></div>
            <div v-if="state[kind].pagination.last_page > 1" class="actions"><button :disabled="state[kind].pagination.current_page <= 1" @click="load(kind, state[kind].pagination.current_page - 1)">Previous</button><span>Page {{ state[kind].pagination.current_page }} of {{ state[kind].pagination.last_page }}</span><button :disabled="state[kind].pagination.current_page >= state[kind].pagination.last_page" @click="load(kind, state[kind].pagination.current_page + 1)">Next</button></div>
        </section>
    </div>
</template>

<style scoped>
.categories{display:grid;gap:18px}.category-forms{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.category-card{min-width:0;padding:22px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}h3{margin:0 0 8px;color:#142139;font-size:16px}.helper{color:#69758a;font-size:13px;margin:0 0 20px;line-height:1.5}.fields{display:grid;grid-template-columns:1fr 1fr;gap:16px}label{display:grid;gap:7px;min-width:0;font-size:12px;font-weight:650;color:#344159}.account{grid-column:1/-1}input,select,button{min-width:0;min-height:38px;padding:8px 10px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px}button{cursor:pointer;font-weight:750}button:disabled{opacity:.55;cursor:not-allowed}.primary{background:#173b77;border-color:#173b77;color:white}.actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:20px}.table-wrapper{overflow-x:auto}table{width:100%;border-collapse:collapse;margin-top:16px}th,td{padding:11px 10px;border-bottom:1px solid #edf1f5;text-align:left;font-size:12px}th{background:#f8fafc;color:#69758a;white-space:nowrap}.row-actions{display:flex;gap:8px}.badge{display:inline-block;padding:4px 8px;border-radius:12px;background:#f1f5f9;text-transform:capitalize}.badge.active,.success{color:#16704a;background:#edf9f2}.error{color:#b42318;background:#fff4f4;padding:8px 12px}.success{padding:10px}.error p{margin:4px 0}@media(max-width:1000px){.category-forms{grid-template-columns:1fr}}@media(max-width:480px){.fields{grid-template-columns:1fr}.category-card{padding:16px}}
</style>
