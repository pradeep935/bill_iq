<template>
  <Layout :page="page" :title="title">
    <template #topbar-title>
      <div class="bill-page-title"><span>Home</span><h1>Staff Workspace</h1><p>{{ todayLabel }}</p></div>
    </template>

    <div class="sw">
      <section class="sw-hello">
        <div>
          <h2>{{ greeting }}, {{ context.employee_name || 'Team Member' }}</h2>
          <p class="sw-chips">
            <span>{{ context.role }}</span>
            <span>{{ selectedBranchName }}</span>
            <span>{{ selectedWarehouseName }}</span>
            <span>FY {{ context.financial_year || 'Current' }}</span>
            <span v-if="shift">{{ shift.label }}</span>
          </p>
        </div>
        <div class="sw-hello-side">
          <label v-if="context.allowed_branches?.length > 1">Branch
            <select :value="filters.branch_id || ''" @change="changeFilter('branch_id', $event.target.value)">
              <option value="">All my branches</option>
              <option v-for="branch in context.allowed_branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
            </select>
          </label>
          <label v-if="context.allowed_warehouses?.length > 1">Warehouse
            <select :value="filters.warehouse_id || ''" @change="changeFilter('warehouse_id', $event.target.value)">
              <option value="">All my warehouses</option>
              <option v-for="warehouse in context.allowed_warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
            </select>
          </label>
          <button type="button" :disabled="refreshing" @click="refreshWorkspace">{{ refreshing ? 'Refreshing…' : 'Refresh' }}</button>
        </div>
      </section>

      <p v-if="context.branch_required || context.warehouse_required" class="sw-notice" role="alert">
        <strong>{{ context.branch_required ? 'No branch is assigned to you.' : 'No warehouse is assigned to you.' }}</strong> Please ask your admin.
      </p>

      <section v-if="kpis.length" class="sw-kpis" aria-label="Today">
        <component :is="card.href ? 'a' : 'div'" v-for="card in kpis" :key="card.key" :href="card.href || null" class="sw-kpi" :class="card.tone">
          <span>{{ card.title }}</span>
          <strong>{{ formatValue(card) }}</strong>
          <small>{{ card.note }}</small>
        </component>
      </section>

      <section class="sw-panel">
        <h3>What do you want to do?</h3>
        <div v-if="mainActions.length" class="sw-actions">
          <a v-for="action in mainActions" :key="action.key" :href="action.href" :class="['sw-action', action.key]">
            <span class="sw-icon" v-html="icon(action.key)"></span>
            <strong>{{ action.label }}</strong>
            <small>{{ action.help }}</small>
          </a>
        </div>
        <div v-if="moreActions.length" class="sw-more">
          <span>More</span>
          <a v-for="action in moreActions" :key="action.key" :href="action.href">{{ action.label }}</a>
        </div>
        <p v-if="!quickActions.length" class="sw-empty">Your login can only view. Ask your admin for more access.</p>
      </section>

      <div class="sw-columns">
        <section class="sw-panel">
          <div class="sw-head"><h3>My Pending Work</h3><em :class="{ clear: !tasks.length }">{{ tasks.length ? `${tasks.length} to finish` : 'All done' }}</em></div>
          <p v-if="!tasks.length" class="sw-empty">Nothing is pending. Unfinished bills and stock counts appear here.</p>
          <a v-for="task in tasks" :key="`${task.module}-${task.reference}-${task.date}`" :href="task.href || null" class="sw-row">
            <span><strong>{{ task.title }}</strong><small>{{ [task.reference, formatDate(task.date), task.module].filter((v) => v && v !== '-').join(' · ') }}</small></span>
            <b v-if="task.href">Finish ›</b>
          </a>
        </section>

        <section class="sw-panel">
          <div class="sw-head"><h3>Stock to Order</h3><a v-if="stockHref" :href="stockHref">View all</a></div>
          <p v-if="!inventoryAlerts.length" class="sw-empty">No product is running short.</p>
          <a v-for="alert in inventoryAlerts" :key="`${alert.product}-${alert.status}`" :href="alert.href || null" class="sw-row">
            <span><strong>{{ alert.product }}</strong><small>{{ alert.title }}</small></span>
            <b class="qty" :class="{ out: alert.status === 'Out' }">{{ Number(alert.quantity).toLocaleString('en-IN') }}</b>
          </a>
        </section>
      </div>

      <section v-if="recentActivity.length" class="sw-panel">
        <h3>My Recent Work</h3>
        <component :is="activity.href ? 'a' : 'div'" v-for="(activity, index) in recentActivity.slice(0, 6)" :key="index" :href="activity.href || null" class="sw-row">
          <span><strong>{{ activity.activity }}</strong><small>{{ [activity.reference, formatDate(activity.date)].filter((v) => v && v !== '-').join(' · ') }}</small></span>
          <b class="muted">{{ activity.status }}</b>
        </component>
      </section>
    </div>
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
const todayLabel = new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
// Attendance is shown only when it means something for this login.
const shift = computed(() => (['Not Linked', 'Not Available', undefined].includes(props.context.shift_status?.label) ? null : props.context.shift_status));

// The four figures that matter during the day.
const card = (key) => props.summary.find((item) => item.key === key);
const kpis = computed(() => [
  card('today_sales') && { ...card('today_sales'), title: 'My Sales Today', tone: 'sale', note: card('today_sales').subvalue },
  card('today_collections') && { ...card('today_collections'), title: 'Money Collected Today', tone: 'in', note: 'receipts you entered' },
  card('pending_tasks') && { ...card('pending_tasks'), title: 'Pending Work', tone: 'todo', href: null, note: card('pending_tasks').value ? 'finish them below' : 'nothing pending' },
  card('out_of_stock') && { ...card('out_of_stock'), title: 'Out of Stock', tone: 'out', note: `${card('low_stock')?.value || 0} more running low` },
  card('pending_stock_counts')?.value ? { ...card('pending_stock_counts'), title: 'Stock Counts to Finish', tone: 'todo', note: 'open counts' } : null,
].filter(Boolean));
const stockHref = computed(() => card('out_of_stock')?.href || null);

// Daily money and goods work gets large buttons; the rest stay small.
const help = {
  new_sale: 'Make a bill', receipt: 'Customer paid you', payment: 'You paid a supplier',
  new_purchase: 'Goods bought', expense: 'Rent, tea, transport',
};
const order = ['new_sale', 'receipt', 'payment', 'new_purchase', 'expense'];
const names = { receipt: 'Money In', payment: 'Money Out', expense: 'Add Expense' };
const mainActions = computed(() => order
  .map((key) => props.quickActions.find((action) => action.key === key))
  .filter(Boolean)
  .map((action) => ({ ...action, label: names[action.key] || action.label, help: help[action.key] })));
const moreActions = computed(() => props.quickActions.filter((action) => !order.includes(action.key)));

const paths = {
  new_sale: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
  receipt: '<path d="M12 4v14"/><path d="m6 12 6 6 6-6"/>',
  payment: '<path d="M12 20V6"/><path d="m6 12 6-6 6 6"/>',
  new_purchase: '<path d="M5 8h14l-1 12H6z"/><path d="M9 8a3 3 0 0 1 6 0"/>',
  expense: '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><path d="M7 15h4"/>',
};
const icon = (key) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[key] || paths.new_sale}</svg>`;

const formatValue = (card) => {
  if (card.format === 'currency') {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(Number(card.value || 0));
  }

  return String(card.value ?? 0);
};

const formatDate = (value) => {
  if (!value) return '';
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
.sw{display:grid;gap:16px;padding-bottom:28px}
.sw-hello{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:18px 20px;color:#fff;background:linear-gradient(135deg,#2457d6,#1c46ad);border-radius:14px}
.sw-hello h2{margin:0;color:#fff;font-size:22px;font-weight:900}
.sw-chips{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 0}
.sw-chips span{padding:4px 10px;background:rgba(255,255,255,.16);border-radius:999px;font-size:12px;font-weight:750}
.sw-hello-side{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap}
.sw-hello-side label{display:grid;gap:4px;color:#dbe6ff;font-size:11px;font-weight:800}
.sw-hello-side select,.sw-hello-side button{min-height:38px;padding:7px 12px;color:#142139;background:#fff;border:0;border-radius:8px;font-size:12px;font-weight:800}
.sw-hello-side button{cursor:pointer}.sw-hello-side button:disabled{opacity:.7;cursor:wait}
.sw-notice{margin:0;padding:12px 16px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;font-size:13px}
.sw-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
.sw-kpi{display:grid;gap:4px;padding:14px 16px;color:inherit;background:#fff;border:1px solid #dfe6ef;border-left:4px solid #2457d6;border-radius:12px;text-decoration:none}
.sw-kpi span{color:#5f6d82;font-size:12px;font-weight:800}
.sw-kpi strong{color:#142139;font-size:28px;font-weight:900;line-height:1.15}
.sw-kpi small{color:#758197;font-size:12px}
.sw-kpi.in{border-left-color:#16a34a}.sw-kpi.in strong{color:#15803d}
.sw-kpi.todo{border-left-color:#f59e0b}
.sw-kpi.out{border-left-color:#d23f49}.sw-kpi.out strong{color:#b02a34}
a.sw-kpi:hover,a.sw-row:hover,.sw-action:hover{border-color:#2457d6;box-shadow:0 6px 18px rgba(36,87,214,.12)}
.sw-panel{padding:16px 18px;background:#fff;border:1px solid #dfe6ef;border-radius:12px}
.sw-panel h3{margin:0 0 12px;color:#142139;font-size:16px;font-weight:800}
.sw-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
.sw-action{display:grid;justify-items:center;gap:6px;padding:18px 12px;color:#142139;background:#fff;border:1px solid #dfe6ef;border-radius:12px;text-align:center;text-decoration:none}
.sw-icon{display:grid;place-items:center;width:48px;height:48px;color:#2457d6;background:#edf2ff;border-radius:50%}
.sw-icon :deep(svg){width:24px;height:24px}
.sw-action strong{font-size:14px;font-weight:800}.sw-action small{color:#758197;font-size:11px}
.sw-action.new_sale{color:#fff;background:#2457d6;border-color:#2457d6}.sw-action.new_sale .sw-icon{background:#fff}.sw-action.new_sale small{color:#dbe6ff}
.sw-action.receipt .sw-icon{color:#15803d;background:#e7f8ef}
.sw-action.payment .sw-icon,.sw-action.expense .sw-icon{color:#b02a34;background:#fdecee}
.sw-action.new_purchase .sw-icon{color:#b45309;background:#fff4e5}
.sw-more{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid #eef2f7}
.sw-more span{color:#8490a2;font-size:11px;font-weight:800;text-transform:uppercase}
.sw-more a{padding:7px 14px;color:#2457d6;background:#edf4ff;border:1px solid #cfe0ff;border-radius:999px;font-size:12px;font-weight:800;text-decoration:none}
.sw-columns{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:16px;align-items:start}
.sw-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}.sw-head h3{margin:0}
.sw-head em{padding:4px 10px;color:#b45309;background:#fff7ed;border-radius:999px;font-size:11px;font-style:normal;font-weight:800}.sw-head em.clear{color:#15803d;background:#e7f8ef}
.sw-head a{color:#2457d6;font-size:12px;font-weight:800;text-decoration:none}
.sw-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px;padding:11px 14px;color:inherit;border:1px solid #e3e9f2;border-radius:10px;text-decoration:none}
.sw-row:last-child{margin-bottom:0}
.sw-row span{display:grid;gap:2px;min-width:0}
.sw-row strong{color:#142139;font-size:13px;overflow-wrap:anywhere}.sw-row small{color:#758197;font-size:12px}
.sw-row b{flex:0 0 auto;color:#2457d6;font-size:12px;font-weight:800}
.sw-row b.qty{padding:3px 10px;color:#b45309;background:#fff4e5;border-radius:999px}.sw-row b.qty.out{color:#b02a34;background:#fdecee}
.sw-row b.muted{color:#758197}
.sw-empty{margin:0;padding:14px;color:#667085;background:#f8fafc;border:1px dashed #d8e0eb;border-radius:10px;font-size:13px}
@media(max-width:900px){.sw-columns{grid-template-columns:minmax(0,1fr)}}
@media(max-width:600px){.sw-actions{grid-template-columns:repeat(2,minmax(0,1fr))}.sw-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.sw-kpi strong{font-size:22px}}
</style>
