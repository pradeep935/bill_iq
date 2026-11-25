<template>
  <Layout page="dashboard" title="Business Control Room">
    <template #topbar-title>
      <div class="bill-page-title">
        <span>Live workspace</span>
        <h1>Business Dashboard</h1>
        <p>Sales, purchases, GST, stock, accounts and closing health for the active business.</p>
      </div>
    </template>

    <section class="page-toolbar">
      <a v-if="permissions['sales.create']" class="bill-primary" :href="routeUrl('sales.create')">New Sale</a>
      <a v-if="permissions['accounting.create']" :href="routeUrl('accounting.vouchers.create')">Voucher</a>
      <a v-if="permissions['reports.view']" :href="routeUrl('reports.index')">Reports</a>
    </section>

    <section class="bill-notice-row" v-if="notifications.length">
      <a v-for="item in notifications" :key="item.label" :href="item.href" class="bill-notice" :class="item.type">
        <span></span>
        {{ item.label }}
      </a>
    </section>

    <section class="bill-grid bill-grid-4">
      <a v-for="card in summary" :key="card.key" class="bill-card bill-click-card" :href="card.href">
        <span>{{ card.label }}</span>
        <strong>{{ displayValue(card) }}</strong>
        <small>{{ card.hint }}</small>
      </a>
    </section>

    <section class="bill-grid bill-grid-4">
      <a
        v-for="module in modules"
        :key="module.key"
        class="bill-workflow-card bill-module-card"
        :class="{ disabled: !module.enabled }"
        :href="module.enabled ? module.href : null"
        :aria-disabled="!module.enabled"
      >
        <span>{{ module.label.slice(0, 2).toUpperCase() }}</span>
        <strong>{{ module.label }}</strong>
        <div class="bill-mini-grid">
          <div v-for="stat in module.stats" :key="stat.label">
            <small>{{ stat.label }}</small>
            <b>{{ displayValue(stat) }}</b>
          </div>
        </div>
      </a>
    </section>

    <section class="bill-card bill-quick-actions" v-if="quickActions.length">
      <div class="bill-card-head">
        <h3>Quick Actions</h3>
      </div>
      <div class="bill-action-grid">
        <a v-for="action in quickActions" :key="action.label" :href="action.href">
          {{ action.label }}
        </a>
      </div>
    </section>

    <section class="bill-grid bill-grid-2">
      <article class="bill-card">
        <div class="bill-card-head">
          <h3>Recent Sales</h3>
          <a :href="routeUrl('sales.index')">View all</a>
        </div>
        <div v-if="recentSales.length" class="bill-table-scroll">
          <table class="bill-table recent-sales">
            <thead>
              <tr>
                <th>Invoice</th>
                <th>Customer</th>
                <th>Amount</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="sale in recentSales" :key="sale.id">
                <td><strong class="recent-nowrap">{{ sale.invoice_number }}</strong><small class="recent-sub">{{ sale.date }}</small></td>
                <td>{{ sale.customer }}</td>
                <td><strong class="recent-nowrap">{{ money(sale.total) }}</strong><span class="recent-badges"><span class="bill-badge">{{ sale.payment_status }}</span><span class="bill-badge" :class="{ danger: !postedStatuses.includes(sale.invoice_status.toLowerCase()) }">{{ sale.invoice_status }}</span></span></td>
                <td class="bill-row-actions">
                  <RowActionMenu :open="openActionMenuId === sale.id" :show-view="false" more-label="Actions" more-title="Sale actions" @toggle="toggleActionMenu(sale)" @close="closeActionMenu">
                    <a :href="sale.links.view" @click="closeActionMenu">View</a>
                    <a v-if="sale.can_edit" :href="sale.links.edit" @click="closeActionMenu">Edit</a>
                    <a :href="sale.links.print" @click="closeActionMenu">Print</a>
                  </RowActionMenu>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="bill-empty">
          <strong>No sales recorded today.</strong>
          <a v-if="permissions['sales.create']" class="bill-primary" :href="routeUrl('sales.create')">Create New Sale</a>
        </div>
      </article>

      <article class="bill-card">
        <div class="bill-card-head">
          <h3>Pending Actions</h3>
          <small>{{ pendingActions.open ? `${pendingActions.open} need attention` : 'All clear' }}</small>
        </div>
        <div class="pending-list">
          <component
            :is="item.href ? 'a' : 'span'"
            v-for="item in pendingActions.items"
            :key="item.key"
            :href="item.href"
            :class="['pending-row', { done: !item.count }]"
          >
            <b>{{ item.count.toLocaleString('en-IN') }}</b>
            <span><strong>{{ item.label }}</strong><small>{{ item.count ? item.hint : 'Nothing pending' }}</small></span>
            <em v-if="item.amount !== null && item.count">{{ money(item.amount) }}</em>
          </component>
          <p v-if="!pendingActions.items.length" class="pending-empty">Nothing to show for your role.</p>
        </div>
      </article>
    </section>

    <section class="bill-grid bill-grid-2">
      <article class="bill-card bill-chart-card">
        <div class="bill-card-head">
          <h3>Sales Last 7 Days</h3>
        </div>
        <Line :data="lineChart(charts.salesLast7Days, 'Sales')" :options="chartOptions" />
      </article>
      <article class="bill-card bill-chart-card">
        <div class="bill-card-head">
          <h3>Purchases Last 7 Days</h3>
        </div>
        <Line :data="lineChart(charts.purchasesLast7Days, 'Purchases')" :options="chartOptions" />
      </article>
    </section>

    <section class="bill-grid bill-grid-2">
      <article class="bill-card">
        <div class="bill-card-head">
          <h3>Additional Metrics</h3>
        </div>
        <div class="bill-metric-grid">
          <div v-for="metric in metrics" :key="metric.label">
            <span>{{ metric.label }}</span>
            <strong>{{ displayValue(metric) }}</strong>
          </div>
        </div>
      </article>

      <article class="bill-card">
        <div class="bill-card-head">
          <h3>Inventory Alerts</h3>
          <a :href="inventoryAlerts.viewAll">View all</a>
        </div>
        <div class="bill-alert-groups">
          <div>
            <h4>Low Stock</h4>
            <a v-for="item in inventoryAlerts.lowStock" :key="`low-${item.product_id}`" :href="item.href">
              <span>{{ item.name }}</span>
              <b>{{ item.quantity }}</b>
            </a>
            <p v-if="!inventoryAlerts.lowStock.length">No low-stock products.</p>
          </div>
          <div>
            <h4>Out of Stock</h4>
            <a v-for="item in inventoryAlerts.outOfStock" :key="`out-${item.product_id}`" :href="item.href">
              <span>{{ item.name }}</span>
              <b>0</b>
            </a>
            <p v-if="!inventoryAlerts.outOfStock.length">No out-of-stock products.</p>
          </div>
          <div>
            <h4>Expiring Batches</h4>
            <a v-for="item in inventoryAlerts.expiringBatches" :key="`batch-${item.id}`" :href="item.href">
              <span>{{ item.name }}</span>
              <b>{{ item.expiry_date }}</b>
            </a>
            <p v-if="!inventoryAlerts.expiringBatches.length">No batches expiring soon.</p>
          </div>
        </div>
      </article>
    </section>
  </Layout>
</template>

<script setup>
import {
  CategoryScale,
  Chart as ChartJS,
  Filler,
  Legend,
  LinearScale,
  LineElement,
  PointElement,
  Title,
  Tooltip,
} from 'chart.js';
import { Line } from 'vue-chartjs';
import { ref } from 'vue';
import Layout from './Layout.vue';
import RowActionMenu from '../Components/Common/RowActionMenu.vue';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler);

const props = defineProps({
  summary: { type: Array, default: () => [] },
  modules: { type: Array, default: () => [] },
  recentSales: { type: Array, default: () => [] },
  quickActions: { type: Array, default: () => [] },
  pendingActions: { type: Object, default: () => ({ items: [], open: 0 }) },
  charts: { type: Object, default: () => ({}) },
  inventoryAlerts: { type: Object, default: () => ({ lowStock: [], outOfStock: [], expiringBatches: [], viewAll: '#' }) },
  metrics: { type: Array, default: () => [] },
  notifications: { type: Array, default: () => [] },
  permissions: { type: Object, default: () => ({}) },
  routes: { type: Object, default: () => ({}) },
});

const postedStatuses = ['approved', 'confirmed', 'posted', 'completed'];
const moneyFormatter = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' });
const openActionMenuId = ref(null);

const money = (value) => moneyFormatter.format(Number(value || 0));
const displayValue = (item) => item?.format === 'currency' ? money(item.value) : Number(item?.value || 0).toLocaleString('en-IN');
const routeUrl = (name) => props.routes?.[name]?.url || '#';
const toggleActionMenu = (sale) => {
  openActionMenuId.value = openActionMenuId.value === sale.id ? null : sale.id;
};
const closeActionMenu = () => {
  openActionMenuId.value = null;
};

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: {
    y: { beginAtZero: true, ticks: { callback: (value) => Number(value).toLocaleString('en-IN') } },
    x: { grid: { display: false } },
  },
};

const lineChart = (series = { labels: [], values: [] }, label) => ({
  labels: series.labels || [],
  datasets: [
    {
      label,
      data: series.values || [],
      borderColor: label === 'Sales' ? '#2457d6' : '#0f9f8f',
      backgroundColor: label === 'Sales' ? 'rgba(36, 87, 214, .12)' : 'rgba(15, 159, 143, .12)',
      fill: true,
      tension: 0.35,
      pointRadius: 3,
    },
  ],
});
</script>

<style scoped>
.recent-sales td,.recent-sales th{padding-left:8px;padding-right:8px;font-size:12px}
.recent-sales th:first-child,.recent-sales td:first-child{min-width:0}
.recent-sub{display:block;color:#758197;font-size:11px}
.recent-badges{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
.recent-badges .bill-badge{min-width:0}
.recent-nowrap{white-space:nowrap}
.pending-list{display:grid;gap:8px}
.pending-row{display:grid;grid-template-columns:64px minmax(0,1fr) auto;align-items:center;gap:12px;padding:10px 12px;color:#17233b;border:1px solid #e5ebf3;border-radius:8px;text-decoration:none}
a.pending-row:hover{border-color:#2457d6;background:#f5f8ff}
.pending-row b{color:#b54708;font-size:18px;font-weight:800;text-align:center}
.pending-row span{display:grid}
.pending-row strong{font-size:13px}
.pending-row small{color:#758197;font-size:11px}
.pending-row em{font-size:13px;font-style:normal;font-weight:800;white-space:nowrap}
.pending-row.done b{color:#168757}
.pending-empty{color:#758197;font-size:13px}
</style>
