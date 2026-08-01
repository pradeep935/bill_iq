<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import InventoryApi from './InventoryApi';
import AppToast from '../../Components/Common/AppToast.vue';
import TableLoadingState from '../../Components/Common/TableLoadingState.vue';
import InventoryModuleScaffold from './Shared/InventoryModuleScaffold.vue';
import InventoryTable from './Shared/InventoryTable.vue';
import InventoryModal from './Shared/InventoryModal.vue';
import BarcodePreview from './Shared/BarcodePreview.vue';
import BarcodeScannerInput from './Shared/BarcodeScannerInput.vue';

defineProps({ page: { type: String, default: 'inventory-barcode-center' }, title: { type: String, default: 'Barcode Center' } });

const loading = ref(false);
const initialLoaded = ref(false);
const saving = ref(false);
const refs = ref({ products: [], categories: [], brands: [], formats: [], types: [], templates: [] });
const rows = ref([]);
const dashboard = ref({});
const reports = ref({});
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const toast = ref(null);
const modal = ref(null);
const current = ref(null);
const scanResult = ref(null);
const errors = ref({});
const activeReport = ref('product_barcodes');
const filters = ref({ search: '', product_id: '', category_id: '', brand_id: '', barcode_type: '', has_barcode: '', active_status: '', per_page: 15 });
const form = ref({ product_id: '', barcode: '', format: 'CODE128', barcode_type: 'internal', is_primary: true, is_active: true, quantity: 1 });
const label = ref({ product_id: '', barcode: '', labels_count: 10, template: '50x25', paper_size: 'A4', width: 50, height: 25, columns: 3, margin: 5, gap_x: 3, gap_y: 3, show_name: true, show_sku: true, show_price: true, show_mrp: false, show_business: true });
let timer = null;

const columns = [{ key: 'name', label: 'Product' }, { key: 'sku', label: 'SKU' }, { key: 'primary_barcode', label: 'Primary Barcode' }, { key: 'barcode_type', label: 'Barcode Type' }, { key: 'alternate_barcodes', label: 'Alternate Barcodes' }, { key: 'selling_price', label: 'Selling Price' }, { key: 'status', label: 'Status' }, { key: 'updated_at', label: 'Updated At' }];
const cards = computed(() => [
    { label: 'Products With Barcode', value: dashboard.value.with_barcode || 0, tone: 'good' },
    { label: 'Products Without Barcode', value: dashboard.value.without_barcode || 0, tone: 'warn' },
    { label: 'Alternate Barcodes', value: dashboard.value.alternate_barcodes || 0, tone: 'info' },
    { label: 'Generated Today', value: dashboard.value.generated_today || 0, tone: 'money' },
    { label: 'Labels Printed Today', value: dashboard.value.labels_printed_today || 0, tone: 'info' },
    { label: 'Duplicate/Invalid Issues', value: dashboard.value.issues || 0, tone: 'bad' },
]);
const reportRows = computed(() => reports.value?.[activeReport.value] || []);
const selectedProduct = computed(() => refs.value.products.find((p) => Number(p.id) === Number(form.value.product_id || label.value.product_id)) || current.value || {});
const money = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const statusLabel = (v) => String(v || '-').replaceAll('_', ' ');
const showToast = (message, type = 'success') => { toast.value = { title: 'Barcode Center', message, type }; };

const loadRefs = async () => { refs.value = await InventoryApi.barcodeReferences(); };
const load = async (page = 1) => {
    loading.value = true;
    try {
        const [list, reportData] = await Promise.all([InventoryApi.barcodeList({ ...filters.value, page }), InventoryApi.barcodeReports(filters.value)]);
        rows.value = list.items || [];
        dashboard.value = list.dashboard || {};
        pagination.value = list.pagination || pagination.value;
        reports.value = reportData || {};
        initialLoaded.value = true;
    } finally { loading.value = false; }
};
const clearFilters = () => { filters.value = { search: '', product_id: '', category_id: '', brand_id: '', barcode_type: '', has_barcode: '', active_status: '', per_page: 15 }; };
const openAssign = (row = null) => {
    current.value = row;
    errors.value = {};
    form.value = { product_id: row?.id || '', barcode: row?.primary_barcode || '', format: 'CODE128', barcode_type: row?.barcode_type || 'internal', is_primary: true, is_active: true, quantity: 1 };
    modal.value = 'assign';
};
const openManage = (row) => { current.value = row; modal.value = 'manage'; };
const openPrint = (row) => { current.value = row; label.value = { ...label.value, product_id: row.id, barcode: row.primary_barcode }; modal.value = 'print'; };
const saveBarcode = async () => {
    errors.value = {};
    saving.value = true;
    try { await InventoryApi.assignBarcode(form.value); showToast('Barcode saved.'); modal.value = null; await load(pagination.value.current_page || 1); } catch (e) { errors.value = e?.response?.data?.errors || {}; showToast(e?.response?.data?.message || 'Barcode save failed.', 'error'); } finally { saving.value = false; }
};
const generateBarcode = async (row, overwrite = false) => {
    try { await InventoryApi.generateBarcode({ product_id: row.id, format: 'CODE128', overwrite }); showToast('Barcode generated.'); await load(pagination.value.current_page || 1); } catch (e) {
        if (e?.response?.data?.errors?.overwrite && window.confirm('Product already has a barcode. Overwrite it?')) return generateBarcode(row, true);
        showToast(e?.response?.data?.message || 'Generate failed.', 'error');
    }
};
const setPrimary = async (id) => { await InventoryApi.setPrimaryBarcode(id); showToast('Primary barcode updated.'); await load(); };
const toggle = async (id, active) => { await InventoryApi.toggleBarcode(id, active); showToast('Barcode status updated.'); await load(); };
const scan = async (barcode) => {
    scanResult.value = null;
    const response = await InventoryApi.scanBarcode(barcode);
    scanResult.value = response.result;
    if (response.result?.product?.id) filters.value.product_id = response.result.product.id;
    showToast(response.result ? 'Barcode resolved.' : 'Barcode or serial not found.', response.result ? 'success' : 'error');
};
const recordPrint = async () => {
    saving.value = true;
    try { await InventoryApi.printBarcode(label.value); showToast('Label print recorded.'); printLabels(); await load(); } catch (e) { showToast(e?.response?.data?.message || 'Print setup failed.', 'error'); } finally { saving.value = false; }
};
const printLabels = () => {
    const product = current.value || selectedProduct.value;
    const count = Number(label.value.labels_count || 1);
    const cards = Array.from({ length: count }).map(() => `<article><strong>${label.value.show_name ? product.name || '' : ''}</strong><small>${label.value.show_sku ? product.sku || '' : ''}</small><div class="bars"></div><span>${label.value.barcode || product.primary_barcode || ''}</span><b>${label.value.show_price ? 'Rs. ' + money(product.selling_price) : ''}</b></article>`).join('');
    const win = window.open('', '_blank');
    win.document.write(`<html><head><style>@media print{body{margin:${label.value.margin}mm}}body{font-family:Arial}.sheet{display:grid;grid-template-columns:repeat(${label.value.columns}, ${label.value.width}mm);gap:${label.value.gap_y}mm ${label.value.gap_x}mm}article{box-sizing:border-box;width:${label.value.width}mm;height:${label.value.height}mm;border:1px dashed #999;display:grid;align-content:center;justify-items:center;text-align:center;padding:2mm;overflow:hidden}strong{font-size:9px;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}small{font-size:7px}.bars{height:8mm;width:90%;border-bottom:8mm repeating-linear-gradient(90deg,#111 0 1px,transparent 1px 2px,#111 2px 3px,transparent 3px 4px)}span{font-size:8px;font-weight:bold}b{font-size:8px}</style></head><body><div class="sheet">${cards}</div></body></html>`);
    win.document.close(); win.print();
};
const exportRows = (format) => {
    if (format === 'pdf') { window.print(); return; }
    const source = activeReport.value === 'product_barcodes' ? rows.value : reportRows.value;
    const data = source.map((r) => ({ product: r.name || r.product?.name, sku: r.sku || r.product?.sku, barcode: r.primary_barcode || r.barcode, type: r.barcode_type || r.event_type || r.template, status: r.status, updated_at: r.updated_at || r.created_at }));
    const csv = [Object.keys(data[0] || { report: activeReport.value }).join(','), ...data.map((r) => Object.values(r).map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(','))].join('\n');
    const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([csv], { type: format === 'excel' ? 'application/vnd.ms-excel' : 'text/csv;charset=utf-8;' })); link.download = `barcode-${activeReport.value}.${format === 'excel' ? 'xls' : 'csv'}`; link.click(); URL.revokeObjectURL(link.href);
};

watch(filters, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350); }, { deep: true });
onMounted(async () => { await loadRefs(); await load(); });
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title><div class="bill-page-title"><span>INVENTORY CONTROL</span><h1>Barcode Center</h1><p>Generate, assign, scan, preview and print product, variant, batch and serial barcode labels.</p></div></template>
        <AppToast v-if="toast" show :title="toast.title" :message="toast.message" :type="toast.type" />
        <TableLoadingState v-if="loading && !initialLoaded" title="Loading Barcode Center..." description="Checking product barcodes and label history." />
        <InventoryModuleScaffold v-else title="Barcode Register" subtitle="Manage primary and alternate barcodes with duplicate protection." :cards="cards" :loading="loading" :initial-loaded="initialLoaded" :pagination="pagination" @page="load">
            <template #toolbar><button @click="openAssign()">Assign Barcode</button><a class="button-link" href="/app/inventory/barcode-center/import-sample">Sample</a><button @click="exportRows('csv')">CSV</button><button @click="exportRows('excel')">Excel</button><button @click="exportRows('pdf')">PDF</button></template>
            <template #filters>
                <BarcodeScannerInput placeholder="Scan barcode, serial or IMEI" @scan="scan" />
                <input v-model="filters.search" placeholder="Search product, SKU or barcode" />
                <select v-model="filters.product_id"><option value="">All Products</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select>
                <select v-model="filters.category_id"><option value="">All Categories</option><option v-for="c in refs.categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                <select v-model="filters.brand_id"><option value="">All Brands</option><option v-for="b in refs.brands" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                <select v-model="filters.barcode_type"><option value="">All Types</option><option v-for="t in refs.types" :key="t">{{ t }}</option></select>
                <select v-model="filters.has_barcode"><option value="">Barcode Status</option><option value="yes">Has barcode</option><option value="no">No barcode</option></select>
                <select v-model="filters.active_status"><option value="">All Active</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
                <select v-model="filters.per_page"><option :value="15">15 / page</option><option :value="25">25 / page</option><option :value="50">50 / page</option></select>
                <button @click="clearFilters">Clear</button>
            </template>
            <div v-if="scanResult" class="scan-result"><strong>{{ scanResult.product?.name }}</strong><span>{{ scanResult.type }} resolved from scan</span></div>
            <InventoryTable :columns="columns" :rows="rows" empty-text="No barcode records found.">
                <template #cell-name="{ row }"><strong>{{ row.name }}</strong><span>{{ row.barcodes?.length || 0 }} barcode rows</span></template>
                <template #cell-alternate_barcodes="{ value }">{{ (value || []).join(', ') || '-' }}</template>
                <template #cell-selling_price="{ value }">Rs. {{ money(value) }}</template>
                <template #actions="{ row }"><div class="row-actions"><button @click="openAssign(row)">Assign/Edit</button><button @click="generateBarcode(row)">Generate</button><button @click="openManage(row)">Manage</button><button @click="current = row; modal = 'preview'">Preview</button><button @click="openPrint(row)">Print Labels</button><button @click="activeReport = 'generation_history'">History</button></div></template>
            </InventoryTable>
            <section class="reports"><div class="tabs"><button v-for="r in ['product_barcodes','without_barcode','alternate_barcodes','generation_history','printing_history']" :key="r" :class="{active: activeReport === r}" @click="activeReport = r">{{ statusLabel(r) }}</button></div></section>
        </InventoryModuleScaffold>

        <InventoryModal v-if="modal === 'assign'" title="Assign Barcode" :subtitle="selectedProduct.name" :errors="errors" @close="modal = null">
            <div class="form-grid"><select v-model="form.product_id"><option value="">Product</option><option v-for="p in refs.products" :key="p.id" :value="p.id">{{ p.name }}</option></select><input v-model="form.barcode" placeholder="Barcode value" /><select v-model="form.format"><option v-for="f in refs.formats" :key="f">{{ f }}</option></select><select v-model="form.barcode_type"><option v-for="t in refs.types" :key="t">{{ t }}</option></select><label><input v-model="form.is_primary" type="checkbox" /> Primary</label><label><input v-model="form.is_active" type="checkbox" /> Active</label></div>
            <BarcodePreview :title="selectedProduct.name" :subtitle="selectedProduct.sku" :value="form.barcode" :price="selectedProduct.selling_price" />
            <footer class="modal-actions"><button @click="modal = null">Cancel</button><button class="primary" :disabled="saving" @click="saveBarcode">Save</button></footer>
        </InventoryModal>

        <InventoryModal v-if="modal === 'manage' && current" title="Manage Barcodes" :subtitle="current.name" wide @close="modal = null">
            <InventoryTable :columns="[{key:'barcode',label:'Barcode'},{key:'format',label:'Format'},{key:'barcode_type',label:'Type'},{key:'is_primary',label:'Primary'},{key:'status',label:'Status'}]" :rows="current.barcodes || []">
                <template #actions="{ row }"><div class="row-actions"><button @click="setPrimary(row.id)">Set Primary</button><button @click="toggle(row.id, !(row.is_active ?? row.status === 'active'))">{{ (row.is_active ?? row.status === 'active') ? 'Deactivate' : 'Activate' }}</button></div></template>
            </InventoryTable>
        </InventoryModal>

        <InventoryModal v-if="modal === 'preview' && current" title="Barcode Preview" :subtitle="current.name" @close="modal = null">
            <BarcodePreview :title="current.name" :subtitle="current.sku" :value="current.primary_barcode" :price="current.selling_price" />
        </InventoryModal>

        <InventoryModal v-if="modal === 'print' && current" title="Print Labels" :subtitle="current.name" wide @close="modal = null">
            <div class="form-grid"><input v-model.number="label.labels_count" type="number" min="1" /><select v-model="label.template"><option v-for="t in refs.templates" :key="t">{{ t }}</option></select><input v-model.number="label.width" type="number" /><input v-model.number="label.height" type="number" /><input v-model.number="label.columns" type="number" /><input v-model.number="label.margin" type="number" /><input v-model.number="label.gap_x" type="number" /><input v-model.number="label.gap_y" type="number" /><label><input v-model="label.show_name" type="checkbox" /> Name</label><label><input v-model="label.show_sku" type="checkbox" /> SKU</label><label><input v-model="label.show_price" type="checkbox" /> Price</label><label><input v-model="label.show_mrp" type="checkbox" /> MRP</label><label><input v-model="label.show_business" type="checkbox" /> Business</label></div>
            <BarcodePreview :title="label.show_name ? current.name : ''" :subtitle="label.show_sku ? current.sku : ''" :value="label.barcode || current.primary_barcode" :price="label.show_price ? current.selling_price : ''" />
            <footer class="modal-actions"><button @click="modal = null">Cancel</button><button class="primary" :disabled="saving" @click="recordPrint">Print</button></footer>
        </InventoryModal>
    </Layout>
</template>

<style scoped>
input,select,button,.button-link{background:#fff;border:1px solid #d8e0eb;border-radius:8px;color:#344159;font-size:12px;font-weight:750;min-height:38px;padding:8px 10px}.button-link{align-items:center;display:inline-flex;text-decoration:none}.row-actions{display:flex;flex-wrap:wrap;gap:6px}.row-actions button{min-height:30px;padding:5px 8px}.form-grid{display:grid;gap:10px;grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:14px}.modal-actions{border-top:1px solid #edf1f5;display:flex;gap:10px;justify-content:flex-end;margin-top:18px;padding-top:14px}.primary{background:#2563eb;color:#fff}.scan-result{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;color:#166534;display:flex;gap:10px;margin-bottom:12px;padding:10px 12px}.scan-result span{color:#3f7652}.tabs{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.tabs .active{background:#173b77;color:#fff}@media(max-width:800px){.form-grid{grid-template-columns:1fr}}
</style>
