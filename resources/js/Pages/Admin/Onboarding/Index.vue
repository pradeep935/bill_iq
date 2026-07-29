<template>
  <Layout :page="page" :title="title">
    <template #topbar-title>
      <div>
        <h1>Onboarding</h1>
        <p>Complete the required setup steps before starting billing, inventory and accounting.</p>
      </div>
    </template>

    <section class="onboarding-page">
      <header class="onboarding-hero">
        <div>
          <span class="eyebrow">BillIQ Setup Progress</span>
          <h2>{{ business.name }}</h2>
          <p>{{ progress.required_completed }} of {{ progress.required_total }} required steps completed</p>
        </div>
        <div class="hero-progress" :aria-label="`${progress.progress_percentage}% setup complete`">
          <strong>{{ progress.progress_percentage }}%</strong>
          <span>Overall setup status</span>
        </div>
      </header>

      <section class="context-row">
        <div>
          <span>Business</span>
          <strong>{{ business.name }}</strong>
        </div>
        <div>
          <span>Current Branch</span>
          <strong>{{ business.branch || 'No active branch yet' }}</strong>
        </div>
        <div>
          <span>Financial Year</span>
          <strong>{{ business.financial_year || 'Not configured' }}</strong>
        </div>
        <div class="context-actions">
          <button type="button" class="secondary-button" :disabled="refreshing" @click="refresh">
            {{ refreshing ? 'Refreshing...' : 'Refresh' }}
          </button>
          <a v-if="permissions['masters.manage'] && routes.masters?.url" class="secondary-button" :href="routes.masters.url">Open Masters</a>
          <a v-if="permissions['employees.view'] && routes.employees?.url" class="secondary-button" :href="routes.employees.url">Employees</a>
        </div>
      </section>

      <section class="summary-grid">
        <article v-for="card in summary" :key="card.key" class="summary-card">
          <span>{{ card.label }}</span>
          <strong>{{ card.value }}</strong>
          <small>{{ card.detail }}</small>
        </article>
      </section>

      <section class="readiness-grid">
        <article v-for="item in readinessList" :key="item.key" class="readiness-card">
          <div>
            <span>{{ item.label }}</span>
            <strong>{{ readinessStatusLabel(item.status) }}</strong>
          </div>
          <span class="status-badge" :class="`status-${item.status}`">{{ readinessStatusLabel(item.status) }}</span>
        </article>
      </section>

      <section v-if="nextStep" class="next-step">
        <div>
          <span class="eyebrow">Next Recommended Step</span>
          <h3>{{ nextStep.title }}</h3>
          <p>{{ nextStep.description }}</p>
        </div>
        <a v-if="nextStep.action_url" class="primary-button" :href="nextStep.action_url">{{ nextStep.action_label }}</a>
      </section>

      <nav class="filter-row" aria-label="Onboarding filters">
        <button
          v-for="option in filters.options"
          :key="option.key"
          type="button"
          :class="{ active: filters.active === option.key }"
          @click="setFilter(option.key)"
        >
          {{ option.label }}
        </button>
      </nav>

      <section class="steps-panel">
        <div class="panel-heading">
          <div>
            <h3>Setup Checklist</h3>
            <p>Follow the steps in order. Optional and coming soon steps do not affect required progress.</p>
          </div>
          <span>{{ steps.length }} steps shown</span>
        </div>

        <div v-if="steps.length" class="step-list">
          <article v-for="step in steps" :key="step.key" class="step-row">
            <div class="step-number">{{ String(step.number).padStart(2, '0') }}</div>
            <div class="step-main">
              <div class="step-title-row">
                <div>
                  <h4>{{ step.title }}</h4>
                  <p>{{ step.description }}</p>
                </div>
                <div class="step-tags">
                  <span class="category-tag">{{ categoryLabel(step.category) }}</span>
                  <span class="required-tag" :class="{ optional: !step.required }">{{ step.required ? 'Required' : 'Optional' }}</span>
                </div>
              </div>
              <div class="step-detail-grid">
                <div>
                  <span>Status</span>
                  <strong class="status-badge" :class="`status-${step.status}`">{{ step.status_label }}</strong>
                </div>
                <div>
                  <span>Records</span>
                  <strong>{{ step.record_count }}</strong>
                </div>
                <div>
                  <span>Details</span>
                  <strong>{{ step.detail }}</strong>
                </div>
              </div>
              <div v-if="step.blocked_by?.length" class="blocked-note">
                Complete previous step: {{ step.blocked_by.map(categoryLabel).join(', ') }}
              </div>
            </div>
            <a v-if="step.action_url" class="step-action" :href="step.action_url">{{ step.action_label }}</a>
            <span v-else class="step-action muted">{{ step.action_label }}</span>
          </article>
        </div>

        <div v-else class="empty-state">
          <strong>No onboarding steps match this filter</strong>
          <span>Choose another setup category to continue.</span>
        </div>
      </section>
    </section>
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

const readinessList = computed(() => Object.entries(props.readiness || {}).map(([key, value]) => ({ key, ...value })));

const routeTo = (name, params = {}, fallback = '#') => {
  if (typeof route === 'function' && name) {
    return route(name, params);
  }

  const query = new URLSearchParams(params).toString();
  return query ? `${fallback}?${query}` : fallback;
};

const setFilter = (filter) => {
  router.visit(routeTo(props.routes.onboarding?.name, { filter }, props.routes.onboarding?.url || '/app/admin/onboarding'), {
    preserveScroll: true,
    preserveState: true,
  });
};

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

const categoryLabel = (value) => String(value || '').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

const readinessStatusLabel = (status) => ({
  ready: 'Ready',
  not_ready: 'Not Ready',
  attention_required: 'Attention Required',
}[status] || categoryLabel(status));
</script>

<style scoped>
.onboarding-page {
  display: grid;
  gap: 18px;
  padding: 20px;
}

.onboarding-hero {
  align-items: center;
  background: #123c69;
  color: #fff;
  display: flex;
  justify-content: space-between;
  padding: 24px;
}

.eyebrow,
.context-row span,
.summary-card span,
.summary-card small,
.readiness-card span,
.panel-heading p,
.panel-heading span,
.step-main p,
.step-detail-grid span,
.empty-state span {
  color: #667085;
  display: block;
  font-size: 12px;
}

.onboarding-hero .eyebrow,
.onboarding-hero p,
.hero-progress span {
  color: #dbeafe;
}

.onboarding-hero h2 {
  font-size: 30px;
  margin: 8px 0;
}

.hero-progress {
  border: 1px solid rgba(255, 255, 255, 0.35);
  display: grid;
  gap: 4px;
  min-width: 150px;
  padding: 16px;
  text-align: center;
}

.hero-progress strong {
  font-size: 34px;
}

.context-row,
.summary-card,
.readiness-card,
.next-step,
.steps-panel,
.step-row,
.empty-state {
  background: #fff;
  border: 1px solid #e4e7ec;
}

.context-row {
  align-items: center;
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(3, minmax(0, 1fr)) auto;
  padding: 16px;
}

.context-row strong {
  color: #101828;
  display: block;
  margin-top: 4px;
}

.context-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
}

.primary-button,
.secondary-button,
.step-action {
  align-items: center;
  border: 1px solid transparent;
  display: inline-flex;
  font-weight: 700;
  justify-content: center;
  min-height: 40px;
  padding: 0 14px;
  text-decoration: none;
}

.primary-button,
.step-action {
  background: #2563eb;
  color: #fff;
}

.secondary-button {
  background: #f8fafc;
  border-color: #d0d5dd;
  color: #1d2939;
}

.secondary-button:disabled {
  opacity: 0.65;
}

.summary-grid,
.readiness-grid {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(5, minmax(0, 1fr));
}

.summary-card,
.readiness-card {
  display: grid;
  gap: 8px;
  padding: 16px;
}

.summary-card strong {
  color: #101828;
  font-size: 24px;
}

.readiness-card {
  align-items: center;
  grid-template-columns: 1fr auto;
}

.readiness-card strong {
  color: #101828;
}

.next-step {
  align-items: center;
  display: flex;
  gap: 18px;
  justify-content: space-between;
  padding: 18px;
}

.next-step h3 {
  margin: 6px 0;
}

.next-step p {
  color: #475467;
  margin: 0;
}

.filter-row {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 2px;
}

.filter-row button {
  background: #fff;
  border: 1px solid #d0d5dd;
  color: #344054;
  cursor: pointer;
  font-weight: 700;
  min-height: 38px;
  padding: 0 12px;
  white-space: nowrap;
}

.filter-row button.active {
  background: #2563eb;
  border-color: #2563eb;
  color: #fff;
}

.steps-panel {
  display: grid;
  gap: 14px;
  padding: 16px;
}

.panel-heading {
  align-items: center;
  display: flex;
  justify-content: space-between;
}

.panel-heading h3,
.step-row h4 {
  margin: 0;
}

.step-list {
  display: grid;
  gap: 12px;
}

.step-row {
  align-items: flex-start;
  display: grid;
  gap: 14px;
  grid-template-columns: 48px 1fr auto;
  padding: 14px;
}

.step-number {
  align-items: center;
  background: #eff6ff;
  color: #1d4ed8;
  display: flex;
  font-weight: 800;
  height: 40px;
  justify-content: center;
  width: 40px;
}

.step-main {
  display: grid;
  gap: 12px;
}

.step-title-row {
  display: flex;
  gap: 12px;
  justify-content: space-between;
}

.step-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
}

.category-tag,
.required-tag,
.status-badge {
  display: inline-flex;
  font-size: 12px;
  font-weight: 800;
  padding: 5px 8px;
}

.category-tag {
  background: #f2f4f7;
  color: #344054;
}

.required-tag {
  background: #fff7ed;
  color: #c2410c;
}

.required-tag.optional {
  background: #f4f3ff;
  color: #6941c6;
}

.step-detail-grid {
  display: grid;
  gap: 10px;
  grid-template-columns: 150px 100px 1fr;
}

.step-detail-grid strong {
  color: #101828;
  display: block;
  margin-top: 4px;
}

.status-completed,
.status-ready {
  background: #ecfdf3;
  color: #027a48;
}

.status-in_progress {
  background: #eff6ff;
  color: #1d4ed8;
}

.status-pending,
.status-not_ready {
  background: #f2f4f7;
  color: #475467;
}

.status-attention_required {
  background: #fff7ed;
  color: #c2410c;
}

.status-blocked {
  background: #fffbeb;
  color: #b54708;
}

.status-coming_soon,
.status-skipped {
  background: #f2f4f7;
  color: #667085;
}

.status-optional {
  background: #f4f3ff;
  color: #6941c6;
}

.blocked-note {
  background: #fffbeb;
  color: #92400e;
  font-size: 13px;
  padding: 10px;
}

.step-action {
  white-space: nowrap;
}

.step-action.muted {
  background: #f2f4f7;
  color: #667085;
}

.empty-state {
  display: grid;
  gap: 6px;
  padding: 22px;
}

@media (max-width: 1180px) {
  .summary-grid,
  .readiness-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .context-row {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .context-actions {
    justify-content: flex-start;
  }
}

@media (max-width: 760px) {
  .onboarding-page {
    padding: 12px;
  }

  .onboarding-hero,
  .next-step,
  .panel-heading,
  .step-title-row {
    align-items: stretch;
    flex-direction: column;
  }

  .summary-grid,
  .readiness-grid,
  .context-row,
  .step-detail-grid {
    grid-template-columns: 1fr;
  }

  .step-row {
    grid-template-columns: 1fr;
  }

  .step-action,
  .primary-button,
  .secondary-button {
    width: 100%;
  }
}
</style>
