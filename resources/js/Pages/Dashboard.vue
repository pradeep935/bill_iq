<template>
  <Layout page="dashboard" title="Business Dashboard">
    <template #topbar-title>
      <div class="bill-page-title">
        <span>Home</span>
        <h1>Business Dashboard</h1>
        <p>{{ todayLabel }}</p>
      </div>
    </template>

    <div class="dash">
      <section class="kpis" aria-label="Business summary">
        <component :is="card.href ? 'a' : 'div'" v-for="card in kpis" :key="card.key" :href="card.href" :class="['kpi', card.tone]">
          <span class="kpi-icon" v-html="icon(card.icon)"></span>
          <span class="kpi-label">{{ card.label }}</span>
          <strong>{{ money(card.amount) }}</strong>
          <small>{{ card.note }}</small>
        </component>
      </section>

      <section v-if="quickActions.length" class="panel">
        <h2>What do you want to do?</h2>
        <div class="actions-grid">
          <a v-for="action in quickActions" :key="action.label" :href="action.href" :class="['action', action.icon]">
            <span v-html="icon(action.icon)"></span>
            {{ action.label }}
          </a>
        </div>
      </section>

      <div class="columns">
        <section class="panel">
          <div class="panel-head">
            <h2>Pending Actions</h2>
            <em :class="{ clear: !pendingActions.open }">{{ pendingActions.open ? `${pendingActions.open} need attention` : 'All clear' }}</em>
          </div>
          <div class="pending-list">
            <component :is="item.href ? 'a' : 'span'" v-for="item in pendingActions.items" :key="item.key" :href="item.href" :class="['pending-row', { done: !item.count }]">
              <b>{{ item.count.toLocaleString('en-IN') }}</b>
              <span><strong>{{ item.label }}</strong><small>{{ item.count ? item.hint : 'Nothing pending' }}</small></span>
              <em v-if="item.amount !== null && item.count">{{ money(item.amount) }}</em>
              <i aria-hidden="true">›</i>
            </component>
            <p v-if="!pendingActions.items.length" class="empty">Nothing to show for your role.</p>
          </div>
        </section>

        <section v-if="permissions['sales.view']" class="panel">
          <div class="panel-head">
            <h2>Recent Sales</h2>
            <a :href="routeUrl('sales.index')">View all</a>
          </div>
          <ul v-if="recentSales.length" class="sales-list">
            <li v-for="sale in recentSales" :key="sale.id">
              <a :href="sale.links.view" class="sale-main">
                <strong>{{ sale.customer }}</strong>
                <small>{{ sale.invoice_number }} · {{ shortDate(sale.date) }}</small>
              </a>
              <span class="sale-money">
                <strong>{{ money(sale.total) }}</strong>
                <span :class="['tag', sale.payment_status.toLowerCase()]">{{ sale.payment_status }}</span>
              </span>
              <a :href="sale.links.print" target="_blank" rel="noopener" class="sale-print" :aria-label="`Print ${sale.invoice_number}`">Print</a>
            </li>
          </ul>
          <div v-else class="empty">
            <p>No sales yet.</p>
            <a v-if="permissions['sales.create']" class="button" :href="routeUrl('sales.create')">Create your first sale</a>
          </div>
        </section>
      </div>

      <div class="columns wide-left">
        <section class="panel">
          <div class="panel-head">
            <h2>Sales and Purchases</h2>
            <span class="legend"><i class="sales"></i>Sales <i class="purchases"></i>Purchases · last 6 months</span>
          </div>
          <div v-if="hasChartData" class="chart"><Bar :data="barData" :options="barOptions" /></div>
          <p v-else class="empty">No sales or purchases in the last 6 months.</p>
        </section>

        <section v-if="home.money" class="panel">
          <div class="panel-head"><h2>Money</h2></div>
          <dl class="rows">
            <a :href="home.money.ledgerHref"><dt>Cash in hand</dt><dd>{{ money(home.money.cash) }}</dd></a>
            <a :href="home.money.ledgerHref"><dt>Bank balance</dt><dd>{{ money(home.money.bank) }}</dd></a>
            <div><dt>Today's profit <small>estimate, without GST</small></dt><dd>{{ money(home.money.profit) }}</dd></div>
            <a :href="home.money.gstHref"><dt>{{ home.money.gst >= 0 ? 'GST to pay' : 'GST credit' }} <small>all bills till date</small></dt><dd>{{ money(Math.abs(home.money.gst)) }}</dd></a>
          </dl>
        </section>
      </div>

      <div v-if="home.stock && inventoryAlerts" class="columns wide-left">
        <section class="panel">
          <div class="panel-head">
            <h2>Stock</h2>
            <a :href="home.stock.href">Open stock</a>
          </div>
          <div class="stats">
            <a :href="home.stock.href"><small>Stock value</small><strong>{{ money(home.stock.value) }}</strong></a>
            <a :href="home.stock.href"><small>Products in stock</small><strong>{{ number(home.stock.inStock) }}</strong></a>
            <a :href="`${home.stock.href}?stock_status=unavailable`" class="bad"><small>Out of stock / minus</small><strong>{{ number(home.stock.out) }}</strong></a>
            <a :href="`${home.stock.href}?stock_status=low`"><small>Low stock</small><strong>{{ number(home.stock.low) }}</strong></a>
          </div>
          <div class="alerts">
            <div v-for="group in alertGroups" :key="group.title">
              <h3>{{ group.title }}</h3>
              <a v-for="row in group.rows" :key="row.key" :href="row.href"><span>{{ row.name }}</span><b :class="{ bad: row.bad }">{{ row.value }}</b></a>
              <p v-if="!group.rows.length">{{ group.empty }}</p>
            </div>
          </div>
        </section>

        <section class="panel">
          <div class="panel-head"><h2>Your business</h2></div>
          <dl class="rows">
            <a v-for="row in countRows" :key="row.label" :href="row.href"><dt>{{ row.label }}</dt><dd>{{ number(row.count) }}</dd></a>
          </dl>
        </section>
      </div>
    </div>
  </Layout>
</template>

<script setup>
import { computed } from 'vue';
import { BarElement, CategoryScale, Chart as ChartJS, LinearScale, Tooltip } from 'chart.js';
import { Bar } from 'vue-chartjs';
import Layout from './Layout.vue';

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip);

const props = defineProps({
  home: { type: Object, default: () => ({ counts: {} }) },
  quickActions: { type: Array, default: () => [] },
  pendingActions: { type: Object, default: () => ({ items: [], open: 0 }) },
  recentSales: { type: Array, default: () => [] },
  chart: { type: Object, default: () => ({ labels: [], sales: [], purchases: [] }) },
  inventoryAlerts: { type: Object, default: null },
  permissions: { type: Object, default: () => ({}) },
  routes: { type: Object, default: () => ({}) },
});

const moneyFormatter = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 });
const money = (value) => moneyFormatter.format(Number(value || 0));
const number = (value) => Number(value || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });
const shortDate = (value) => value ? new Date(`${value}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
const todayLabel = new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const routeUrl = (name) => props.routes?.[name]?.url || '#';
const plural = (count, word) => `${number(count)} ${word}${count === 1 ? '' : 's'}`;

const kpis = computed(() => {
  const h = props.home;
  return [
    h.toCollect && { key: 'collect', tone: 'in', icon: 'in', label: 'To Collect', amount: h.toCollect.amount, note: h.toCollect.count ? `from ${plural(h.toCollect.count, 'unpaid invoice')}` : 'Nothing to collect', href: h.toCollect.href },
    h.toPay && { key: 'pay', tone: 'out', icon: 'out', label: 'To Pay', amount: h.toPay.amount, note: h.toPay.count ? `for ${plural(h.toPay.count, 'unpaid bill')}` : 'Nothing to pay', href: h.toPay.href },
    h.todaySales && { key: 'sales', tone: 'sale', icon: 'sale', label: "Today's Sales", amount: h.todaySales.amount, note: plural(h.todaySales.count, 'invoice'), href: h.todaySales.href },
    h.todayPurchases && { key: 'purchases', tone: 'buy', icon: 'purchase', label: "Today's Purchases", amount: h.todayPurchases.amount, note: plural(h.todayPurchases.count, 'bill'), href: h.todayPurchases.href },
  ].filter(Boolean);
});

const countRows = computed(() => [
  ['Products', props.home.counts?.products],
  ['Customers', props.home.counts?.customers],
  ['Suppliers', props.home.counts?.suppliers],
].filter(([, data]) => data).map(([label, data]) => ({ label, count: data.count, href: data.href })));

const alertGroups = computed(() => {
  const alerts = props.inventoryAlerts || {};
  const stockRow = (item, bad) => ({ key: item.product_id, name: item.name, href: item.href, value: number(item.quantity), bad });
  const groups = [
    { title: 'Out of stock', empty: 'Every product has stock.', rows: (alerts.outOfStock || []).map((item) => stockRow(item, true)) },
    { title: 'Low stock', empty: 'No product is running low.', rows: (alerts.lowStock || []).map((item) => stockRow(item, false)) },
  ];
  if ((alerts.expiringBatches || []).length) {
    groups.push({ title: 'Expiring in 30 days', empty: '', rows: alerts.expiringBatches.map((b) => ({ key: b.id, name: b.name, href: b.href, value: shortDate(b.expiry_date), bad: true })) });
  }
  return groups;
});

const hasChartData = computed(() => [...(props.chart.sales || []), ...(props.chart.purchases || [])].some((v) => Number(v) > 0));
const barData = computed(() => ({
  labels: props.chart.labels || [],
  datasets: [
    { label: 'Sales', data: props.chart.sales || [], backgroundColor: '#2457d6', borderRadius: 4, maxBarThickness: 28 },
    { label: 'Purchases', data: props.chart.purchases || [], backgroundColor: '#f59e0b', borderRadius: 4, maxBarThickness: 28 },
  ],
}));
const barOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${money(ctx.raw)}` } } },
  scales: {
    y: { beginAtZero: true, ticks: { callback: (value) => `₹${Intl.NumberFormat('en-IN', { notation: 'compact' }).format(value)}` } },
    x: { grid: { display: false } },
  },
};

const paths = {
  in: '<path d="M12 4v14"/><path d="m6 12 6 6 6-6"/>',
  out: '<path d="M12 20V6"/><path d="m6 12 6-6 6 6"/>',
  sale: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
  purchase: '<path d="M5 8h14l-1 12H6z"/><path d="M9 8a3 3 0 0 1 6 0"/>',
  expense: '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><path d="M7 15h4"/>',
  product: '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>',
  customer: '<circle cx="10" cy="8" r="4"/><path d="M3 21a7 7 0 0 1 14 0"/><path d="M19 8v6M16 11h6"/>',
  supplier: '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
};
const icon = (name) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.sale}</svg>`;
</script>

<style scoped>
.dash{display:grid;gap:16px;color:#17233b}
.dash a{color:inherit;text-decoration:none}
.panel{min-width:0;padding:18px;background:#fff;border:1px solid #e3e9f2;border-radius:14px}
.panel h2{margin:0;font-size:16px;font-weight:800}
.panel>h2{margin-bottom:14px}
.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.panel-head>a{color:#2457d6;font-size:13px;font-weight:700}
.panel-head em{padding:3px 10px;color:#b54708;background:#fff4e5;border-radius:999px;font-size:12px;font-style:normal;font-weight:700}
.panel-head em.clear{color:#168757;background:#eaf8f1}

.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
.kpi{display:grid;grid-template-columns:40px minmax(0,1fr);column-gap:12px;align-items:center;padding:18px;background:#fff;border:1px solid #e3e9f2;border-radius:14px;transition:box-shadow .15s,transform .15s}
a.kpi:hover{box-shadow:0 10px 26px rgba(15,34,66,.1);transform:translateY(-1px)}
.kpi-icon{grid-row:span 2;display:grid;place-items:center;width:40px;height:40px;border-radius:12px}
.kpi-icon :deep(svg){width:20px;height:20px}
.kpi-label{color:#667085;font-size:13px;font-weight:700}
.kpi strong{font-size:23px;font-weight:800;line-height:1.25;overflow-wrap:anywhere}
.kpi small{grid-column:1/-1;margin-top:10px;color:#758197;font-size:12px}
.kpi.in .kpi-icon{color:#168757;background:#e7f7ef}.kpi.in strong{color:#12704a}
.kpi.out .kpi-icon{color:#c4323d;background:#fdecee}.kpi.out strong{color:#b02a34}
.kpi.sale .kpi-icon{color:#2457d6;background:#eaf1ff}
.kpi.buy .kpi-icon{color:#b45309;background:#fff3e0}

.actions-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px}
.action{display:grid;justify-items:center;gap:8px;padding:14px 8px;border:1px solid #e3e9f2;border-radius:12px;font-size:13px;font-weight:700;text-align:center;transition:border-color .15s,background .15s}
.action:hover{border-color:#2457d6;background:#f5f8ff}
.action span{display:grid;place-items:center;width:42px;height:42px;color:#2457d6;background:#eaf1ff;border-radius:50%}
.action span :deep(svg){width:21px;height:21px}
.action.in span{color:#168757;background:#e7f7ef}.action.out span,.action.expense span{color:#c4323d;background:#fdecee}.action.purchase span{color:#b45309;background:#fff3e0}
.action.sale{color:#fff!important;background:#2457d6;border-color:#2457d6}.action.sale:hover{background:#1d49b8}.action.sale span{color:#2457d6;background:#fff}

.columns{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:stretch}
.columns.wide-left{grid-template-columns:minmax(0,2fr) minmax(0,1fr)}

.pending-list{display:grid;gap:8px}
.pending-row{display:grid;grid-template-columns:52px minmax(0,1fr) auto 12px;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e8edf5;border-radius:10px}
a.pending-row:hover{border-color:#2457d6;background:#f5f8ff}
.pending-row b{color:#b54708;font-size:17px;font-weight:800;text-align:center}
.pending-row span{display:grid}
.pending-row strong{font-size:13px}
.pending-row small{color:#758197;font-size:11px}
.pending-row em{font-size:13px;font-style:normal;font-weight:800;white-space:nowrap}
.pending-row i{color:#98a2b3;font-size:18px;font-style:normal}
.pending-row.done b{color:#168757}

.sales-list{margin:0;padding:0;list-style:none}
.sales-list li{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid #eef2f7}
.sales-list li:last-child{border-bottom:0}
.sale-main{display:grid;min-width:0}
.sale-main strong{overflow:hidden;font-size:13px;text-overflow:ellipsis;white-space:nowrap}
.sale-main small{color:#758197;font-size:11px}
.sale-money{display:grid;justify-items:end;gap:3px;font-size:13px}
.tag{padding:2px 8px;color:#b54708;background:#fff4e5;border-radius:999px;font-size:10px;font-weight:800}
.tag.paid{color:#168757;background:#eaf8f1}.tag.partial{color:#2457d6;background:#eaf1ff}
.dash .sale-print{padding:5px 10px;color:#2457d6;border:1px solid #d8e0eb;border-radius:8px;font-size:12px;font-weight:700}

.chart{height:260px}
.legend{display:flex;align-items:center;gap:6px;color:#667085;font-size:12px}
.legend i{width:10px;height:10px;border-radius:3px}.legend i.sales{background:#2457d6}.legend i.purchases{background:#f59e0b}

.rows{display:grid;margin:0}
.rows>*{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 0;border-bottom:1px solid #eef2f7}
.rows>*:last-child{border-bottom:0}
.rows a:hover dt{color:#2457d6}
.rows dt{display:grid;color:#475569;font-size:13px;font-weight:700}
.rows dt small{color:#98a2b3;font-size:11px;font-weight:500}
.rows dd{margin:0;font-size:15px;font-weight:800;text-align:right}

.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px}
.stats a{display:grid;gap:2px;padding:12px;background:#f8fafc;border:1px solid #e8edf5;border-radius:10px}
.stats a:hover{border-color:#2457d6}
.stats small{color:#758197;font-size:11px;font-weight:700}
.stats strong{font-size:18px;font-weight:800}
.stats a.bad strong{color:#b02a34}
.alerts{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px}
.alerts h3{margin:0 0 4px;color:#667085;font-size:11px;font-weight:800;text-transform:uppercase}
.alerts a{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid #eef2f7;font-size:13px}
.alerts a:hover span{color:#2457d6}
.alerts b.bad{color:#b02a34}
.alerts p,.empty{margin:0;color:#758197;font-size:13px}
.empty{padding:18px 0;text-align:center}
.dash .button{display:inline-block;margin-top:10px;padding:8px 14px;color:#fff;background:#2457d6;border-radius:8px;font-weight:700}

@media (max-width:1100px){.columns,.columns.wide-left{grid-template-columns:minmax(0,1fr)}}
@media (max-width:600px){.kpis{grid-template-columns:1fr}.actions-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pending-row{grid-template-columns:44px minmax(0,1fr) 12px}.pending-row em{grid-column:2}}
</style>
