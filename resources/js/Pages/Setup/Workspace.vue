<script setup>
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Layout from '../Layout.vue';
import InventoryModuleScaffold from '../Inventory/Shared/InventoryModuleScaffold.vue';
import InventoryTable from '../Inventory/Shared/InventoryTable.vue';
import InventoryApi from '../Inventory/InventoryApi';
import axios from 'axios';

const props = defineProps({ page: String, title: String, initial_tab: { type: String, default: 'admin' } });
const inertia = usePage();
const tab = ref(props.initial_tab);
const loading = ref(false);
const loaded = ref(false);
const refs = ref({ branches: [], warehouses: [] });
const stock = ref([]);
const masters = ref({});

const rows = computed(() => {
    if (tab.value === 'users') return inertia.props?.users || [];
    if (tab.value === 'settings') return Object.entries(masters.value || {}).map(([key, value], id) => ({ id, key, value: Array.isArray(value) ? value.length : value }));
    if (tab.value === 'onboarding') return [
        { id: 1, step: 'Branches', status: refs.value.branches?.length ? 'complete' : 'pending', count: refs.value.branches?.length || 0 },
        { id: 2, step: 'Warehouses', status: refs.value.warehouses?.length ? 'complete' : 'pending', count: refs.value.warehouses?.length || 0 },
        { id: 3, step: 'Inventory Stock', status: stock.value.length ? 'complete' : 'pending', count: stock.value.length },
    ];
    return [
        { id: 1, metric: 'Branches', value: refs.value.branches?.length || 0 },
        { id: 2, metric: 'Warehouses', value: refs.value.warehouses?.length || 0 },
        { id: 3, metric: 'Stock Lines', value: stock.value.length },
    ];
});
const cards = computed(() => [
    { label: 'Branches', value: refs.value.branches?.length || 0, tone: 'info' },
    { label: 'Warehouses', value: refs.value.warehouses?.length || 0, tone: 'good' },
    { label: 'Stock Lines', value: stock.value.length, tone: 'money' },
]);

const load = async () => {
    loading.value = true;
    try {
        refs.value = await InventoryApi.stockReferences().catch(() => ({ branches: [], warehouses: [] }));
        const stockRows = await InventoryApi.stockSummary({ per_page: 25 }).catch(() => ({ stocks: [] }));
        stock.value = stockRows.stocks || [];
        masters.value = await axios.get('/app/setup/masters/references').then((r) => r.data).catch(() => ({}));
    } finally {
        loaded.value = true;
        loading.value = false;
    }
};

onMounted(load);
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title><div class="bill-page-title"><span>SETUP</span><h1>{{ title }}</h1><p>Operational workspace connected to branches, warehouses, inventory and master setup.</p></div></template>
        <InventoryModuleScaffold :title="title" :cards="cards" :loading="loading" :initial-loaded="loaded" :pagination="{ current_page: 1, last_page: 1, total: rows.length, from: rows.length ? 1 : 0, to: rows.length }">
            <template #toolbar><button @click="load">Refresh</button><a href="/app/setup/masters">Open Masters</a><a href="/app/setup/employees">Employees</a></template>
            <template #section-actions><div class="tabs"><button v-for="t in ['admin','staff','onboarding','users','saas','settings']" :key="t" :class="{ active: tab === t }" @click="tab = t">{{ t }}</button></div></template>
            <InventoryTable v-if="tab === 'onboarding'" :columns="[{key:'step',label:'Step'},{key:'status',label:'Status'},{key:'count',label:'Records'}]" :rows="rows" />
            <InventoryTable v-else-if="tab === 'settings'" :columns="[{key:'key',label:'Setting Area'},{key:'value',label:'Records'}]" :rows="rows" />
            <InventoryTable v-else-if="tab === 'users'" :columns="[{key:'name',label:'Name'},{key:'email',label:'Email'},{key:'role',label:'Role'}]" :rows="rows" empty-text="Use the connected user management endpoint when enabled for this business." />
            <InventoryTable v-else :columns="[{key:'metric',label:'Metric'},{key:'value',label:'Value'}]" :rows="rows" />
        </InventoryModuleScaffold>
    </Layout>
</template>

<style scoped>
.tabs{display:flex;flex-wrap:wrap;gap:8px}.tabs button.active{background:#142139;color:#fff}button,a{align-items:center;background:#fff;border:1px solid #d8e0eb;border-radius:8px;color:#344159;display:inline-flex;font-size:12px;font-weight:800;min-height:38px;padding:8px 10px;text-decoration:none}
</style>
