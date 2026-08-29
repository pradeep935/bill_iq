<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import InventoryApi from './InventoryApi';
import CrudTable from '../../Components/Common/CrudTable.vue';
import CrudDrawer from '../../Components/Common/CrudDrawer.vue';
import ListingCard from '../../Components/Common/ListingCard.vue';
import ListingSummaryCards from '../../Components/Common/ListingSummaryCards.vue';
import RowActionMenu from '../../Components/Common/RowActionMenu.vue';
import TableLoadingState from '../../Components/Common/TableLoadingState.vue';
import { formatInventoryDateTime, formatInventoryQty } from './Shared/formatters';

defineProps({
    page: { type: String, default: 'inventory-current-stock' },
    title: { type: String, default: 'Current Stock' },
});

const loading = ref(false);
const detailLoading = ref(false);
const rows = ref([]);
const dashboard = ref({
    total_products: 0,
    total_quantity: 0,
    inventory_value: 0,
    low_stock_products: 0,
    out_of_stock_products: 0,
});
const references = ref({ branches: [], warehouses: [], categories: [], brands: [] });
const selectedDetail = ref(null);
const detailMode = ref('view');
const openActionMenuId = ref(null);
const expandedProducts = ref({});
const expandedDetails = ref({});
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const filters = ref({
    view_mode: 'summary',
    search: '',
    branch_id: '',
    warehouse_id: '',
    category: '',
    brand: '',
    stock_status: '',
    batch: '',
    expiry_status: '',
    sort: 'product_name',
    direction: 'asc',
    per_page: 15,
});

const detailedColumns = [
    { key: 'image', label: 'Image', class: 'stock-col-image stock-col-image-first' },
    { key: 'product_name', label: 'Product Name', class: 'stock-col-product stock-col-product-first' },
    { key: 'sku', label: 'SKU' },
    { key: 'barcode', label: 'Barcode' },
    { key: 'category', label: 'Category' },
    { key: 'brand', label: 'Brand' },
    { key: 'branch', label: 'Branch' },
    { key: 'warehouse', label: 'Warehouse' },
    { key: 'batch', label: 'Batch' },
    { key: 'quantity_on_hand', label: 'Saleable Qty' },
    { key: 'non_saleable_quantity', label: 'Non-Saleable Qty' },
    { key: 'reserved_quantity', label: 'Reserved Qty' },
    { key: 'quantity_available', label: 'Available Qty' },
    { key: 'unit', label: 'Unit' },
    { key: 'average_cost', label: 'Average Cost' },
    { key: 'stock_value', label: 'Saleable Value' },
    { key: 'last_updated', label: 'Last Updated' },
    { key: 'status', label: 'Status' },
];

const summaryColumns = [
    { key: 'expand', label: '', class: 'stock-col-expand' },
    { key: 'image', label: 'Image', class: 'stock-col-image' },
    { key: 'product_name', label: 'Product Name', class: 'stock-col-product' },
    { key: 'sku', label: 'SKU' },
    { key: 'barcode', label: 'Barcode' },
    { key: 'brand', label: 'Brand' },
    { key: 'quantity_on_hand', label: 'Saleable Qty' },
    { key: 'unit', label: 'Unit' },
    { key: 'average_cost', label: 'Average Cost' },
    { key: 'stock_value', label: 'Saleable Value' },
    { key: 'branch_count', label: 'Branches' },
    { key: 'status', label: 'Status' },
];

const columns = computed(() => filters.value.view_mode === 'summary' ? summaryColumns : detailedColumns);

let timer = null;

const filteredWarehouses = computed(() => {
    if (!filters.value.branch_id) return references.value.warehouses || [];

    return (references.value.warehouses || []).filter((warehouse) =>
        Number(warehouse.branch_id || 0) === Number(filters.value.branch_id)
    );
});

const summaryCards = computed(() => [
    { label: 'Total Products', value: dashboard.value.total_products },
    { label: 'Physical Quantity', value: formatQty(dashboard.value.total_quantity) },
    { label: 'Saleable Quantity', value: formatQty(dashboard.value.saleable_quantity) },
    { label: 'Non-Saleable Quantity', value: formatQty(dashboard.value.non_saleable_quantity), filter: 'non_saleable' },
    { label: 'Saleable Value', value: `Rs. ${formatMoney(dashboard.value.inventory_value)}` },
    { label: 'Low Stock Products', value: dashboard.value.low_stock_products },
    { label: 'Out of Stock Products', value: dashboard.value.out_of_stock_products },
]);

const loadReferences = async () => {
    references.value = await InventoryApi.stockReferences();
};

const loadSummary = async (page = 1) => {
    loading.value = true;

    try {
        const response = await InventoryApi.stockSummary({ ...filters.value, page });
        rows.value = response.items || [];
        dashboard.value = response.dashboard || dashboard.value;
        pagination.value = response.pagination || pagination.value;
    } finally {
        loading.value = false;
    }
};

const clearFilters = () => {
    filters.value = {
        view_mode: 'summary',
        search: '',
        branch_id: '',
        warehouse_id: '',
        category: '',
        brand: '',
        stock_status: '',
        batch: '',
        expiry_status: '',
        sort: 'product_name',
        direction: 'asc',
        per_page: 15,
    };
};

const setStockFilter = (card) => {
    if (!card?.filter) return;
    filters.value.stock_status = card.filter;
};

const valueFor = (row, column) => {
    if ([
        'physical_quantity',
        'quantity_on_hand',
        'saleable_quantity',
        'non_saleable_quantity',
        'reserved_quantity',
        'quantity_available',
        'damaged_quantity',
        'expired_quantity',
        'defective_quantity',
        'quarantined_quantity',
        'lost_quantity',
    ].includes(column.key)) return formatQty(row[column.key]);
    if (column.key === 'branch_count') return row.branch_count || '-';
    if (['average_cost', 'stock_value'].includes(column.key)) return `Rs. ${formatMoney(row[column.key])}`;
    if (column.key === 'last_updated') return formatDate(row.last_updated);
    return row[column.key] || '-';
};

const stockStatus = (row) => String(row.stock_status || 'In Stock').toLowerCase().replaceAll(' ', '-');

const productSubtext = (row) => row.category || row.brand || row.sku || 'Inventory product';

const rowActionKey = (row) => [
    row.product_id || 0,
    row.branch_id || 0,
    row.warehouse_id || 0,
    row.batch_id || row.batch || 0,
].join(':');

const toggleActionMenu = (row) => {
    const key = rowActionKey(row);
    openActionMenuId.value = openActionMenuId.value === key ? null : key;
};

const closeActionMenu = () => {
    openActionMenuId.value = null;
};

const stockImage = (source) => {
    const path = String(source?.image || source?.product?.image || '').trim();

    if (!path) {
        return '';
    }

    if (path.startsWith('data:') || path.startsWith('blob:')) {
        return path;
    }

    const appBasePath = window.location.pathname.split('/app')[0] || '';
    let cleanPath = path.replace(/^\/+/, '');

    if (/^(https?:)?\/\//.test(path)) {
        try {
            cleanPath = new URL(path, window.location.origin).pathname.replace(/^\/+/, '');
        } catch {
            return path;
        }
    }

    if (cleanPath.startsWith('storage/uploads/')) {
        cleanPath = cleanPath.replace(/^storage\//, '');
    }

    if (cleanPath.startsWith('uploads/') || cleanPath.startsWith('upload/') || cleanPath.startsWith('storage/')) {
        return `${appBasePath}/${cleanPath}`;
    }

    return `${appBasePath}/uploads/${cleanPath}`;
};

const drawerTitle = computed(() => selectedDetail.value?.product?.name || 'Inventory Summary');
const drawerDescription = computed(() => selectedDetail.value
    ? `${selectedDetail.value.product.sku || '-'} | ${selectedDetail.value.product.barcode || 'No barcode'}`
    : 'Ledger-based product stock summary');

const viewProduct = async (row, mode = 'view') => {
    closeActionMenu();
    detailLoading.value = true;
    selectedDetail.value = null;
    detailMode.value = mode;

    try {
        selectedDetail.value = await InventoryApi.stockProductDetail(row.product_id, {
            branch_id: row.branch_id || '',
            warehouse_id: row.warehouse_id || '',
            product_variant_id: row.product_variant_id || '',
            batch_id: row.batch_id || '',
        });
    } finally {
        detailLoading.value = false;
    }
};

const showLedger = (row) => viewProduct(row, 'ledger');
const showBatch = (row) => viewProduct(row, 'batch');
const showSerial = (row) => viewProduct(row, 'serial');

const closeDetail = () => {
    selectedDetail.value = null;
    detailLoading.value = false;
};

const toggleExpand = async (row) => {
    const id = row.product_id;
    expandedProducts.value[id] = !expandedProducts.value[id];

    if (expandedProducts.value[id] && !expandedDetails.value[id]) {
        expandedDetails.value[id] = await InventoryApi.stockProductDetail(id);
    }
};

const printBarcode = (row) => {
    closeActionMenu();
    const popup = window.open('', '_blank', 'width=360,height=260');
    if (!popup) return;
    popup.document.write(`<html><head><title>Barcode</title></head><body style="font-family:Arial;text-align:center;padding:24px"><strong>${row.product_name}</strong><p>${row.sku || ''}</p><div style="font-size:22px;letter-spacing:2px;border:1px solid #111;padding:18px">${row.barcode || 'No Barcode'}</div></body></html>`);
    popup.document.close();
    popup.print();
};

const exportRows = (type = 'csv') => {
    const exportColumns = columns.value.filter((column) => column.key !== 'expand');
    const headings = exportColumns.map((column) => column.label).concat(['Status']);
    const lines = [headings, ...rows.value.map((row) => exportColumns.map((column) => valueFor(row, column)).concat([row.stock_status]))];

    if (type === 'pdf') {
        const popup = window.open('', '_blank', 'width=1000,height=700');
        if (!popup) return;
        popup.document.write(`<html><head><title>Current Stock</title></head><body><h2>Current Stock</h2><table border="1" cellspacing="0" cellpadding="6">${lines.map((line, index) => `<tr>${line.map((cell) => `<${index ? 'td' : 'th'}>${cell ?? ''}</${index ? 'td' : 'th'}>`).join('')}</tr>`).join('')}</table></body></html>`);
        popup.document.close();
        popup.print();
        return;
    }

    const csv = lines
        .map((line) => line.map((value) => `"${String(value ?? '').replaceAll('"', '""')}"`).join(','))
        .join('\n');
    const blob = new Blob([csv], { type: type === 'excel' ? 'application/vnd.ms-excel;charset=utf-8;' : 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `current-stock-export.${type === 'excel' ? 'xls' : 'csv'}`;
    link.click();
    URL.revokeObjectURL(url);
};

const conditionMeta = [
    { key: 'damaged', label: 'Damaged' },
    { key: 'expired', label: 'Expired' },
    { key: 'defective', label: 'Defective' },
    { key: 'quarantined', label: 'Quarantined' },
    { key: 'lost', label: 'Lost' },
];

const numericValue = (value) => Number.isFinite(Number(value)) ? Number(value) : 0;
const hasQty = (value) => Math.abs(numericValue(value)) > 0.0004;
const formatQty = formatInventoryQty;
const formatMoney = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatDateTime = formatInventoryDateTime;
const formatDate = formatDateTime;
const conditionQty = (row, condition) => Number((row.condition_stock || []).find((item) => item.condition === condition)?.quantity || 0);
const visibleConditionStock = (rows = []) => rows.filter((row) => hasQty(row.quantity));
const conditionClass = (condition) => `condition-${String(condition || 'saleable').replaceAll('_', '-')}`;
const rowConditionBadges = (row) => conditionMeta
    .map((condition) => ({
        ...condition,
        quantity: conditionQty(row, condition.key) || row[`${condition.key}_quantity`],
    }))
    .filter((condition) => hasQty(condition.quantity))
    .map((condition) => ({
        key: condition.key,
        label: condition.label,
        value: formatQty(condition.quantity),
    }));
const rowConditionMetrics = (row, includeValue = false) => {
    const metrics = [
        { label: 'Physical', value: formatQty(row.physical_quantity) },
        { label: 'Saleable', value: formatQty(row.saleable_quantity ?? row.quantity_on_hand) },
    ];

    conditionMeta.forEach((condition) => {
        const quantity = conditionQty(row, condition.key) || row[`${condition.key}_quantity`];
        if (hasQty(quantity)) {
            metrics.push({ label: condition.label, value: formatQty(quantity), tone: condition.key });
        }
    });

    metrics.push({ label: 'Available', value: formatQty(row.available_quantity ?? row.quantity_available) });

    if (includeValue) {
        metrics.push({ label: 'Value', value: `Rs. ${formatMoney(row.value ?? row.stock_value)}` });
    }

    return metrics;
};
const movementText = (row) => {
    if (Number(row.stock_in || 0)) return `In to ${row.stock_status || 'saleable'}`;
    if (Number(row.stock_out || 0)) return `Out from ${row.stock_status || 'saleable'}`;
    return '-';
};

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => loadSummary(1), 300);
}, { deep: true });

watch(() => filters.value.branch_id, () => {
    filters.value.warehouse_id = '';
});

onMounted(async () => {
    await loadReferences();
    await loadSummary();
});
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title"><span>STOCK LEDGER</span><h1>Current Stock</h1><p>Ledger-based stock by product, branch, warehouse, batch and valuation.</p></div>
        </template>

        <div class="stock-page">
            <div class="page-actions">
                <button type="button" title="Export CSV" @click="exportRows('csv')">CSV</button>
                <button type="button" title="Export Excel" @click="exportRows('excel')">Excel</button>
                <button type="button" title="Export PDF" @click="exportRows('pdf')">PDF</button>
            </div>

            <ListingSummaryCards :cards="summaryCards" @select="setStockFilter" />

            <ListingCard>
                <div class="listing-toolbar">
                    <div class="view-switch">
                        <button type="button" :class="{ active: filters.view_mode === 'summary' }" @click="filters.view_mode = 'summary'">Summary View</button>
                        <button type="button" :class="{ active: filters.view_mode === 'detailed' }" @click="filters.view_mode = 'detailed'">Detailed View</button>
                    </div>

                    <div class="search-box">
                        <span></span>
                        <input v-model="filters.search" type="text" placeholder="Search product, SKU, barcode, HSN, brand" />
                    </div>

                    <div class="filter-group">
                        <select v-model="filters.branch_id">
                            <option value="">All Branches</option>
                            <option v-for="branch in references.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                        <select v-model="filters.warehouse_id">
                            <option value="">All Warehouses</option>
                            <option v-for="warehouse in filteredWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                        </select>
                        <select v-model="filters.category">
                            <option value="">All Categories</option>
                            <option v-for="category in references.categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                        <select v-model="filters.brand">
                            <option value="">All Brands</option>
                            <option v-for="brand in references.brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
                        </select>
                        <input v-model="filters.batch" type="text" placeholder="Batch" />
                        <select v-model="filters.stock_status">
                            <option value="">All Stock</option>
                            <option value="saleable">Saleable</option>
                            <option value="non_saleable">Non-Saleable</option>
                            <option value="damaged">Damaged</option>
                            <option value="expired">Expired</option>
                            <option value="defective">Defective</option>
                            <option value="quarantined">Quarantined</option>
                            <option value="lost">Lost</option>
                            <option value="low">Low Stock</option>
                            <option value="out">Out of Stock</option>
                        </select>
                        <select v-model="filters.per_page">
                            <option :value="15">15 / page</option>
                            <option :value="25">25 / page</option>
                            <option :value="50">50 / page</option>
                        </select>
                        <button type="button" @click="clearFilters">Clear</button>
                    </div>
                </div>

                <CrudTable
                    :columns="columns"
                    :rows="rows"
                    :loading="loading"
                    :value-for="valueFor"
                    :wrap-class="filters.view_mode === 'detailed' ? 'stock-table-wrap detailed-stock-table-wrap' : 'stock-table-wrap summary-stock-table-wrap'"
                    :table-class="filters.view_mode === 'detailed' ? 'detailed-stock-table' : 'summary-stock-table'"
                    :show-status="false"
                    loading-text="Loading current stock..."
                    loading-description="Please wait while ledger balances are calculated."
                    empty-text="No stock ledger balance found."
                >
                    <template #cell-image="{ row }">
                        <div class="product-image">
                            <img v-if="stockImage(row)" :src="stockImage(row)" :alt="row.product_name" />
                            <span v-else>{{ String(row.product_name || 'P').charAt(0).toUpperCase() }}</span>
                        </div>
                    </template>

                    <template #cell-expand="{ row }">
                        <button type="button" class="expand-button" :title="expandedProducts[row.product_id] ? 'Collapse details' : 'Expand details'" @click="toggleExpand(row)">
                            {{ expandedProducts[row.product_id] ? 'v' : '>' }}
                        </button>
                    </template>

                    <template #cell-product_name="{ row }">
                        <div class="product-info">
                            <strong>{{ row.product_name }}</strong>
                            <span>{{ productSubtext(row) }}</span>
                            <div v-if="rowConditionBadges(row).length" class="condition-badges">
                                <span
                                    v-for="badge in rowConditionBadges(row)"
                                    :key="`${row.product_id}-${badge.key}`"
                                    class="condition-badge"
                                    :class="conditionClass(badge.key)"
                                >
                                    {{ badge.label }} {{ badge.value }}
                                </span>
                            </div>
                        </div>
                    </template>

                    <template #cell-stock_value="{ row }">
                        <strong>Rs. {{ formatMoney(row.stock_value) }}</strong>
                    </template>

                    <template #cell-last_updated="{ row }">
                        {{ formatDate(row.last_updated) }}
                    </template>

                    <template #cell-status="{ row }">
                        <span class="stock-badge" :class="stockStatus(row)">{{ row.stock_status }}</span>
                    </template>

                    <template #actions="{ row }">
                        <RowActionMenu
                            :open="openActionMenuId === rowActionKey(row)"
                            view-title="View product inventory"
                            @view="viewProduct(row)"
                            @toggle="toggleActionMenu(row)"
                            @close="closeActionMenu"
                        >
                            <button type="button" title="Open stock ledger for this product, branch and warehouse" @click="showLedger(row)">Stock Ledger</button>
                            <button v-if="row.batch_required || row.batch_id" type="button" title="View batch-wise stock" @click="showBatch(row)">Batch Details</button>
                            <button v-if="row.serial_required" type="button" title="View serial numbers" @click="showSerial(row)">Serial Details</button>
                            <button type="button" title="Print barcode labels" @click="printBarcode(row)">Print Barcode</button>
                        </RowActionMenu>
                    </template>
                </CrudTable>

                <div v-if="filters.view_mode === 'summary'" class="expanded-list">
                    <section v-for="row in rows.filter((item) => expandedProducts[item.product_id])" :key="`expanded-${row.product_id}`" class="expanded-card">
                        <h3>{{ row.product_name }}</h3>
                        <div v-if="!expandedDetails[row.product_id]" class="detail-empty">Loading breakdown...</div>
                        <div v-else>
                            <div class="mini-row" v-for="(stock, index) in expandedDetails[row.product_id].warehouse_stock" :key="index">
                                <span>{{ stock.branch }} / {{ stock.warehouse }}</span>
                                <div class="metric-chip-row">
                                    <span
                                        v-for="metric in rowConditionMetrics(stock, true)"
                                        :key="`${index}-${metric.label}`"
                                        class="metric-chip"
                                        :class="metric.tone ? conditionClass(metric.tone) : ''"
                                    >
                                        <small>{{ metric.label }}</small>
                                        <strong>{{ metric.value }}</strong>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="pagination">
                    <button type="button" :disabled="pagination.current_page <= 1 || loading" @click="loadSummary(pagination.current_page - 1)">Previous</button>
                    <span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span>
                    <button type="button" :disabled="pagination.current_page >= pagination.last_page || loading" @click="loadSummary(pagination.current_page + 1)">Next</button>
                </div>
            </ListingCard>
        </div>

        <CrudDrawer
            :model-value="Boolean(selectedDetail || detailLoading)"
            :title="drawerTitle"
            :description="drawerDescription"
            eyebrow="STOCK LEDGER"
            :show-footer="false"
            @close="closeDetail"
        >
            <template #icon>
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5v-9Z" stroke="currentColor" stroke-width="1.8"/>
                    <path d="m4.5 7.5 7.5 4.2 7.5-4.2M12 12v8.5" stroke="currentColor" stroke-width="1.8"/>
                </svg>
            </template>
            <div class="drawer-detail">
                <TableLoadingState
                    v-if="detailLoading"
                    title="Loading inventory summary..."
                    description="Please wait while product stock details are loaded."
                    :rows="2"
                />
                <template v-else>
                    <div class="detail-product-head">
                        <div class="product-image large">
                            <img v-if="stockImage(selectedDetail.product)" :src="stockImage(selectedDetail.product)" :alt="selectedDetail.product.name" />
                            <span v-else>{{ String(selectedDetail.product.name || 'P').charAt(0).toUpperCase() }}</span>
                        </div>
                        <div>
                            <h2>{{ selectedDetail.product.name }}</h2>
                            <p>{{ selectedDetail.product.sku }} | {{ selectedDetail.product.barcode || 'No barcode' }} | {{ selectedDetail.product.category || '-' }} | {{ selectedDetail.product.brand || '-' }}</p>
                        </div>
                    </div>
                    <div class="detail-cards">
                        <div><span>Physical Qty</span><strong>{{ formatQty(selectedDetail.valuation.physical_quantity) }}</strong></div>
                        <div><span>Saleable Qty</span><strong>{{ formatQty(selectedDetail.valuation.saleable_quantity) }}</strong></div>
                        <div><span>Reserved Qty</span><strong>{{ formatQty(selectedDetail.valuation.reserved) }}</strong></div>
                        <div><span>Available for Sale</span><strong>{{ formatQty(selectedDetail.valuation.available) }}</strong></div>
                        <div><span>Non-Saleable Qty</span><strong>{{ formatQty(selectedDetail.valuation.non_saleable_quantity) }}</strong></div>
                        <div><span>Saleable Value</span><strong>Rs. {{ formatMoney(selectedDetail.valuation.value) }}</strong></div>
                    </div>
                    <div class="detail-grid">
                        <div><strong>Last Movement</strong><span>{{ formatDateTime(selectedDetail.last_movement) }}</span></div>
                        <div><strong>Last Purchase</strong><span>{{ formatDateTime(selectedDetail.last_purchase) }}</span></div>
                        <div><strong>Last Sale</strong><span>{{ formatDateTime(selectedDetail.last_sale) }}</span></div>
                        <div><strong>Last Adjustment</strong><span>{{ selectedDetail.last_adjustment ? selectedDetail.last_adjustment_reference : '-' }}<br v-if="selectedDetail.last_adjustment">{{ formatDateTime(selectedDetail.last_adjustment) }}</span></div>
                    </div>
                    <template v-if="detailMode === 'view'">
                        <h3>Stock By Condition</h3>
                        <div class="condition-grid">
                            <div
                                v-for="row in visibleConditionStock(selectedDetail.condition_stock)"
                                :key="row.condition"
                                class="condition-card"
                                :class="conditionClass(row.condition)"
                            >
                                <span>{{ row.label }}</span>
                                <strong>{{ formatQty(row.quantity) }} {{ selectedDetail.product.unit }}</strong>
                            </div>
                        </div>
                        <h3>Branch-wise Stock</h3>
                        <div class="mini-row stock-breakdown-row" v-for="row in selectedDetail.branch_stock" :key="row.branch_id">
                            <span>{{ row.branch }}</span>
                            <div class="metric-chip-row">
                                <span
                                    v-for="metric in rowConditionMetrics(row, true)"
                                    :key="`${row.branch_id}-${metric.label}`"
                                    class="metric-chip"
                                    :class="metric.tone ? conditionClass(metric.tone) : ''"
                                >
                                    <small>{{ metric.label }}</small>
                                    <strong>{{ metric.value }}</strong>
                                </span>
                            </div>
                        </div>
                        <h3>Warehouse-wise Stock</h3>
                        <div class="mini-row stock-breakdown-row" v-for="(row, index) in selectedDetail.warehouse_stock" :key="index">
                            <span>{{ row.branch }} / {{ row.warehouse }}</span>
                            <div class="metric-chip-row">
                                <span
                                    v-for="metric in rowConditionMetrics(row)"
                                    :key="`${index}-${metric.label}`"
                                    class="metric-chip"
                                    :class="metric.tone ? conditionClass(metric.tone) : ''"
                                >
                                    <small>{{ metric.label }}</small>
                                    <strong>{{ metric.value }}</strong>
                                </span>
                            </div>
                        </div>
                    </template>
                    <template v-if="detailMode === 'view' || detailMode === 'ledger'">
                        <h3>Recent Stock Movements / Stock Ledger</h3>
                        <div v-if="!selectedDetail.recent_movements?.length" class="detail-empty">No stock movements found.</div>
                        <div v-else class="ledger-table-wrap">
                            <table class="ledger-table">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Type</th>
                                        <th>Reference</th>
                                        <th>Movement</th>
                                        <th class="numeric">Qty</th>
                                        <th class="numeric">Saleable Balance</th>
                                        <th class="numeric">Physical Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in selectedDetail.recent_movements" :key="row.id">
                                        <td>{{ formatDateTime(row.date_time) }}</td>
                                        <td>{{ row.transaction_label || row.transaction_type }}</td>
                                        <td>{{ row.reference_number || '-' }}</td>
                                        <td>{{ row.movement || movementText(row) }}</td>
                                        <td class="numeric">{{ formatQty(row.quantity || row.stock_in || row.stock_out) }}</td>
                                        <td class="numeric">{{ formatQty(row.saleable_balance ?? row.running_balance) }}</td>
                                        <td class="numeric">{{ formatQty(row.physical_balance ?? row.running_balance) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                    <template v-if="detailMode === 'view' || detailMode === 'batch'">
                        <h3>Batch Details</h3>
                        <div v-if="!selectedDetail.batch_stock.length" class="detail-empty">No batch stock.</div>
                        <div class="mini-row" v-for="(row, index) in selectedDetail.batch_stock" :key="index"><span>{{ row.batch }} / {{ formatDateTime(row.expiry_date) }}</span><strong>Physical {{ formatQty(row.physical_quantity) }} | Saleable {{ formatQty(row.saleable_quantity) }}</strong></div>
                    </template>
                    <template v-if="detailMode === 'view' || detailMode === 'serial'">
                        <h3>Serial Numbers</h3>
                        <div v-if="!selectedDetail.serial_numbers.length" class="detail-empty">No serial numbers.</div>
                        <div class="mini-row" v-for="(row, index) in selectedDetail.serial_numbers" :key="index"><span>{{ row.serial_number }}</span><strong>{{ row.status }}</strong></div>
                    </template>
                </template>
            </div>
        </CrudDrawer>
    </Layout>
</template>

<style scoped>
.stock-page { padding: 4px 0 28px; }
.page-actions { display: flex; gap: 8px; justify-content: flex-end; margin-bottom: 18px; }
.page-actions button, .pagination button, .filter-group button { background: #fff; border: 1px solid #d8e0eb; border-radius: 8px; color: #35435b; cursor: pointer; font-size: 11px; font-weight: 750; min-height: 38px; padding: 8px 14px; }
.listing-toolbar { align-items: flex-start; display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; margin-bottom: 14px; position: relative; }
.view-switch { background: #f8fafc; border: 1px solid #d8e0eb; border-radius: 9px; display: inline-flex; gap: 4px; padding: 4px; }
.view-switch button { background: transparent; border: 0; border-radius: 7px; color: #536179; cursor: pointer; font-size: 11px; font-weight: 800; min-height: 32px; padding: 0 11px; }
.view-switch button.active { background: #2457d6; color: #fff; }
.search-box { align-items: center; border: 1px solid #d8e0eb; border-radius: 9px; display: flex; gap: 10px; min-height: 42px; padding: 0 12px; width: min(360px, 100%); }
.search-box span { border: 2px solid #8b98ac; border-radius: 50%; height: 13px; position: relative; width: 13px; }
.search-box span::after { background: #8b98ac; content: ''; height: 6px; position: absolute; right: -5px; top: 9px; transform: rotate(45deg); width: 2px; }
.search-box input { border: 0; color: #22304a; flex: 1; font-size: 12px; font-weight: 650; min-width: 0; outline: none; }
.filter-group { display: flex; flex-wrap: wrap; gap: 9px; justify-content: flex-end; }
.filter-group input, .filter-group select { background: #fff; border: 1px solid #d8e0eb; border-radius: 8px; color: #344159; font-size: 12px; font-weight: 650; min-height: 38px; min-width: 132px; padding: 8px 10px; }
.product-image { align-items: center; background: #edf2ff; border-radius: 9px; display: inline-flex; height: 42px; justify-content: center; overflow: hidden; width: 42px; }
.product-image img { display: block; height: 100%; object-fit: cover; width: 100%; }
.product-image span { color: #2457d6; font-weight: 850; }
.product-info strong, .product-info span { display: block; }
.product-info strong { color: #27344c; font-weight: 850; }
.product-info span { color: #8490a2; font-size: 10px; margin-top: 2px; }
.condition-badges { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 7px; max-width: 260px; }
.condition-badge { border-radius: 999px; display: inline-flex !important; font-size: 10px !important; font-weight: 850; line-height: 1; padding: 5px 7px; }
.condition-badge.condition-damaged { background: #fff8ed; color: #b45309; }
.condition-badge.condition-expired { background: #fff1f2; color: #be123c; }
.condition-badge.condition-defective { background: #fffbeb; color: #a16207; }
.condition-badge.condition-quarantined { background: #eff6ff; color: #1d4ed8; }
.condition-badge.condition-lost { background: #f1f5f9; color: #475569; }
:deep(.stock-col-expand) { background: #fff; left: 0; min-width: 44px; position: sticky; z-index: 5; }
:deep(.stock-col-image) { background: #fff; left: 44px; min-width: 62px; position: sticky; z-index: 5; }
:deep(.stock-col-product) { background: #fff; left: 106px; min-width: 210px; position: sticky; z-index: 5; }
:deep(.stock-col-image-first) { left: 0; }
:deep(.stock-col-product-first) { left: 62px; }
:deep(th.stock-col-expand),
:deep(th.stock-col-image),
:deep(th.stock-col-product) { background: #f8fafc; z-index: 6; }
:deep(.crud-action-column) { min-width: 132px; width: 132px; }
:deep(.crud-row-actions) { align-items: center; flex-wrap: nowrap; position: relative; }
:deep(.detailed-stock-table) { min-width: 1760px; width: max-content; }
:deep(.detailed-stock-table .stock-col-product-first) { min-width: 260px; }
:deep(.detailed-stock-table .crud-action-column) { background: #fff; box-shadow: -10px 0 18px rgba(15, 23, 42, .04); min-width: 150px; position: sticky; right: 0; z-index: 6; }
:deep(.detailed-stock-table th.crud-action-column) { background: #f8fafc; z-index: 7; }
:deep(.detailed-stock-table-wrap) { max-width: 100%; overscroll-behavior-x: contain; }
.stock-badge { border-radius: 7px; display: inline-flex; font-size: 10px; font-weight: 800; padding: 5px 8px; }
.stock-badge.in-stock { background: #eaf8f1; color: #168757; }
.stock-badge.low-stock { background: #fff4d4; color: #9b6a0c; }
.stock-badge.out-of-stock { background: #fff3f4; color: #d23f49; }
.stock-badge.over-stock { background: #f3edff; color: #6e45b8; }
.stock-badge.negative-stock { background: #fee2e2; color: #b91c1c; }
.stock-badge.reserved { background: #e0f2fe; color: #0369a1; }
.expand-button {
  align-items: center;
  background: #fff;
  border: 1px solid #d8e0eb;
  border-radius: 8px;
  color: #344159;
  cursor: pointer;
  display: inline-flex;
  font-size: 11px;
  font-weight: 850;
  height: 30px;
  justify-content: center;
  min-width: 30px;
  padding: 0 8px;
}
.expand-button:hover { background: #edf2ff; border-color: #cbd9ff; color: #2457d6; }
.expanded-list { display: grid; gap: 8px; margin-top: 10px; }
.expanded-card { background: #f8fafc; border: 1px solid #e1e7ef; border-radius: 9px; margin-left: 44px; padding: 10px 14px; }
.expanded-card h3 { color: #142139; font-size: 13px; margin: 0 0 8px; }
.pagination { align-items: center; color: #69758a; display: flex; font-size: 11px; gap: 10px; justify-content: flex-end; margin-top: 12px; }
.drawer-detail { display: grid; gap: 14px; padding-bottom: 22px; }
.detail-product-head { align-items: center; display: flex; gap: 12px; margin-bottom: 12px; }
.product-image.large { height: 52px; width: 52px; }
.drawer-detail h2 { margin: 0; color: #142139; }
.drawer-detail p { color: #758197; margin: 4px 0 0; }
.drawer-detail h3 { border-top: 1px solid #e4eaf3; color: #142139; font-size: 14px; margin: 4px 0 0; padding-top: 16px; }
.detail-cards { display: grid; gap: 10px; grid-template-columns: repeat(4, minmax(0, 1fr)); }
.detail-cards div, .detail-grid div { background: #f8fafc; border: 1px solid #e1e7ef; border-radius: 9px; padding: 10px; }
.detail-cards span, .detail-grid span { color: #7b879c; display: block; font-size: 11px; }
.detail-cards strong, .detail-grid strong { color: #142139; display: block; font-size: 13px; margin-top: 4px; }
.detail-grid { display: grid; gap: 10px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 10px; }
.mini-row { align-items: center; border-bottom: 1px solid #edf1f5; display: flex; gap: 16px; justify-content: space-between; padding: 9px 0; }
.mini-row span { color: #536179; }
.mini-row strong { color: #142139; }
.metric-chip-row { align-items: center; display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; max-width: 620px; }
.metric-chip { align-items: center; background: #f8fafc; border: 1px solid #e1e7ef; border-radius: 7px; display: inline-flex; gap: 6px; min-height: 30px; padding: 5px 8px; }
.metric-chip small { color: #7b879c; font-size: 10px; font-weight: 850; text-transform: uppercase; }
.metric-chip strong { color: #142139; font-size: 11px; margin: 0; white-space: nowrap; }
.metric-chip.condition-damaged { background: #fff8ed; border-color: #ffd9b3; }
.metric-chip.condition-expired { background: #fff5f5; border-color: #fecaca; }
.metric-chip.condition-defective { background: #fffbeb; border-color: #fde68a; }
.metric-chip.condition-quarantined { background: #eff6ff; border-color: #bfdbfe; }
.metric-chip.condition-lost { background: #f8fafc; border-color: #cbd5e1; }
.condition-grid { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
.condition-card { background: #f8fafc; border: 1px solid #e1e7ef; border-left: 4px solid #2457d6; border-radius: 8px; padding: 10px; }
.condition-card span { color: #536179; display: block; font-size: 11px; font-weight: 850; text-transform: uppercase; }
.condition-card strong { color: #142139; display: block; font-size: 14px; margin-top: 4px; }
.condition-damaged { border-color: #ffd9b3; border-left-color: #d97706; background: #fff8ed; }
.condition-expired { border-color: #fecaca; border-left-color: #dc2626; background: #fff5f5; }
.condition-defective { border-color: #fde68a; border-left-color: #ca8a04; background: #fffbeb; }
.condition-quarantined { border-color: #bfdbfe; border-left-color: #2563eb; background: #eff6ff; }
.condition-lost { border-color: #cbd5e1; border-left-color: #475569; background: #f8fafc; }
.detail-empty { color: #8490a2; padding: 12px 0; text-align: center; }
.ledger-table-wrap { border: 1px solid #e1e7ef; border-radius: 8px; overflow-x: auto; }
.ledger-table { border-collapse: collapse; min-width: 620px; width: 100%; }
.ledger-table th {
  background: #f8fafc;
  border-bottom: 1px solid #e1e7ef;
  color: #7b879c;
  font-size: 10px;
  font-weight: 850;
  padding: 10px 12px;
  text-align: left;
  text-transform: uppercase;
}
.ledger-table td {
  border-bottom: 1px solid #edf1f5;
  color: #2d3a52;
  font-size: 12px;
  font-weight: 700;
  padding: 11px 12px;
  vertical-align: top;
}
.ledger-table tr:last-child td { border-bottom: 0; }
.ledger-table .numeric { text-align: right; white-space: nowrap; }
@media (max-width: 1100px) { .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .listing-toolbar { flex-direction: column; } }
@media (max-width: 720px) { .summary-grid, .detail-cards, .detail-grid { grid-template-columns: 1fr; } .filter-group > * { width: 100%; } .mini-row, .stock-breakdown-row { align-items: flex-start; flex-direction: column; } .metric-chip-row { justify-content: flex-start; } }
</style>
