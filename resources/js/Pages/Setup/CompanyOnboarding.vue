<script setup>
import { reactive, ref } from 'vue';
import axios from 'axios';
import Layout from '../Layout.vue';

const props = defineProps({
  page: String,
  title: String,
  draft: { type: Object, default: () => ({}) },
  users: { type: Array, default: () => [] },
  verification: { type: Object, default: () => ({ checks: [], warnings: [] }) },
});

const form = reactive({
  ...props.draft,
  authorized_user_ids: props.users.map((user) => user.id),
});
const state = ref({ loading: false, message: '', errors: {}, verification: props.verification });

const validateDraft = async () => {
  state.value.loading = true;
  state.value.errors = {};
  state.value.message = '';
  try {
    const response = await axios.post('/app/setup/companies/validate-draft', form);
    state.value.message = response.data.message;
    state.value.verification = response.data.verification;
  } catch (error) {
    state.value.errors = error.response?.data?.errors || { general: [error.response?.data?.message || 'Draft validation failed.'] };
  } finally {
    state.value.loading = false;
  }
};

const toggleUser = (id) => {
  const index = form.authorized_user_ids.indexOf(id);
  if (index >= 0) form.authorized_user_ids.splice(index, 1);
  else form.authorized_user_ids.push(id);
};
</script>

<template>
  <Layout :page="page" :title="title">
    <template #topbar-title>
      <div class="bill-page-title">
        <span>CLIENT ONBOARDING</span>
        <h1>{{ title }}</h1>
        <p>Prepare and verify a company profile before any database company is created.</p>
      </div>
    </template>

    <section class="onboard-shell">
      <article class="profile-panel">
        <header>
          <div>
            <span>Draft Profile</span>
            <h2>PHOOLTAS preparation</h2>
          </div>
          <button type="button" :disabled="state.loading" @click="validateDraft">{{ state.loading ? 'Checking...' : 'Validate Draft' }}</button>
        </header>

        <div class="form-grid">
          <label><span>Legal Name</span><input v-model="form.legal_name" /></label>
          <label><span>GSTIN</span><input v-model="form.gstin" maxlength="15" /></label>
          <label><span>State</span><input v-model="form.state" /></label>
          <label><span>State Code</span><input v-model="form.state_code" maxlength="2" /></label>
          <label><span>Financial Year</span><input v-model="form.financial_year" /></label>
          <label><span>Period Start</span><input v-model="form.period_start_date" type="date" /></label>
          <label><span>Period End</span><input v-model="form.period_end_date" type="date" /></label>
          <label><span>Default Branch</span><input v-model="form.default_branch_name" /></label>
          <label><span>Branch Code</span><input v-model="form.default_branch_code" /></label>
          <label><span>Default Warehouse</span><input v-model="form.default_warehouse_name" /></label>
          <label><span>Warehouse Code</span><input v-model="form.default_warehouse_code" /></label>
          <label class="span-2"><span>Registered Address</span><textarea v-model="form.registered_address" placeholder="Fill only after source document verification"></textarea></label>
        </div>

        <section class="user-access">
          <h3>Authorized Users</h3>
          <button v-for="user in users" :key="user.id" type="button" :class="{ active: form.authorized_user_ids.includes(user.id) }" @click="toggleUser(user.id)">
            {{ user.name }} · Role {{ user.role_id }}
          </button>
        </section>
      </article>

      <aside class="verify-panel">
        <h3>Verification</h3>
        <button class="verify-action" type="button" :disabled="state.loading" @click="validateDraft">{{ state.loading ? 'Checking...' : 'Validate Draft' }}</button>
        <div v-for="check in state.verification.checks || []" :key="check.label" class="check-row" :class="check.status">
          <span>{{ check.label }}</span>
          <strong>{{ check.status }}</strong>
        </div>
        <div class="notice">
          <strong>No company will be created from this screen.</strong>
          <p>{{ state.verification.create_policy }}</p>
        </div>
        <div v-if="state.verification.warnings?.length" class="warnings">
          <span v-for="warning in state.verification.warnings" :key="warning">{{ warning }}</span>
        </div>
        <div v-if="Object.keys(state.errors).length" class="errors">
          <span v-for="(messages, field) in state.errors" :key="field">{{ messages[0] }}</span>
        </div>
        <div v-if="state.message" class="success">{{ state.message }}</div>
      </aside>
    </section>
  </Layout>
</template>

<style scoped>
.onboard-shell{display:grid;grid-template-columns:1fr 340px;gap:18px}.profile-panel,.verify-panel{background:#fff;border:1px solid #dfe6ef;border-radius:8px;padding:18px}.profile-panel header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:16px}.profile-panel header span{color:#2457d6;font-size:11px;font-weight:900;letter-spacing:.08em}.profile-panel h2,.verify-panel h3,.user-access h3{margin:0;color:#142139}.profile-panel header button,.user-access button,.verify-action{min-height:38px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;color:#344159;font-weight:850;padding:8px 12px}.profile-panel header button,.verify-action{background:#2457d6;border-color:#2457d6;color:#fff}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.form-grid label{display:grid;gap:6px}.form-grid span{color:#5f6d82;font-size:11px;font-weight:850}.form-grid input,.form-grid textarea{border:1px solid #d8e0eb;border-radius:8px;min-height:40px;padding:9px 10px}.form-grid textarea{min-height:96px}.span-2{grid-column:span 2}.user-access{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}.user-access h3{width:100%;font-size:14px}.user-access button.active{background:#142139;color:#fff}.verify-panel{display:grid;align-content:start;gap:10px}.verify-action{width:100%;justify-content:center}.check-row{display:flex;justify-content:space-between;gap:12px;border:1px solid #e4eaf2;border-radius:8px;padding:10px}.check-row span{color:#344159}.check-row strong{text-transform:capitalize}.check-row.passed{background:#ecfdf3;color:#166534}.check-row.pending{background:#fffbeb;color:#92400e}.check-row.blocked{background:#fff1f2;color:#9f1239}.notice,.warnings,.errors,.success{display:grid;gap:6px;border-radius:8px;padding:12px;font-size:12px}.notice{background:#f8fafc;border:1px solid #dfe6ef}.warnings{background:#fffbeb;border:1px solid #fde68a;color:#92400e}.errors{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}.success{background:#ecfdf3;border:1px solid #bbf7d0;color:#166534}@media(max-width:900px){.onboard-shell,.form-grid{grid-template-columns:1fr}.span-2{grid-column:span 1}.profile-panel header{flex-direction:column}}
</style>
