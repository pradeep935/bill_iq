<template>
  <Layout :page="page" :title="title">
    <template #topbar-title>
      <div>
        <h1>Staff Workspace</h1>
        <p>Daily operations for your assigned branch, warehouse, and responsibilities.</p>
      </div>
    </template>

    <section class="staff-page">
      <header class="staff-hero">
        <div>
          <span class="staff-kicker">{{ context.role }}</span>
          <h2>{{ greeting }}, {{ context.employee_name || 'Team Member' }}</h2>
          <p>{{ contextText }}</p>
        </div>
        <div class="staff-hero-actions">
          <button type="button" class="secondary-button" :disabled="refreshing" @click="refreshWorkspace">
            {{ refreshing ? 'Refreshing...' : 'Refresh' }}
          </button>
          <a v-if="routes['profile.edit']?.url" class="primary-button" :href="routes['profile.edit'].url">My Profile</a>
        </div>
      </header>

      <div v-if="context.branch_required || context.warehouse_required" class="notice-panel">
        <strong>{{ context.branch_required ? 'Branch assignment needed' : 'Warehouse assignment needed' }}</strong>
        <span>{{ context.branch_required ? 'You are not assigned to a branch yet. Please contact your administrator.' : 'No warehouse has been assigned to your account.' }}</span>
      </div>

      <section class="context-grid">
        <article class="context-card">
          <span>Assigned Branch</span>
          <strong>{{ selectedBranchName }}</strong>
          <small>{{ context.allowed_branches?.length || 0 }} branch options</small>
        </article>
        <article class="context-card">
          <span>Assigned Warehouse</span>
          <strong>{{ selectedWarehouseName }}</strong>
          <small>{{ context.allowed_warehouses?.length || 0 }} warehouse options</small>
        </article>
        <article class="context-card">
          <span>Financial Year</span>
          <strong>{{ context.financial_year || 'Current' }}</strong>
          <small>Business session</small>
        </article>
        <article class="context-card">
          <span>Shift Status</span>
          <strong>{{ context.shift_status?.label || 'Not Available' }}</strong>
          <small>{{ context.shift_status?.detail || 'Attendance module unavailable' }}</small>
        </article>
      </section>

      <section v-if="hasLocationFilters" class="filter-row">
        <label v-if="context.allowed_branches?.length > 1">
          <span>Branch</span>
          <select :value="filters.branch_id || ''" @change="changeFilter('branch_id', $event.target.value)">
            <option value="">All assigned branches</option>
            <option v-for="branch in context.allowed_branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
          </select>
        </label>
        <label v-if="context.allowed_warehouses?.length > 1">
          <span>Warehouse</span>
          <select :value="filters.warehouse_id || ''" @change="changeFilter('warehouse_id', $event.target.value)">
            <option value="">All assigned warehouses</option>
            <option v-for="warehouse in context.allowed_warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
          </select>
        </label>
      </section>

      <section class="summary-grid">
        <a v-for="card in summary" :key="card.key" class="summary-card" :href="card.href || '#'">
          <span>{{ card.label }}</span>
          <strong>{{ formatValue(card) }}</strong>
          <small>{{ card.value === 0 ? card.empty : card.subvalue }}</small>
        </a>
      </section>

      <section class="quick-actions">
        <div class="section-heading">
          <h3>Quick Actions</h3>
          <span>{{ quickActions.length }} available</span>
        </div>
        <div v-if="quickActions.length" class="action-grid">
          <a v-for="action in quickActions" :key="action.key" :href="action.href" class="action-button">
            <span>{{ action.label }}</span>
          </a>
        </div>
        <div v-else class="empty-state">
          <strong>No quick actions available</strong>
          <span>Your account has view access only for the current workspace.</span>
        </div>
      </section>

      <div class="workspace-grid">
        <section class="panel panel-wide">
          <div class="section-heading">
            <h3>My Pending Work</h3>
            <span>{{ tasks.length }} open</span>
          </div>
          <div v-if="tasks.length" class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Work Item</th>
                  <th>Reference</th>
                  <th>Location</th>
                  <th>Date</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="task in tasks" :key="`${task.module}-${task.reference}-${task.date}`">
                  <td>
                    <strong>{{ task.title }}</strong>
                    <small>{{ task.module }}</small>
                  </td>
                  <td>{{ task.reference }}</td>
                  <td>{{ task.location || 'Assigned location' }}</td>
                  <td>{{ formatDate(task.date) }}</td>
                  <td><span class="status-pill">{{ task.status }}</span></td>
                  <td><a v-if="task.href" :href="task.href">Open</a><span v-else>-</span></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="empty-state">
            <strong>No pending tasks assigned to you</strong>
            <span>New drafts, stock counts, and follow-ups will appear here.</span>
          </div>
        </section>

        <section class="panel">
          <div class="section-heading">
            <h3>Inventory Alerts</h3>
            <span>{{ inventoryAlerts.length }} items</span>
          </div>
          <div v-if="inventoryAlerts.length" class="list-stack">
            <a v-for="alert in inventoryAlerts" :key="`${alert.product}-${alert.status}`" :href="alert.href || '#'" class="list-item">
              <div>
                <strong>{{ alert.product }}</strong>
                <small>{{ alert.title }}</small>
              </div>
              <span class="status-pill danger">{{ alert.quantity }}</span>
            </a>
          </div>
          <div v-else class="empty-state">
            <strong>No inventory alerts</strong>
            <span>No inventory alerts for your assigned location.</span>
          </div>
        </section>

        <section class="panel">
          <div class="section-heading">
            <h3>Recent Activity</h3>
            <span>{{ recentActivity.length }} logs</span>
          </div>
          <div v-if="recentActivity.length" class="list-stack">
            <a v-for="activity in recentActivity" :key="`${activity.activity}-${activity.reference}-${activity.date}`" :href="activity.href || '#'" class="list-item">
              <div>
                <strong>{{ activity.activity }}</strong>
                <small>{{ activity.reference }} - {{ formatDate(activity.date) }}</small>
              </div>
              <span class="status-pill">{{ activity.status }}</span>
            </a>
          </div>
          <div v-else class="empty-state">
            <strong>No recent activity recorded for you</strong>
            <span>Your sales, counts, and assigned work updates will appear here.</span>
          </div>
        </section>
      </div>
    </section>
  </Layout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Layout from '../Layout.vue';

const props = defineProps({
  page: { type: String, required: true },
  title: { type: String, required: true },
  context: { type: Object, default: () => ({}) },
  summary: { type: Array, default: () => [] },
  quickActions: { type: Array, default: () => [] },
  tasks: { type: Array, default: () => [] },
  recentActivity: { type: Array, default: () => [] },
  inventoryAlerts: { type: Array, default: () => [] },
  permissions: { type: Object, default: () => ({}) },
  routes: { type: Object, default: () => ({}) },
});

const refreshing = ref(false);
const filters = reactive({
  branch_id: props.context.selected_branch_id || '',
  warehouse_id: props.context.selected_warehouse_id || '',
});

const greeting = computed(() => {
  const hour = new Date().getHours();
  if (hour < 12) return 'Good morning';
  if (hour < 17) return 'Good afternoon';
  return 'Good evening';
});

const selectedBranchName = computed(() => {
  if (props.context.branch_required) return 'Not assigned';
  const branch = props.context.allowed_branches?.find((item) => Number(item.id) === Number(props.context.selected_branch_id));
  return branch?.name || (props.context.allowed_branches?.length ? 'All assigned branches' : 'No branch assigned');
});

const selectedWarehouseName = computed(() => {
  if (props.context.warehouse_required) return 'Not assigned';
  const warehouse = props.context.allowed_warehouses?.find((item) => Number(item.id) === Number(props.context.selected_warehouse_id));
  return warehouse?.name || (props.context.allowed_warehouses?.length ? 'All assigned warehouses' : 'No warehouse assigned');
});

const contextText = computed(() => `${selectedBranchName.value} - ${selectedWarehouseName.value}`);
const hasLocationFilters = computed(() => (props.context.allowed_branches?.length || 0) > 1 || (props.context.allowed_warehouses?.length || 0) > 1);

const formatValue = (card) => {
  if (card.format === 'currency') {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(Number(card.value || 0));
  }

  return String(card.value ?? 0);
};

const formatDate = (value) => {
  if (!value) return '-';
  return new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short' }).format(new Date(value));
};

const refreshWorkspace = () => {
  refreshing.value = true;
  router.reload({
    only: ['context', 'summary', 'quickActions', 'tasks', 'recentActivity', 'inventoryAlerts', 'permissions', 'routes'],
    preserveScroll: true,
    onFinish: () => {
      refreshing.value = false;
    },
  });
};

const changeFilter = (key, value) => {
  filters[key] = value;
  if (key === 'branch_id') {
    filters.warehouse_id = '';
  }

  router.visit(props.routes['staff.workspace']?.url || '/app/staff/workspace', {
    data: Object.fromEntries(Object.entries(filters).filter(([, entry]) => entry !== '' && entry !== null)),
    preserveScroll: true,
  });
};
</script>

<style scoped>
.staff-page {
  display: grid;
  gap: 18px;
  padding: 20px;
}

.staff-hero {
  align-items: flex-start;
  background: #163b37;
  color: #fff;
  display: flex;
  gap: 20px;
  justify-content: space-between;
  padding: 22px;
}

.staff-kicker,
.section-heading span,
.context-card span,
.context-card small,
.empty-state span,
td small {
  color: #667085;
  display: block;
  font-size: 12px;
}

.staff-hero .staff-kicker,
.staff-hero p {
  color: #d6f3ec;
}

.staff-hero h2 {
  font-size: 28px;
  line-height: 1.1;
  margin: 8px 0;
}

.staff-hero p {
  margin: 0;
}

.staff-hero-actions,
.filter-row,
.section-heading {
  align-items: center;
  display: flex;
  gap: 10px;
}

.staff-hero-actions {
  flex-wrap: wrap;
  justify-content: flex-end;
}

.primary-button,
.secondary-button,
.action-button {
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
.action-button {
  background: #f5b544;
  color: #1d2939;
}

.secondary-button {
  background: #fff;
  color: #163b37;
}

.secondary-button:disabled {
  opacity: 0.65;
}

.notice-panel,
.context-card,
.summary-card,
.quick-actions,
.panel {
  background: #fff;
  border: 1px solid #e4e7ec;
}

.notice-panel {
  display: grid;
  gap: 4px;
  padding: 14px 16px;
}

.notice-panel strong {
  color: #b42318;
}

.context-grid,
.summary-grid,
.action-grid,
.workspace-grid {
  display: grid;
  gap: 14px;
}

.context-grid {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.context-card,
.summary-card {
  color: #101828;
  display: grid;
  gap: 8px;
  padding: 16px;
  text-decoration: none;
}

.context-card strong,
.summary-card strong {
  font-size: 22px;
  line-height: 1.1;
}

.filter-row {
  background: #f9fafb;
  border: 1px solid #e4e7ec;
  flex-wrap: wrap;
  padding: 12px;
}

.filter-row label {
  display: grid;
  gap: 6px;
  min-width: 220px;
}

.filter-row select {
  border: 1px solid #d0d5dd;
  min-height: 38px;
  padding: 0 10px;
}

.summary-grid {
  grid-template-columns: repeat(6, minmax(0, 1fr));
}

.quick-actions,
.panel {
  display: grid;
  gap: 14px;
  padding: 16px;
}

.section-heading {
  justify-content: space-between;
}

.section-heading h3 {
  font-size: 18px;
  margin: 0;
}

.action-grid {
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
}

.action-button {
  min-height: 48px;
}

.workspace-grid {
  grid-template-columns: 1.4fr 1fr;
}

.panel-wide {
  grid-row: span 2;
}

.table-wrap {
  overflow-x: auto;
}

table {
  border-collapse: collapse;
  min-width: 760px;
  width: 100%;
}

th,
td {
  border-bottom: 1px solid #eef2f6;
  padding: 12px 10px;
  text-align: left;
  vertical-align: top;
}

th {
  color: #475467;
  font-size: 12px;
  text-transform: uppercase;
}

.status-pill {
  background: #ecfdf3;
  color: #027a48;
  display: inline-flex;
  font-size: 12px;
  font-weight: 700;
  padding: 5px 8px;
}

.status-pill.danger {
  background: #fef3f2;
  color: #b42318;
}

.list-stack {
  display: grid;
  gap: 10px;
}

.list-item {
  align-items: center;
  border: 1px solid #eef2f6;
  color: #101828;
  display: flex;
  gap: 12px;
  justify-content: space-between;
  padding: 12px;
  text-decoration: none;
}

.empty-state {
  background: #f9fafb;
  border: 1px dashed #d0d5dd;
  display: grid;
  gap: 6px;
  padding: 18px;
}

@media (max-width: 1180px) {
  .context-grid,
  .summary-grid,
  .workspace-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 720px) {
  .staff-page {
    padding: 12px;
  }

  .staff-hero,
  .section-heading,
  .list-item {
    align-items: stretch;
    flex-direction: column;
  }

  .staff-hero-actions {
    justify-content: stretch;
  }

  .staff-hero-actions > *,
  .context-grid,
  .summary-grid,
  .workspace-grid {
    width: 100%;
  }

  .context-grid,
  .summary-grid,
  .workspace-grid {
    grid-template-columns: 1fr;
  }
}
</style>
