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
          <table class="bill-table">
            <thead>
              <tr>
                <th>Invoice</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Total</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="sale in recentSales" :key="sale.id">
                <td>{{ sale.invoice_number }}</td>
                <td>{{ sale.date }}</td>
                <td>{{ sale.customer }}</td>
                <td>{{ money(sale.total) }}</td>
                <td><span class="bill-badge">{{ sale.payment_status }}</span></td>
                <td><span class="bill-badge" :class="{ danger: !postedStatuses.includes(sale.invoice_status.toLowerCase()) }">{{ sale.invoice_status }}</span></td>
                <td class="bill-row-actions">
                  <a :href="sale.links.view">View</a>
                  <a v-if="sale.can_edit" :href="sale.links.edit">Edit</a>
                  <a :href="sale.links.print">Print</a>
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
          <h3>Accounting Checklist</h3>
          <small>{{ accountingChecklist.completed }} of {{ accountingChecklist.total }} completed</small>
        </div>
        <div class="bill-progress"><span :style="{ width: `${accountingChecklist.percentage}%` }"></span></div>
        <div class="bill-tasks">
          <component
            :is="item.enabled && item.href ? 'a' : 'span'"
            v-for="item in accountingChecklist.items"
            :key="item.label"
            :href="item.enabled ? item.href : null"
            :class="['bill-task', item.status, { disabled: !item.enabled }]"
          >
            <b>{{ item.status }}</b>
            {{ item.label }}
            <em v-if="item.comingSoon">Coming Soon</em>
          </component>
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
import Layout from './Layout.vue';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler);

const props = defineProps({
  summary: { type: Array, default: () => [] },
  modules: { type: Array, default: () => [] },
  recentSales: { type: Array, default: () => [] },
  quickActions: { type: Array, default: () => [] },
  accountingChecklist: { type: Object, default: () => ({ items: [], completed: 0, total: 0, percentage: 0 }) },
  charts: { type: Object, default: () => ({}) },
  inventoryAlerts: { type: Object, default: () => ({ lowStock: [], outOfStock: [], expiringBatches: [], viewAll: '#' }) },
  metrics: { type: Array, default: () => [] },
  notifications: { type: Array, default: () => [] },
  permissions: { type: Object, default: () => ({}) },
  routes: { type: Object, default: () => ({}) },
});

const postedStatuses = ['approved', 'confirmed', 'posted', 'completed'];
const moneyFormatter = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' });

const money = (value) => moneyFormatter.format(Number(value || 0));
const displayValue = (item) => item?.format === 'currency' ? money(item.value) : Number(item?.value || 0).toLocaleString('en-IN');
const routeUrl = (name) => props.routes?.[name]?.url || '#';

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
