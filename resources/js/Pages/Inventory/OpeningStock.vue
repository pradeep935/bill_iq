<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import Layout from '../Layout.vue';
import InventoryApi from './InventoryApi';
import AppToast from '../../Components/Common/AppToast.vue';
import CrudTable from '../../Components/Common/CrudTable.vue';
import DrawerField from '../../Components/Common/DrawerField.vue';

defineProps({
    page: { type: String, default: 'opening-stock' },
    title: { type: String, default: 'Opening Stock' },
    role_id: { type: Number, default: null },
});

const today = new Date().toISOString().slice(0, 10);
const loading = ref(false);
const saving = ref(false);
const vouchers = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const references = ref({ branches: [], warehouses: [] });
const productSearch = ref('');
const productResults = ref([]);
const errors = ref({});
const highlightedRowUid = ref('');
const fillingForm = ref(false);
const viewingVoucher = ref(null);
const toast = ref({ show: false, title: '', message: '', type: 'info' });
let toastTimer = null;
const filters = reactive({
    search: '',
    branch_id: '',
    warehouse_id: '',
    status: '',
    date_from: '',
    date_to: '',
});

const form = reactive({
    id: null,
    voucher_number: '',
    branch_id: '',
    warehouse_id: '',
    opening_date: today,
    remarks: '',
    status: 'draft',
    items: [],
});

const quantityRefs = ref([]);

const itemColumns = [
    { key: 'product', label: 'Product' },
    { key: 'variant', label: 'Variant' },
    { key: 'batch', label: 'Batch' },
    { key: 'mfg', label: 'Mfg' },
    { key: 'expiry', label: 'Expiry' },
    { key: 'quantity', label: 'Qty / Unit' },
    { key: 'purchase_cost', label: 'Cost' },
    { key: 'selling_price', label: 'Sell' },
    { key: 'mrp', label: 'MRP' },
    { key: 'location', label: 'Location' },
    { key: 'line_total', label: 'Total' },
];

const voucherColumns = [
    { key: 'voucher_number', label: 'Voucher' },
    { key: 'opening_date', label: 'Date' },
    { key: 'branch', label: 'Branch' },
    { key: 'warehouse', label: 'Warehouse' },
    { key: 'items_count', label: 'Items' },
    { key: 'total_quantity', label: 'Quantity' },
    { key: 'total_value', label: 'Total Value' },
];

const makeRowUid = () => `${Date.now()}-${Math.random().toString(36).slice(2)}`;

const filteredWarehouses = computed(() => {
    if (!form.branch_id) {
        return references.value.warehouses || [];
    }

    return (references.value.warehouses || []).filter((warehouse) =>
        Number(warehouse.branch_id || 0) === Number(form.branch_id)
    );
});

const filterWarehouses = computed(() => {
    if (!filters.branch_id) {
        return references.value.warehouses || [];
    }

    return (references.value.warehouses || []).filter((warehouse) =>
        Number(warehouse.branch_id || 0) === Number(filters.branch_id)
    );
});

const resetForm = () => {
    form.id = null;
    form.voucher_number = '';
    form.branch_id = '';
    form.warehouse_id = '';
    form.opening_date = today;
    form.remarks = '';
    form.status = 'draft';
    form.items = [];
    errors.value = {};
    productSearch.value = '';
    productResults.value = [];
};

const voucherStatusLabel = computed(() => {
    const map = {
        draft: 'Draft',
        posted: 'Posted',
        cancelled: 'Cancelled',
    };

    return map[form.status] || 'Draft';
});

const totalItems = computed(() => form.items.length);
const totalQuantity = computed(() => form.items.reduce((sum, item) => sum + Number(item.quantity || 0), 0));
const totalValue = computed(() => form.items.reduce((sum, item) => sum + (Number(item.quantity || 0) * Number(item.purchase_cost || 0)), 0));
const isReadOnly = computed(() => form.id && form.status !== 'draft');

const itemValueFor = (item, column) => {
    if (column.key === 'line_total') {
        return `Rs. ${formatMoney(Number(item.quantity || 0) * Number(item.purchase_cost || 0))}`;
    }

    return item[column.key] || '-';
};

const voucherValueFor = (voucher, column) => {
    if (column.key === 'warehouse') {
        return voucher.warehouse || 'Default';
    }

    if (column.key === 'total_value') {
        return `Rs. ${formatMoney(voucher.total_value)}`;
    }

    return voucher[column.key] || '-';
};

const loadReferences = async () => {
    references.value = await InventoryApi.openingStockReferences();
};

const loadVouchers = async (page = 1) => {
    loading.value = true;

    try {
        const response = await InventoryApi.openingStockList({ page, ...filters });
        vouchers.value = response.vouchers || [];
        pagination.value = response.pagination || pagination.value;
    } finally {
        loading.value = false;
    }
};

const searchProducts = async () => {
    const q = productSearch.value.trim();

    if (q.length < 2) {
        productResults.value = [];
        return;
    }

    const results = await InventoryApi.searchOpeningStockProducts(q, {
        branch_id: form.branch_id || '',
        warehouse_id: form.warehouse_id || '',
    });
    const exactBarcode = results.find((product) =>
        String(product.barcode || '').trim().toLowerCase() === q.toLowerCase()
    );

    if (exactBarcode) {
        addProduct(exactBarcode);
        return;
    }

    productResults.value = results;
};

const rowKey = (item) => [
    form.branch_id || '',
    form.warehouse_id || '',
    item.product_id || '',
    item.product_variant_id || '',
    item.batch_id || '',
    String(item.batch_no || '').trim().toUpperCase(),
    String(item.warehouse_location || '').trim().toUpperCase(),
    item.serial_number_id || item.serial_id || '',
].join('|');

const focusQuantity = (index) => {
    nextTick(() => {
        quantityRefs.value[index]?.focus?.();
        quantityRefs.value[index]?.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
    });
};

const highlightExistingRow = (index) => {
    const existing = form.items[index];
    highlightedRowUid.value = existing?.row_uid || '';
    focusQuantity(index);

    window.setTimeout(() => {
        if (highlightedRowUid.value === existing?.row_uid) {
            highlightedRowUid.value = '';
        }
    }, 2600);
};

const addProduct = (product) => {
    const newItem = {
        product_id: product.id,
        product_name: product.name,
        sku: product.sku,
        unit: product.unit || 'PCS',
        tracking_type: product.tracking_type || 'none',
        batch_required: Boolean(product.batch_required),
        expiry_required: Boolean(product.expiry_required),
        serial_required: Boolean(product.serial_required),
        variants: product.variants || [],
        product_variant_id: '',
        batch_id: '',
        batch_no: '',
        manufacturing_date: '',
        expiry_date: '',
        quantity: '1',
        purchase_cost: product.cost_price || 0,
        selling_price: product.selling_price || 0,
        mrp: product.mrp || '',
        current_stock: product.current_stock || 0,
        warehouse_location: '',
        serial_number_id: product.serial_number_id || '',
        remarks: '',
        row_uid: makeRowUid(),
    };

    const existingIndex = form.items.findIndex((item) => rowKey(item) === rowKey(newItem));

    if (existingIndex >= 0) {
        highlightExistingRow(existingIndex);
        showMessage('Duplicate Product', 'This product already exists in the voucher. Please update the existing quantity.', 'warning');
    } else {
        form.items.push(newItem);
        focusQuantity(form.items.length - 1);
    }

    productSearch.value = '';
    productResults.value = [];
};

const removeItem = (index) => {
    if (!window.confirm('Remove this item from the voucher?')) {
        return;
    }

    form.items.splice(index, 1);
};

const editVoucher = (voucher) => {
    fillingForm.value = true;
    form.id = voucher.id;
    form.voucher_number = voucher.voucher_number || '';
    form.branch_id = voucher.branch_id || '';
    form.warehouse_id = voucher.warehouse_id || '';
    form.opening_date = voucher.opening_date || today;
    form.remarks = voucher.remarks || '';
    form.status = voucher.status === 'draft' ? 'draft' : voucher.status;
    form.items = (voucher.items || []).map((item) => ({
        ...item,
        product_name: item.product,
        variants: [],
        unit: item.unit || 'PCS',
        current_stock: item.current_stock || 0,
        serial_number_id: item.serial_number_id || '',
        row_uid: makeRowUid(),
    }));
    errors.value = {};

    nextTick(() => {
        fillingForm.value = false;
    });
};

const payload = (status) => ({
    branch_id: form.branch_id || null,
    warehouse_id: form.warehouse_id || null,
    opening_date: form.opening_date,
    remarks: form.remarks,
    status,
    items: form.items.map((item) => ({
        product_id: item.product_id,
        product_variant_id: item.product_variant_id || null,
        batch_id: item.batch_id || null,
        batch_no: item.batch_no || null,
        manufacturing_date: item.manufacturing_date || null,
        expiry_date: item.expiry_date || null,
        quantity: item.quantity,
        unit: item.unit || 'PCS',
        purchase_cost: item.purchase_cost || 0,
        warehouse_location: item.warehouse_location || null,
        serial_number_id: item.serial_number_id || null,
        remarks: item.remarks || null,
    })),
});

const fieldError = (field) => errors.value?.[field]?.[0] || '';

const rowError = (index, field) => fieldError(`items.${index}.${field}`);

const showMessage = (title, message, type = 'info') => {
    if (toastTimer) {
        clearTimeout(toastTimer);
    }

    toast.value = { show: true, title, message, type };
    toastTimer = setTimeout(() => {
        toast.value.show = false;
        toastTimer = null;
    }, type === 'error' ? 5200 : 3200);
};

const validateClient = (posting = false) => {
    const nextErrors = {};

    if (!form.branch_id) nextErrors.branch_id = ['Please select a branch.'];
    if (filteredWarehouses.value.length && !form.warehouse_id) nextErrors.warehouse_id = ['Please select a warehouse.'];
    if (!form.opening_date) nextErrors.opening_date = ['Opening date is required.'];
    if (!form.items.length) nextErrors.items = ['At least one product row is required.'];

    const seen = {};

    form.items.forEach((item, index) => {
        if (item.quantity === '' || item.quantity === null || item.quantity === undefined) nextErrors[`items.${index}.quantity`] = ['Quantity is required.'];
        if (Number(item.quantity || 0) <= 0) nextErrors[`items.${index}.quantity`] = ['Quantity must be greater than zero.'];
        if (Number(item.purchase_cost || 0) < 0) nextErrors[`items.${index}.purchase_cost`] = ['Cost price cannot be negative.'];
        if (posting && Number(item.purchase_cost || 0) <= 0) nextErrors[`items.${index}.purchase_cost`] = ['Cost price must be greater than zero.'];
        if (['PCS', 'BOX', 'UNIT', 'NOS', 'SET', 'PAIR'].includes(String(item.unit || '').toUpperCase()) && !Number.isInteger(Number(item.quantity || 0))) {
            nextErrors[`items.${index}.quantity`] = ['Decimal quantity is not allowed for this unit.'];
        }
        if (posting && item.batch_required && !item.batch_no && !item.batch_id) nextErrors[`items.${index}.batch_no`] = ['Batch number is required before posting.'];
        if (posting && item.expiry_required && !item.expiry_date) nextErrors[`items.${index}.expiry_date`] = ['Expiry date is required before posting.'];
        if (item.manufacturing_date && item.expiry_date && item.expiry_date <= item.manufacturing_date) {
            nextErrors[`items.${index}.expiry_date`] = ['Expiry date must be greater than manufacturing date.'];
        }

        const key = rowKey(item);
        if (seen[key]) nextErrors[`items.${index}.product_id`] = ['This product already exists in the voucher. Please update the existing quantity.'];
        seen[key] = true;
    });

    errors.value = nextErrors;

    return !Object.keys(nextErrors).length;
};

const saveVoucher = async (status = 'draft') => {
    if (saving.value) {
        return;
    }

    saving.value = true;
    errors.value = {};

    try {
        if (!validateClient(status === 'posted')) {
            showMessage('Please Review the Form', Object.values(errors.value)?.[0]?.[0] || 'Please check the highlighted fields.', 'error');
            return;
        }

        const response = await InventoryApi.saveOpeningStock(payload(status), form.id);
        showMessage(status === 'posted' ? 'Opening Stock Posted' : 'Draft Saved', response.message || 'Opening stock voucher saved successfully.', 'success');
        if (status === 'posted' && response.voucher) {
            editVoucher(response.voucher);
        } else {
            resetForm();
        }
        await loadVouchers();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors || {};
            showMessage('Please Review the Form', Object.values(errors.value)?.[0]?.[0] || 'Please check the highlighted fields.', 'error');
            return;
        }

        showMessage('Unable to Save Voucher', error.response?.data?.message || 'Opening stock voucher could not be saved.', 'error');
    } finally {
        saving.value = false;
    }
};

const approveVoucher = async (voucher) => {
    if (!window.confirm('Are you sure you want to post this opening stock voucher? Stock will be updated and the voucher cannot be edited after posting.')) {
        return;
    }

    try {
        const response = await InventoryApi.approveOpeningStock(voucher.id);
        showMessage('Opening Stock Posted', response.message || 'Opening stock posted.', 'success');
        if (response.voucher && Number(form.id || 0) === Number(voucher.id)) {
            editVoucher(response.voucher);
        }
        await loadVouchers(pagination.value.current_page || 1);
    } catch (error) {
        showMessage('Unable to Post Voucher', error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || 'Opening stock could not be posted.', 'error');
    }
};

const deleteVoucher = async (voucher) => {
    if (!window.confirm(`Delete draft voucher ${voucher.voucher_number}?`)) {
        return;
    }

    try {
        const response = await InventoryApi.deleteOpeningStock(voucher.id);
        showMessage('Draft Deleted', response.message || 'Opening stock draft deleted.', 'success');
        if (form.id === voucher.id) resetForm();
        await loadVouchers(pagination.value.current_page || 1);
    } catch (error) {
        showMessage('Unable to Delete Draft', error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || 'Opening stock draft could not be deleted.', 'error');
    }
};

const reverseVoucher = async (voucher) => {
    const remarks = window.prompt('Cancellation reason');

    if (!remarks) {
        return;
    }

    try {
        const response = await InventoryApi.reverseOpeningStock(voucher.id, remarks);
        showMessage('Opening Stock Cancelled', response.message || 'Opening stock cancelled.', 'success');
        await loadVouchers(pagination.value.current_page || 1);
    } catch (error) {
        showMessage('Unable to Cancel Voucher', error.response?.data?.message || Object.values(error.response?.data?.errors || {})?.[0]?.[0] || 'Opening stock could not be cancelled.', 'error');
    }
};

const formatMoney = (value) => Number(value || 0).toLocaleString('en-IN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const printVoucher = (voucher) => {
    viewingVoucher.value = voucher;
    nextTick(() => window.print());
};

const printableVoucher = computed(() => viewingVoucher.value || (form.id ? { ...form, branch: references.value.branches.find((b) => Number(b.id) === Number(form.branch_id))?.name, warehouse: references.value.warehouses.find((w) => Number(w.id) === Number(form.warehouse_id))?.name, total_quantity: totalQuantity.value, total_value: totalValue.value, items: form.items.map((item) => ({ ...item, product: item.product_name, purchase_cost: item.purchase_cost, stock_value: Number(item.quantity || 0) * Number(item.purchase_cost || 0) })) } : null));

watch(
    () => form.branch_id,
    () => {
        if (fillingForm.value) {
            return;
        }

        form.warehouse_id = '';
        form.items = [];
        productSearch.value = '';
        productResults.value = [];

        nextTick(() => {
            if (filteredWarehouses.value.length === 1) {
                form.warehouse_id = filteredWarehouses.value[0].id;
            }
        });
    }
);

watch(
    () => form.warehouse_id,
    () => {
        form.items.forEach((item) => {
            item.warehouse_location = '';
        });
    }
);

watch(
    () => filters.branch_id,
    () => {
        filters.warehouse_id = '';
    }
);

onMounted(async () => {
    await loadReferences();
    await loadVouchers();
});
</script>

<template>
    <Layout :page="page" :title="title">
        <template #topbar-title>
            <div class="bill-page-title"><span>INVENTORY FOUNDATION</span><h1>Opening Stock</h1><p>Create draft vouchers and post opening quantities to the stock ledger.</p></div>
        </template>
        <div class="inventory-page">
            <div class="page-actions">
                <button type="button" @click="resetForm">New Voucher</button>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <span>Total Items</span>
                    <strong>{{ totalItems }}</strong>
                </div>
                <div class="summary-card">
                    <span>Total Quantity</span>
                    <strong>{{ totalQuantity.toFixed(3) }}</strong>
                </div>
                <div class="summary-card">
                    <span>Total Stock Value</span>
                    <strong>Rs. {{ formatMoney(totalValue) }}</strong>
                </div>
            </div>

            <section class="listing-card">
                <div class="listing-title">
                    <div>
                        <h2>Opening Stock Entry</h2>
                        <p>Create draft vouchers or post opening balances to stock ledger.</p>
                    </div>
                    <span class="voucher-status">{{ voucherStatusLabel }}</span>
                </div>

                <div class="opening-form-grid">
                    <div class="field-stack">
                        <DrawerField label="Voucher Number" :model-value="form.voucher_number || 'Auto generated on save'" disabled hint="Generated after saving the draft or posting the voucher." />
                    </div>

                    <div class="field-stack">
                        <DrawerField label="Voucher Status" :model-value="voucherStatusLabel" disabled hint="Draft can be edited. Posted vouchers update stock and become read only." />
                    </div>

                    <div class="field-stack">
                        <DrawerField v-model="form.branch_id" label="Branch" as="select" required :disabled="isReadOnly" hint="Select the branch whose opening balance is being entered.">
                            <option value="">Select Branch</option>
                            <option v-for="branch in references.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </DrawerField>
                        <span v-if="fieldError('branch_id')" class="field-error">{{ fieldError('branch_id') }}</span>
                    </div>

                    <div class="field-stack">
                        <DrawerField v-model="form.warehouse_id" label="Warehouse" as="select" :required="filteredWarehouses.length > 0" :disabled="isReadOnly" hint="Used for warehouse-wise opening stock. Default is used when no warehouse master exists.">
                            <option value="">{{ filteredWarehouses.length ? 'Select Warehouse' : 'Default warehouse' }}</option>
                            <option v-for="warehouse in filteredWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                        </DrawerField>
                        <span v-if="fieldError('warehouse_id')" class="field-error">{{ fieldError('warehouse_id') }}</span>
                    </div>

                    <div class="field-stack">
                        <DrawerField v-model="form.opening_date" label="Opening Date" type="date" required :disabled="isReadOnly" hint="The stock ledger date for this opening quantity." />
                        <span v-if="fieldError('opening_date')" class="field-error">{{ fieldError('opening_date') }}</span>
                    </div>

                    <div class="field-stack field-span-2">
                        <DrawerField v-model="form.remarks" label="Remarks" placeholder="Voucher remarks" :disabled="isReadOnly" hint="Optional internal note for audit or import reference." />
                    </div>
                </div>

                <div v-if="!isReadOnly" class="listing-toolbar compact-toolbar">
                    <div class="search-box wide-search">
                        <span></span>
                        <input
                            v-model="productSearch"
                            type="text"
                            placeholder="Search product by name, short name, SKU, barcode or HSN/SAC"
                            @input="searchProducts"
                        />
                    </div>
                    <div v-if="productResults.length" class="search-results">
                        <button
                            v-for="product in productResults"
                            :key="product.id"
                            type="button"
                            @click="addProduct(product)"
                        >
                            <strong>{{ product.name }}</strong>
                            <span>{{ product.sku }} | {{ product.barcode || 'No barcode' }} | {{ product.unit || 'PCS' }}</span>
                        </button>
                    </div>
                </div>

                <CrudTable
                    :columns="itemColumns"
                    :rows="form.items"
                    row-key="row_uid"
                    :value-for="itemValueFor"
                    :row-class="(item) => item.row_uid === highlightedRowUid ? 'duplicate-highlight-row' : ''"
                    :show-status="false"
                    :show-actions="!isReadOnly"
                    empty-text="Search and add stock items."
                >
                    <template #cell-product="{ row: item }">
                        <div class="product-information">
                            <strong>{{ item.product_name }}</strong>
                            <span>{{ item.sku || '-' }}</span>
                            <span>Current: {{ Number(item.current_stock || 0).toFixed(3) }} {{ item.unit || 'PCS' }}</span>
                        </div>
                    </template>

                    <template #cell-variant="{ row: item }">
                        <select v-model="item.product_variant_id" class="line-control" :disabled="isReadOnly">
                            <option value="">Default</option>
                            <option v-for="variant in item.variants" :key="variant.id" :value="variant.id">{{ variant.sku }}</option>
                        </select>
                    </template>

                    <template #cell-batch="{ row: item }">
                        <input v-model="item.batch_no" class="line-control" type="text" :disabled="isReadOnly || !item.batch_required" />
                    </template>

                    <template #cell-mfg="{ row: item }">
                        <input v-model="item.manufacturing_date" class="line-control date-control" type="date" :disabled="isReadOnly || !item.expiry_required" />
                    </template>

                    <template #cell-expiry="{ row: item }">
                        <input v-model="item.expiry_date" class="line-control date-control" type="date" :disabled="isReadOnly || !item.expiry_required" />
                    </template>

                    <template #cell-quantity="{ row: item }">
                        <div class="quantity-cell">
                            <input :ref="(el) => { const index = form.items.indexOf(item); if (el && index >= 0) quantityRefs[index] = el; }" v-model="item.quantity" class="line-control qty-control" type="number" min="0.001" step="0.001" :disabled="isReadOnly" />
                            <span>{{ item.unit || 'PCS' }}</span>
                        </div>
                    </template>

                    <template #cell-purchase_cost="{ row: item }">
                        <input v-model="item.purchase_cost" class="line-control money-control" type="number" min="0" step="0.01" :disabled="isReadOnly" />
                    </template>

                    <template #cell-selling_price="{ row: item }">
                        <input v-model="item.selling_price" class="line-control money-control" type="number" min="0" step="0.01" disabled />
                    </template>

                    <template #cell-mrp="{ row: item }">
                        <input v-model="item.mrp" class="line-control money-control" type="number" min="0" step="0.01" disabled />
                    </template>

                    <template #cell-location="{ row: item }">
                        <input v-model="item.warehouse_location" class="line-control" type="text" :disabled="isReadOnly" />
                    </template>

                    <template #actions="{ row: item }">
                        <button v-if="!isReadOnly" type="button" class="crud-action danger" @click="removeItem(form.items.indexOf(item))">Remove</button>
                    </template>
                </CrudTable>

                <div v-if="Object.keys(errors).length" class="error-box">
                    <span v-for="(messages, field) in errors" :key="field">{{ messages[0] }}</span>
                </div>

                <div class="form-hints">
                    <strong>Where this is used</strong>
                    <span>Branch and warehouse decide where the stock opens. Product rows post the first quantity, cost value, batch, expiry and location for inventory reports.</span>
                </div>

                <div class="actions">
                    <button v-if="!isReadOnly" type="button" :disabled="saving" @click="saveVoucher('draft')">Save Draft</button>
                    <button v-if="!isReadOnly" type="button" class="primary" :disabled="saving" @click="saveVoucher('posted')">
                        {{ saving ? 'Saving...' : 'Confirm & Post' }}
                    </button>
                </div>
            </section>

            <section class="listing-card">
                <div class="listing-title">
                    <div>
                        <h2>Opening Stock Vouchers</h2>
                        <p>Review drafts, posted vouchers and cancelled entries.</p>
                    </div>
                    <span>{{ pagination.total || 0 }} vouchers</span>
                </div>

                <div class="listing-toolbar voucher-filters">
                    <div class="search-box">
                        <span></span>
                        <input v-model="filters.search" type="text" placeholder="Voucher number" @input="loadVouchers(1)" />
                    </div>
                    <div class="filter-group">
                        <select v-model="filters.branch_id" @change="loadVouchers(1)">
                            <option value="">All Branches</option>
                            <option v-for="branch in references.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                        <select v-model="filters.warehouse_id" @change="loadVouchers(1)">
                            <option value="">All Warehouses</option>
                            <option v-for="warehouse in filterWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                        </select>
                        <select v-model="filters.status" @change="loadVouchers(1)">
                            <option value="">All Status</option>
                            <option value="draft">Draft</option>
                            <option value="posted">Posted</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                        <input v-model="filters.date_from" type="date" @change="loadVouchers(1)" />
                        <input v-model="filters.date_to" type="date" @change="loadVouchers(1)" />
                    </div>
                </div>

                <div class="listing-information">
                    <div>
                        <strong>Voucher Register</strong>
                        <span>
                            Showing {{ pagination.from || 0 }} to {{ pagination.to || 0 }}
                            of {{ pagination.total || 0 }} vouchers
                        </span>
                    </div>
                </div>

                <CrudTable
                    :columns="voucherColumns"
                    :rows="vouchers"
                    :loading="loading"
                    :value-for="voucherValueFor"
                    empty-text="No opening stock vouchers found."
                >
                    <template #actions="{ row: voucher }">
                        <button type="button" class="crud-action" @click="editVoucher(voucher)">View</button>
                        <button v-if="voucher.status === 'draft'" type="button" class="crud-action" @click="editVoucher(voucher)">Edit</button>
                        <button v-if="voucher.status !== 'draft'" type="button" class="crud-action" @click="printVoucher(voucher)">Print</button>
                        <button v-if="voucher.status === 'draft'" type="button" class="crud-action" @click="approveVoucher(voucher)">Post</button>
                        <button v-if="voucher.status === 'draft'" type="button" class="crud-action danger" @click="deleteVoucher(voucher)">Delete</button>
                        <button v-if="voucher.status === 'posted'" type="button" class="crud-action danger" @click="reverseVoucher(voucher)">Cancel</button>
                    </template>
                </CrudTable>

                <div class="pagination">
                    <button type="button" :disabled="pagination.current_page <= 1" @click="loadVouchers(pagination.current_page - 1)">Previous</button>
                    <span>{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total || 0 }}</span>
                    <button type="button" :disabled="pagination.current_page >= pagination.last_page" @click="loadVouchers(pagination.current_page + 1)">Next</button>
                </div>
            </section>
        </div>

        <AppToast
            :show="toast.show"
            :title="toast.title"
            :message="toast.message"
            :type="toast.type"
        />

        <section v-if="printableVoucher" class="print-voucher">
            <header>
                <div>
                    <h1>Opening Stock Voucher</h1>
                    <p>Bill IQ</p>
                </div>
                <strong>{{ printableVoucher.status }}</strong>
            </header>
            <div class="print-meta">
                <span>Voucher: <strong>{{ printableVoucher.voucher_number || 'Draft' }}</strong></span>
                <span>Date: <strong>{{ printableVoucher.opening_date }}</strong></span>
                <span>Branch: <strong>{{ printableVoucher.branch || '-' }}</strong></span>
                <span>Warehouse: <strong>{{ printableVoucher.warehouse || '-' }}</strong></span>
                <span>Created By: <strong>{{ printableVoucher.created_by || '-' }}</strong></span>
                <span>Posted By: <strong>{{ printableVoucher.posted_by || '-' }}</strong></span>
                <span>Posting Date: <strong>{{ printableVoucher.posted_at || '-' }}</strong></span>
                <span>Remarks: <strong>{{ printableVoucher.remarks || '-' }}</strong></span>
            </div>
            <table>
                <thead><tr><th>Product</th><th>Qty</th><th>Unit</th><th>Batch</th><th>Expiry</th><th>Cost</th><th>Total</th></tr></thead>
                <tbody>
                    <tr v-for="(item, index) in printableVoucher.items || []" :key="index">
                        <td>{{ item.product || item.product_name }}</td>
                        <td>{{ item.quantity }}</td>
                        <td>{{ item.unit || 'PCS' }}</td>
                        <td>{{ item.batch_no || '-' }}</td>
                        <td>{{ item.expiry_date || '-' }}</td>
                        <td>Rs. {{ formatMoney(item.purchase_cost) }}</td>
                        <td>Rs. {{ formatMoney(item.stock_value || Number(item.quantity || 0) * Number(item.purchase_cost || 0)) }}</td>
                    </tr>
                </tbody>
            </table>
            <footer>
                <span>Total Quantity: <strong>{{ Number(printableVoucher.total_quantity || 0).toFixed(3) }}</strong></span>
                <span>Total Stock Value: <strong>Rs. {{ formatMoney(printableVoucher.total_value) }}</strong></span>
            </footer>
        </section>
    </Layout>
</template>

<style scoped>
.inventory-page { padding: 4px 0 28px; }
.page-actions { display: flex; gap: 10px; justify-content: flex-end; margin-bottom: 18px; }
.page-actions button,
.actions button,
.pagination button {
  background: #fff;
  border: 1px solid #d8e0eb;
  border-radius: 8px;
  color: #35435b;
  cursor: pointer;
  font-size: 11px;
  font-weight: 750;
  min-height: 38px;
  padding: 8px 14px;
}
.actions button.primary { background: #2457d6; border-color: #2457d6; color: #fff; }
button:disabled { cursor: not-allowed; opacity: .55; }
.summary-grid { display: grid; gap: 16px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: 18px; }
.summary-card { background: #fff; border: 1px solid #dfe6ef; border-radius: 12px; box-shadow: 0 8px 24px rgba(25, 50, 84, .035); padding: 16px 18px; }
.summary-card span { color: #8190a8; display: block; font-size: 11px; font-weight: 750; margin-bottom: 5px; }
.summary-card strong { color: #142139; display: block; font-size: 20px; font-weight: 850; }
.listing-card { background: #fff; border: 1px solid #dfe6ef; border-radius: 12px; box-shadow: 0 10px 28px rgba(25, 50, 84, .04); margin-bottom: 20px; overflow: visible; padding: 22px 24px; }
.listing-title { align-items: flex-start; border-bottom: 1px solid #edf1f5; display: flex; justify-content: space-between; gap: 14px; margin-bottom: 18px; padding-bottom: 16px; }
.listing-title h2 { color: #142139; font-size: 18px; font-weight: 850; margin: 0; }
.listing-title p { color: #758197; font-size: 13px; font-weight: 650; margin: 4px 0 0; }
.listing-title > span,
.voucher-status { color: #142139; font-size: 14px; font-weight: 850; white-space: nowrap; }
.opening-form-grid { display: grid; gap: 14px 18px; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom: 18px; }
.field-stack { min-width: 0; }
.field-span-2 { grid-column: span 2; }
.listing-toolbar { align-items: center; display: flex; gap: 12px; justify-content: space-between; margin-bottom: 16px; position: relative; }
.compact-toolbar { justify-content: stretch; }
.search-box { align-items: center; background: #fff; border: 1px solid #d8e0eb; border-radius: 9px; display: flex; gap: 10px; min-height: 42px; padding: 0 12px; width: min(420px, 100%); }
.wide-search { width: 100%; }
.search-box span { border: 2px solid #8b98ac; border-radius: 50%; height: 13px; position: relative; width: 13px; }
.search-box span::after { background: #8b98ac; border-radius: 2px; content: ''; height: 6px; position: absolute; right: -5px; top: 9px; transform: rotate(45deg); width: 2px; }
.search-box input,
.filter-group select,
.filter-group input,
.line-control {
  background: #fff;
  border: 1px solid #d8e0eb;
  border-radius: 8px;
  color: #22304a;
  font-family: inherit;
  font-size: 12px;
  font-weight: 650;
  min-height: 38px;
  outline: none;
  padding: 8px 10px;
}
.search-box input { border: 0; flex: 1; min-width: 0; padding: 0; }
.filter-group { display: flex; flex-wrap: wrap; gap: 10px; justify-content: flex-end; }
.filter-group select,
.filter-group input { min-width: 150px; }
.line-control { min-width: 108px; width: 100%; }
.line-control:disabled { background: #f5f7fb; color: #69758a; cursor: not-allowed; }
.date-control { min-width: 132px; }
.qty-control { min-width: 86px; }
.money-control { min-width: 96px; }
.search-results { position: absolute; z-index: 20; right: 0; left: 0; top: 44px; display: grid; max-height: 220px; overflow: auto; background: #fff; border: 1px solid #dce4ef; border-radius: 9px; box-shadow: 0 12px 30px rgba(15, 34, 66, .12); }
.search-results button { background: #fff; border: 0; border-bottom: 1px solid #eef2f6; border-radius: 0; color: #27344c; cursor: pointer; display: grid; font-size: 12px; font-weight: 750; justify-items: start; min-height: 44px; padding: 9px 12px; }
.search-results span,
.product-information span { color: #7a869a; display: block; font-size: 10px; line-height: 1.5; }
.product-information strong { color: #27344c; display: block; font-size: 12px; font-weight: 800; min-width: 160px; }
.field-error { display: block; margin-top: 5px; color: #d23f49; font-size: 10px; font-weight: 800; white-space: normal; }
.quantity-cell { display: flex; align-items: center; gap: 6px; }
.quantity-cell span { min-width: 34px; color: #526078; font-weight: 800; }
.error-box { display: grid; gap: 4px; margin-top: 12px; padding: 10px; color: #96333a; background: #fff3f4; border: 1px solid #ffd4d8; border-radius: 8px; font-size: 11px; }
.form-hints { background: #f8fafc; border: 1px dashed #cfdae8; border-radius: 10px; color: #6f7e94; display: grid; font-size: 12px; font-weight: 650; gap: 4px; line-height: 1.45; margin-top: 14px; padding: 12px 14px; }
.form-hints strong { color: #344159; font-size: 12px; font-weight: 850; }
.actions { align-items: center; display: flex; gap: 10px; justify-content: flex-end; margin-top: 14px; }
.pagination { align-items: center; color: #69758a; display: flex; font-size: 11px; gap: 10px; justify-content: flex-end; margin-top: 12px; }
.listing-information { align-items: center; display: flex; justify-content: space-between; margin-bottom: 10px; }
.listing-information strong { color: #344159; display: block; font-size: 12px; font-weight: 850; }
.listing-information span { color: #7b879c; display: block; font-size: 11px; font-weight: 650; margin-top: 2px; }
@media (max-width: 1100px) { .opening-form-grid, .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 760px) { .listing-title, .listing-toolbar { align-items: stretch; flex-direction: column; } .opening-form-grid, .summary-grid { grid-template-columns: 1fr; } .field-span-2 { grid-column: span 1; } .filter-group { justify-content: stretch; } .filter-group select, .filter-group input { width: 100%; } }
.print-voucher { display: none; }
@media print {
    body * { visibility: hidden !important; }
    .print-voucher, .print-voucher * { visibility: visible !important; }
    .print-voucher { display: block; position: fixed; inset: 0; padding: 24px; background: #fff; color: #111827; }
    .print-voucher header, .print-voucher footer { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
    .print-voucher h1 { margin: 0; font-size: 22px; }
    .print-voucher p { margin: 4px 0 0; }
    .print-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 18px; margin-bottom: 18px; font-size: 12px; }
    .print-voucher table { width: 100%; border-collapse: collapse; }
    .print-voucher th, .print-voucher td { border: 1px solid #d1d5db; padding: 8px; font-size: 12px; }
}
</style>
