<template>
  <Layout :page="page" :title="title">
    <template #topbar-title>
      <div class="bill-page-title"><span>Admin</span><h1>Onboarding</h1><p>Finish these steps once, then start daily billing.</p></div>
    </template>

    <div class="ob">
      <section class="ob-progress">
        <div class="ob-progress-head">
          <div>
            <h2>{{ business.name }}</h2>
            <p>{{ progress.required_completed }} of {{ progress.required_total }} required steps completed<template v-if="business.financial_year"> · FY {{ business.financial_year }}</template></p>
          </div>
          <strong>{{ progress.progress_percentage }}%</strong>
        </div>
        <div class="ob-bar" role="progressbar" :aria-valuenow="progress.progress_percentage" aria-valuemin="0" aria-valuemax="100"><span :style="{ width: `${progress.progress_percentage}%` }"></span></div>
      </section>

      <section v-if="nextStep" class="ob-next">
        <div>
          <span>{{ nextStep.key === 'done' ? 'All done' : 'Do this next' }}</span>
          <h3>{{ nextStep.title }}</h3>
          <p>{{ nextStep.description }}</p>
        </div>
        <a v-if="nextStep.action_url" :href="nextStep.action_url">{{ nextStep.action_label }}</a>
      </section>

      <section v-for="group in groups" :key="group.key" class="ob-panel">
        <div class="ob-panel-head">
          <h3>{{ group.title }} <em>{{ group.steps.length }}</em></h3>
          <button v-if="group.key === groups[0].key" type="button" :disabled="refreshing" @click="refresh">{{ refreshing ? 'Refreshing…' : 'Refresh' }}</button>
        </div>
        <article v-for="step in group.steps" :key="step.key" class="ob-step" :class="step.status">
          <b aria-hidden="true">{{ step.status === 'completed' ? '✓' : step.number }}</b>
          <div>
            <h4>{{ step.title }} <span v-if="step.status !== 'completed'" class="ob-tag">{{ step.status_label }}</span></h4>
            <p>{{ step.description }}</p>
            <small>{{ step.detail }}</small>
          </div>
          <a v-if="step.action_url" :href="step.action_url">{{ step.action_label }}</a>
        </article>
      </section>
    </div>
  </Layout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Layout from '../../Layout.vue';

const props = defineProps({
  page: { type: String, required: true },
  title: { type: String, required: true },
  business: { type: Object, default: () => ({}) },
  summary: { type: Array, default: () => [] },
  steps: { type: Array, default: () => [] },
  progress: { type: Object, default: () => ({}) },
  readiness: { type: Object, default: () => ({}) },
  nextStep: { type: Object, default: null },
  filters: { type: Object, default: () => ({ active: 'all', options: [] }) },
  permissions: { type: Object, default: () => ({}) },
  routes: { type: Object, default: () => ({}) },
});

const refreshing = ref(false);

// Pending work first, then optional steps, then what is already done.
const groups = computed(() => {
  const steps = (props.steps || []).filter((step) => step.status !== 'coming_soon');
  const done = (step) => ['completed', 'skipped'].includes(step.status);
  return [
    { key: 'todo', title: 'To do', steps: steps.filter((step) => !done(step) && step.required) },
    { key: 'optional', title: 'Optional', steps: steps.filter((step) => !done(step) && !step.required) },
    { key: 'done', title: 'Completed', steps: steps.filter(done) },
  ].filter((group) => group.steps.length);
});

const refresh = () => {
  refreshing.value = true;
  router.reload({
    only: ['summary', 'steps', 'progress', 'readiness', 'nextStep', 'filters', 'permissions', 'routes'],
    preserveState: true,
    preserveScroll: true,
    onFinish: () => {
      refreshing.value = false;
    },
  });
};
</script>

<style scoped>
.ob{display:grid;gap:16px;padding-bottom:28px}
.ob-progress,.ob-panel{padding:16px 18px;background:#fff;border:1px solid #dfe6ef;border-radius:12px}
.ob-progress-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
.ob-progress h2{margin:0;color:#142139;font-size:18px;font-weight:800}
.ob-progress p{margin:4px 0 0;color:#667085;font-size:13px}
.ob-progress-head strong{color:#2457d6;font-size:30px;font-weight:900}
.ob-bar{height:10px;margin-top:12px;overflow:hidden;background:#eef2f7;border-radius:999px}
.ob-bar span{display:block;height:100%;background:#22c55e;border-radius:999px}
.ob-next{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:16px 18px;color:#fff;background:#2457d6;border-radius:12px}
.ob-next span{color:#dbe6ff;font-size:11px;font-weight:800;text-transform:uppercase}
.ob-next h3{margin:3px 0;font-size:17px;font-weight:800}
.ob-next p{margin:0;color:#e6edff;font-size:13px}
.ob-next a{display:inline-flex;align-items:center;min-height:42px;padding:0 18px;color:#2457d6;background:#fff;border-radius:10px;font-size:13px;font-weight:800;text-decoration:none}
.ob-panel-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
.ob-panel h3{margin:0;color:#142139;font-size:15px;font-weight:800}
.ob-panel h3 em{margin-left:6px;padding:2px 8px;color:#475569;background:#eef2f7;border-radius:999px;font-size:11px;font-style:normal}
.ob-panel-head button{min-height:34px;padding:6px 12px;color:#344159;background:#fff;border:1px solid #d8e0eb;border-radius:8px;font-size:12px;font-weight:800;cursor:pointer}
.ob-step{display:grid;grid-template-columns:36px minmax(0,1fr) auto;align-items:center;gap:12px;padding:12px 0;border-top:1px solid #eef2f7}
.ob-step>b{display:grid;place-items:center;width:34px;height:34px;color:#b45309;background:#fff7ed;border-radius:50%;font-size:13px}
.ob-step.completed>b{color:#15803d;background:#e7f8ef}
.ob-step.optional>b{color:#475569;background:#eef2f7}
.ob-step h4{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0;color:#142139;font-size:14px;font-weight:800}
.ob-tag{padding:2px 8px;color:#b45309;background:#fff7ed;border-radius:999px;font-size:10px;font-weight:800}
.ob-step p{margin:3px 0 0;color:#475569;font-size:12px}
.ob-step small{color:#8490a2;font-size:11px}
.ob-step a{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 14px;color:#2457d6;background:#edf4ff;border:1px solid #cfe0ff;border-radius:8px;font-size:12px;font-weight:800;text-decoration:none;white-space:nowrap}
@media(max-width:600px){.ob-step{grid-template-columns:36px minmax(0,1fr)}.ob-step a{grid-column:1/-1}}
</style>
