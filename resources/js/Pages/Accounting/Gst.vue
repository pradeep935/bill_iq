<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import Layout from '../Layout.vue';
import InventoryModuleScaffold from '../Inventory/Shared/InventoryModuleScaffold.vue';
import InventoryTable from '../Inventory/Shared/InventoryTable.vue';
import axios from 'axios';

const props = defineProps({ page: String, title: String, initial_tab: { type: String, default: 'summary' } });
const tab = ref(props.initial_tab);
const loading = ref(false);
const loaded = ref(false);
const filters = reactive({ from_date: '', to_date: '' });
const sales = ref({});
const purchases = ref({});

const rows = computed(() => [
    { id: 1, section: 'Sales GST', taxable: sales.value.taxable_sales || sales.value.subtotal || 0, cgst: sales.value.cgst || 0, sgst: sales.value.sgst || 0, igst: sales.value.igst || 0, total: sales.value.tax || 0 },
    { id: 2, section: 'Purchase GST', taxable: purchases.value.taxable_purchases || purchases.value.subtotal || 0, cgst: purchases.value.cgst || 0, sgst: purchases.value.sgst || 0, igst: purchases.value.igst || 0, total: purchases.value.tax || 0 },
]);
const cards = computed(() => [
    { label: 'Output GST', value: Number(rows.value[0].total || 0).toLocaleString('en-IN'), tone: 'money' },
    { label: 'Input GST', value: Number(rows.value[1].total || 0).toLocaleString('en-IN'), tone: 'info' },
    { label: 'Net Payable', value: Number((rows.value[0].total || 0) - (rows.value[1].total || 0)).toLocaleString('en-IN'), tone: 'warn' },
]);

const load = async () => {
    loading.value = true;
    try {
        const [saleReport, purchaseReport] = await Promise.all([
            axios.get('/app/sales/invoices/reports', { params: filters }).then((r) => r.data).catch(() => ({})),
            axios.get('/app/purchase/bills/reports', { params: filters }).then((r) => r.data).catch(() => ({})),
        ]);
        sales.value = saleReport.summary || saleReport;
        purchases.value = purchaseReport.summary || purchaseReport;
    } finally {
        loaded.value = true;
        loading.value = false;
    }
};

onMounted(load);
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title><div class="bill-page-title"><span>ACCOUNTING</span><h1>{{ title }}</h1><p>GST summary and return figures from posted sales and purchase vouchers.</p></div></template>
        <InventoryModuleScaffold :title="title" :cards="cards" :loading="loading" :initial-loaded="loaded" :pagination="{ current_page: 1, last_page: 1, total: rows.length, from: rows.length ? 1 : 0, to: rows.length }">
            <template #toolbar><button @click="load">Refresh</button><button @click="window.print()">PDF / Print</button></template>
            <template #section-actions><div class="tabs"><button :class="{ active: tab === 'summary' }" @click="tab = 'summary'">summary</button><button :class="{ active: tab === 'returns' }" @click="tab = 'returns'">returns</button></div></template>
            <template #filters><input v-model="filters.from_date" type="date" @change="load" /><input v-model="filters.to_date" type="date" @change="load" /><button @click="filters.from_date = ''; filters.to_date = ''; load()">Clear</button></template>
            <InventoryTable :columns="[{key:'section',label:'Section'},{key:'taxable',label:'Taxable'},{key:'cgst',label:'CGST'},{key:'sgst',label:'SGST'},{key:'igst',label:'IGST'},{key:'total',label:'GST Total'}]" :rows="rows" />
        </InventoryModuleScaffold>
    </Layout>
</template>

<style scoped>
.tabs{display:flex;gap:8px}.tabs button.active{background:#142139;color:#fff}input,button{border:1px solid #d8e0eb;border-radius:8px;font-size:12px;min-height:38px;padding:8px 10px}button{background:#fff;cursor:pointer;font-weight:800}
</style>
