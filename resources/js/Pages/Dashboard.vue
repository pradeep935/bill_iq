<template>
  <Layout page="dashboard" title="Business Control Room">
    <template #topbar-title>
      <div class="bill-page-title">
        <span>Live workspace</span>
        <h1>Sales, stock, GST and cashflow in one workspace.</h1>
        <p>A single control room for retail billing, inventory posting, tax summaries and accounts closing.</p>
      </div>
    </template>
    <section class="page-toolbar">
      <a class="bill-primary" :href="appUrl('/app/sales/pos')">New Sale</a>
    </section>

    <section class="bill-grid bill-grid-4">
      <article v-for="stat in stats" :key="stat.label" class="bill-card">
        <span>{{ stat.label }}</span>
        <strong>{{ stat.value }}</strong>
        <small>{{ stat.hint }}</small>
      </article>
    </section>

    <section class="bill-grid bill-grid-4">
      <article v-for="item in workflow" :key="item.label" class="bill-workflow-card">
        <span>{{ item.step }}</span>
        <strong>{{ item.label }}</strong>
        <small>{{ item.hint }}</small>
      </article>
    </section>

    <section class="bill-grid bill-grid-2">
      <article class="bill-card">
        <div class="bill-card-head">
          <h3>Recent Sales</h3>
          <a :href="appUrl('/app/sales/invoices')">View all</a>
        </div>
        <table class="bill-table">
          <tbody>
            <tr v-for="sale in recentSales" :key="sale.invoice">
              <td>{{ sale.invoice }}</td>
              <td>{{ sale.customer }}</td>
              <td>{{ sale.total }}</td>
              <td><span class="bill-badge">{{ sale.payment }}</span></td>
            </tr>
          </tbody>
        </table>
      </article>

      <article class="bill-card">
        <div class="bill-card-head">
          <h3>Accounting Checklist</h3>
        </div>
        <div class="bill-tasks">
          <span>Sales invoices posted to receivable and output GST</span>
          <span>Purchase bills posted to supplier payable and input GST</span>
          <span>Stock ledger updated through inward, outward and adjustment vouchers</span>
          <span>Cash, bank, expenses and journal vouchers available for closing</span>
        </div>
      </article>
    </section>
  </Layout>
</template>

<script setup>
import Layout from './Layout.vue';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

defineProps({
  stats: { type: Array, default: () => [] },
  recentSales: { type: Array, default: () => [] },
});

const workflow = [
  { step: '01', label: 'Catalog', hint: 'SKU, barcode, HSN, GST and price master' },
  { step: '02', label: 'Billing', hint: 'POS, credit invoices and payment modes' },
  { step: '03', label: 'Inventory', hint: 'Batch, stock ledger, transfers and valuation' },
  { step: '04', label: 'Accounts', hint: 'Vouchers, ledgers, GST and reports' },
];

const inertiaPage = usePage();
const baseUrl = computed(() => String(inertiaPage.props.app?.base_url || '').replace(/\/$/, ''));

const appUrl = (path) => {
  const normalizedPath = `/${String(path || '').replace(/^\/+/, '')}`;

  return `${baseUrl.value}${normalizedPath}`;
};
</script>
