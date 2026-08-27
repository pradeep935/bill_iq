<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Layout from '../Layout.vue';

import ProductApi from './ProductApi';
import ProductForm from './ProductForm.vue';
import BarcodeModal from './BarcodeModal.vue';
import LabelModal from './LabelModal.vue';
import CrudTable from '../../Components/Common/CrudTable.vue';

const props = defineProps({
    page: {
        type: String,
        default: 'products',
    },

    title: {
        type: String,
        default: 'Products & Barcode',
    },

    role_id: {
        type: Number,
        default: null,
    },
});

const products = ref([]);
const pagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
    from: 0,
    to: 0,
});

const loading = ref(false);
const saving = ref(false);
const deletingId = ref(null);
const duplicatingId = ref(null);
const statusUpdatingId = ref(null);
const bulkProcessing = ref(false);
const exportProcessing = ref(false);
const serverErrors = ref({});
const references = ref({
    categories: [],
    sub_categories: [],
    brands: [],
    units: [],
    gst_rate_slabs: [],
});

const search = ref('');
const productTypeFilter = ref('');
const itemTypeFilter = ref('');
const statusFilter = ref('');
const categoryFilter = ref('');
const brandFilter = ref('');
const unitFilter = ref('');
const gstRateFilter = ref('');
const perPage = ref(15);

const showForm = ref(false);
const selectedProduct = ref({});
const viewProduct = ref(null);

const showBarcodeModal = ref(false);
const barcodeProduct = ref({});

const showLabelModal = ref(false);
const labelProducts = ref([]);
const quickCreateReturnTo = ref('');

const selectedIds = ref([]);
const openActionMenuId = ref(null);
const toast = ref({
    show: false,
    title: '',
    message: '',
    type: 'info',
});
const confirmModal = ref({
    show: false,
    title: '',
    message: '',
    confirmText: 'Confirm',
    danger: false,
    resolve: null,
});
let searchTimer = null;
let toastTimer = null;

const showMessage = (title, message, type = 'info') => {
    if (toastTimer) {
        clearTimeout(toastTimer);
    }

    toast.value = {
        show: true,
        title,
        message,
        type,
    };

    toastTimer = setTimeout(() => {
        toast.value.show = false;
        toastTimer = null;
    }, type === 'error' ? 5200 : 3200);
};

const firstResponseError = (error, fallback) => {
    const errors = error.response?.data?.errors || {};
    const first = Object.values(errors)?.[0];

    if (Array.isArray(first)) {
        return first[0] || fallback;
    }

    return first || error.response?.data?.message || fallback;
};

const requestConfirmation = ({ title, message, confirmText = 'Confirm', danger = false }) => {
    return new Promise((resolve) => {
        confirmModal.value = {
            show: true,
            title,
            message,
            confirmText,
            danger,
            resolve,
        };
    });
};

const resolveConfirmation = (confirmed) => {
    const resolver = confirmModal.value.resolve;
    confirmModal.value.show = false;
    confirmModal.value.resolve = null;

    if (resolver) {
        resolver(confirmed);
    }
};

const rows = computed(() => products.value);

const productColumns = [
    { key: 'image', label: 'Image', hint: 'Main product photo used for quick visual verification.' },
    { key: 'name', label: 'Product Name', hint: 'Primary product/service name used in billing, purchase and reports.' },
    { key: 'sku', label: 'SKU', hint: 'Unique item code for search, import and duplicate prevention.' },
    { key: 'barcode', label: 'Barcode', hint: 'Primary scanner code used in POS, stock entry and label printing.' },
    { key: 'category', label: 'Category', hint: 'Product group used for filtering, reporting and setup organization.' },
    { key: 'brand', label: 'Brand', hint: 'Manufacturer or brand name used for filtering and analytics.' },
    { key: 'unit', label: 'Unit', hint: 'Billing and stock measurement unit such as PCS, KG or HRS.' },
    { key: 'selling_price', label: 'Selling Price', hint: 'Default sales rate used on invoices and POS.' },
    { key: 'mrp', label: 'MRP', hint: 'Printed maximum retail price used for pricing validation.' },
    { key: 'gst_rate', label: 'GST', hint: 'Tax percentage applied during taxable billing.' },
];

const productValueFor = (product, column) => {
    const values = {
        barcode: primaryBarcode(product),
        selling_price: `Rs. ${formatPrice(product.selling_price)}`,
        mrp: product.mrp ? `Rs. ${formatPrice(product.mrp)}` : '-',
        gst_rate: `${Number(product.gst_rate || 0)}%`,
    };

    return values[column.key] ?? product[column.key] ?? '-';
};

const normalizeReferenceOptions = (options = [], labelKeys = ['label', 'name', 'code']) => {
    return (options || [])
        .map((option) => {
            const label = labelKeys
                .map((key) => option?.[key])
                .find((value) => String(value ?? '').trim());
            const value = option?.value ?? option?.id ?? label;

            return {
                ...option,
                value: value === undefined || value === null ? '' : String(value),
                label: String(label ?? value ?? '').trim(),
            };
        })
        .filter((option) => option.value !== '' && option.label !== '');
};

const uniqueOptions = (options = []) => {
    const seen = new Set();

    return options.filter((option) => {
        const key = String(option.label || option.value).toLowerCase();

        if (!key || seen.has(key)) {
            return false;
        }

        seen.add(key);
        return true;
    });
};

const optionsFromProducts = (field) => {
    return products.value
        .map((product) => product?.[field])
        .filter((value) => String(value ?? '').trim())
        .map((value) => ({
            value: String(value),
            label: String(value),
        }));
};

const categoryFilterOptions = computed(() => uniqueOptions([
    ...normalizeReferenceOptions(references.value.categories || [], ['label', 'name', 'code']),
    ...optionsFromProducts('category'),
]));

const brandFilterOptions = computed(() => uniqueOptions([
    ...normalizeReferenceOptions(references.value.brands || [], ['label', 'name', 'code']),
    ...optionsFromProducts('brand'),
]));

const unitFilterOptions = computed(() => uniqueOptions([
    ...normalizeReferenceOptions(references.value.units || [], ['label', 'name', 'code', 'symbol']),
    ...optionsFromProducts('unit'),
]));

const fallbackGstRateOptions = [
    { value: '0', label: '0%' },
    { value: '5', label: '5%' },
    { value: '12', label: '12%' },
    { value: '18', label: '18%' },
    { value: '28', label: '28%' },
];

const gstRateFilterOptions = computed(() => {
    const slabs = normalizeReferenceOptions(references.value.gst_rate_slabs || [], ['label', 'rate']);

    return slabs.length ? slabs : fallbackGstRateOptions;
});

const pageTotal = computed(() => pagination.value.total || products.value.length);

const activeProductsCount = computed(() => {
    return products.value.filter((product) => product.status === 'active').length;
});

const inactiveProductsCount = computed(() => {
    return products.value.filter((product) => product.status === 'inactive').length;
});

const allRowsSelected = computed(() => {
    return rows.value.length > 0 &&
        rows.value.every((product) => selectedIds.value.includes(product.id));
});

const visiblePages = computed(() => {
    const current = pagination.value.current_page || 1;
    const last = pagination.value.last_page || 1;
    const start = Math.max(1, current - 2);
    const end = Math.min(last, current + 2);
    const pages = [];

    for (let page = start; page <= end; page++) {
        pages.push(page);
    }

    return pages;
});

const hasFilters = computed(() => {
    return Boolean(
        search.value ||
        productTypeFilter.value ||
        itemTypeFilter.value ||
        statusFilter.value ||
        categoryFilter.value ||
        brandFilter.value ||
        unitFilter.value ||
        gstRateFilter.value
    );
});

const requestParams = (page = 1) => {
    return {
        page,
        per_page: perPage.value,
        search: search.value || undefined,
        product_type: productTypeFilter.value || undefined,
        item_type: itemTypeFilter.value || undefined,
        status: statusFilter.value || undefined,
        category: categoryFilter.value || undefined,
        brand: brandFilter.value || undefined,
        unit: unitFilter.value || undefined,
        gst_rate: gstRateFilter.value || undefined,
    };
};

const loadProducts = async (page = 1) => {
    loading.value = true;

    try {
        const response = await ProductApi.getProducts(requestParams(page));

        products.value = Array.isArray(response)
            ? response
            : response.products || [];

        pagination.value = response.pagination || {
            current_page: 1,
            last_page: 1,
            per_page: perPage.value,
            total: products.value.length,
            from: products.value.length ? 1 : 0,
            to: products.value.length,
        };

        selectedIds.value = selectedIds.value.filter((id) =>
            products.value.some((product) => product.id === id)
        );
    } catch (error) {
        console.error(error);

        showMessage('Unable to Load Products', 'Please refresh the page and try again.', 'error');
    } finally {
        loading.value = false;
    }
};

const loadReferences = async () => {
    try {
        references.value = await ProductApi.getReferences();
    } catch (error) {
        console.error(error);
        references.value = {
            categories: [],
            sub_categories: [],
            brands: [],
            units: [],
            gst_rate_slabs: [],
        };
    }
};

const addProduct = () => {
    serverErrors.value = {};
    selectedProduct.value = {};
    showForm.value = true;
};

const productPrefillFromQuery = () => {
    const params = new URLSearchParams(window.location.search);
    const prefillName = (params.get('prefill_name') || '').trim();

    quickCreateReturnTo.value = params.get('return_to') || '';

    if (!prefillName) {
        return;
    }

    search.value = prefillName;
    serverErrors.value = {};
    selectedProduct.value = {
        name: prefillName,
        product_type: 'goods',
        item_type: 'stock',
        status: 'active',
    };
    showForm.value = true;
};

const editProduct = async (product) => {
    openActionMenuId.value = null;
    serverErrors.value = {};

    try {
        const response = await ProductApi.getProduct(product.id);
        selectedProduct.value = response.product || product;
    } catch (error) {
        selectedProduct.value = { ...product };
    }

    showForm.value = true;
};

const openView = async (product) => {
    openActionMenuId.value = null;
    viewProduct.value = product;

    try {
        const response = await ProductApi.getProduct(product.id);
        viewProduct.value = response.product || product;
    } catch (error) {
        console.error(error);
    }
};

const saveProduct = async (form) => {
    saving.value = true;
    serverErrors.value = {};

    try {
        const response = await ProductApi.saveProduct(form);

        showForm.value = false;
        serverErrors.value = {};

        if (quickCreateReturnTo.value && response.product?.id) {
            const url = new URL(quickCreateReturnTo.value, window.location.origin);
            url.searchParams.set('added_product_id', response.product.id);
            url.searchParams.set('added_product_name', response.product.name || form.name || '');
            window.location.href = `${url.pathname}${url.search}`;
            return;
        }

        await loadProducts(pagination.value.current_page || 1);

        showMessage('Product Saved', response.message || 'The product was saved successfully.', 'success');
    } catch (error) {
        if (error.response?.status === 422) {
            const errors = error.response.data.errors || {};
            serverErrors.value = errors;

            showMessage(
                'Please Review the Form',
                firstResponseError(error, 'Please check the highlighted fields.'),
                'error'
            );

            return;
        }

        console.error(error);
        showMessage('Unable to Save Product', firstResponseError(error, 'Something went wrong while saving the product.'), 'error');
    } finally {
        saving.value = false;
    }
};

const duplicateProduct = async (product) => {
    const confirmed = await requestConfirmation({
        title: 'Duplicate Product',
        message: `Create a copy of "${product.name}"?`,
        confirmText: 'Duplicate',
    });

    if (!confirmed) {
        return;
    }

    openActionMenuId.value = null;
    duplicatingId.value = product.id;

    try {
        const response = await ProductApi.duplicateProduct(product.id);
        await loadProducts(1);
        showMessage('Product Duplicated', response.message || 'A product copy was created successfully.', 'success');
    } catch (error) {
        console.error(error);
        showMessage('Unable to Duplicate Product', error.response?.data?.message || 'The product could not be duplicated.', 'error');
    } finally {
        duplicatingId.value = null;
    }
};

const updateProductStatus = async (product) => {
    const status = product.status === 'active' ? 'inactive' : 'active';

    openActionMenuId.value = null;
    statusUpdatingId.value = product.id;

    try {
        const response = await ProductApi.bulkStatus([product.id], status);
        await loadProducts(pagination.value.current_page || 1);
        showMessage('Status Updated', response.message || 'The product status was updated successfully.', 'success');
    } catch (error) {
        console.error(error);
        showMessage('Unable to Update Status', error.response?.data?.message || 'The product status could not be updated.', 'error');
    } finally {
        statusUpdatingId.value = null;
    }
};

const bulkStatusUpdate = async (status) => {
    if (!selectedIds.value.length) {
        showMessage('No Products Selected', 'Select one or more products before applying a bulk action.', 'warning');
        return;
    }

    bulkProcessing.value = true;

    try {
        const response = await ProductApi.bulkStatus(selectedIds.value, status);
        selectedIds.value = [];
        await loadProducts(pagination.value.current_page || 1);
        showMessage('Status Updated', response.message || 'The selected product status was updated successfully.', 'success');
    } catch (error) {
        console.error(error);
        showMessage('Unable to Update Products', error.response?.data?.message || 'The selected products could not be updated.', 'error');
    } finally {
        bulkProcessing.value = false;
    }
};

const deleteProduct = async (product) => {
    const confirmed = await requestConfirmation({
        title: 'Delete Product',
        message: `Delete "${product.name}"? This action can be restored only if soft delete is enabled.`,
        confirmText: 'Delete',
        danger: true,
    });

    if (!confirmed) {
        return;
    }

    openActionMenuId.value = null;
    deletingId.value = product.id;

    try {
        const response = await ProductApi.deleteProduct(product.id);
        await loadProducts(pagination.value.current_page || 1);
        showMessage('Product Deleted', response.message || 'The product was deleted successfully.', 'success');
    } catch (error) {
        console.error(error);

        showMessage('Unable to Delete Product', error.response?.data?.message || 'The product could not be deleted.', 'error');
    } finally {
        deletingId.value = null;
    }
};

const openBarcode = (product) => {
    openActionMenuId.value = null;
    barcodeProduct.value = { ...product };
    showBarcodeModal.value = true;
};

const openSingleLabel = (product) => {
    openActionMenuId.value = null;
    labelProducts.value = [{ ...product }];
    showLabelModal.value = true;
};

const toggleActionMenu = (productId) => {
    openActionMenuId.value = openActionMenuId.value === productId ? null : productId;
};

const openAllLabels = () => {
    if (!rows.value.length) {
        showMessage('No Products Available', 'There are no products available to print.', 'warning');
        return;
    }

    labelProducts.value = rows.value.map((product) => ({ ...product }));
    showLabelModal.value = true;
};

const clearFilters = () => {
    search.value = '';
    productTypeFilter.value = '';
    itemTypeFilter.value = '';
    statusFilter.value = '';
    categoryFilter.value = '';
    brandFilter.value = '';
    unitFilter.value = '';
    gstRateFilter.value = '';
};

const toggleSelectAll = () => {
    if (allRowsSelected.value) {
        selectedIds.value = [];
        return;
    }

    selectedIds.value = rows.value.map((product) => product.id);
};

const toggleSelection = (id) => {
    if (selectedIds.value.includes(id)) {
        selectedIds.value = selectedIds.value.filter((selectedId) => selectedId !== id);
        return;
    }

    selectedIds.value = [...selectedIds.value, id];
};

const buildCsv = (exportRows) => {
    const headings = [
        'Product Name',
        'SKU',
        'Barcode',
        'Category',
        'Brand',
        'Unit',
        'Selling Price',
        'MRP',
        'GST',
        'Status',
    ];

    const csvRows = exportRows.map((product) => [
        product.name,
        product.sku,
        primaryBarcode(product),
        product.category,
        product.brand,
        product.unit,
        product.selling_price,
        product.mrp,
        product.gst_rate,
        product.status,
    ]);

    const csv = [headings, ...csvRows]
        .map((row) =>
            row.map((value) => `"${String(value ?? '').replaceAll('"', '""')}"`).join(',')
        )
        .join('\n');

    return csv;
};

const exportProducts = async () => {
    exportProcessing.value = true;

    try {
        const firstResponse = await ProductApi.getProducts({
            ...requestParams(1),
            per_page: 100,
        });

        const exportRows = [...(firstResponse.products || [])];
        const lastPage = firstResponse.pagination?.last_page || 1;

        for (let page = 2; page <= lastPage; page++) {
            const response = await ProductApi.getProducts({
                ...requestParams(page),
                per_page: 100,
            });

            exportRows.push(...(response.products || []));
        }

        if (!exportRows.length) {
            showMessage('No Products Available', 'There are no products available to export.', 'warning');
            return;
        }

        const csv = buildCsv(exportRows);
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'products-export.csv';
        link.click();
        URL.revokeObjectURL(url);
    } catch (error) {
        console.error(error);

        showMessage('Unable to Export Products', 'The product export could not be completed.', 'error');
    } finally {
        exportProcessing.value = false;
    }
};

const changePage = (page) => {
    if (
        page < 1 ||
        page > pagination.value.last_page ||
        page === pagination.value.current_page ||
        loading.value
    ) {
        return;
    }

    loadProducts(page);
};

const primaryBarcode = (product) => {
    if (product.primary_barcode) {
        return product.primary_barcode;
    }

    const primary = (product.barcodes || []).find((barcode) => barcode.is_primary);

    return primary?.barcode || product.barcodes?.[0]?.barcode || '-';
};

const productImage = (product) => {
    const primary = (product.images || []).find((image) => image.is_primary);
    const path = String(primary?.image_path || product.images?.[0]?.image_path || '').trim();

    if (!path) {
        return null;
    }

    if (path.startsWith('data:') || path.startsWith('blob:')) {
        return path;
    }

    let normalizedPath = path.replace(/^\/+/, '');

    if (/^(https?:)?\/\//.test(path)) {
        try {
            normalizedPath = new URL(path, window.location.origin).pathname.replace(/^\/+/, '');
        } catch {
            return path;
        }
    }

    if (normalizedPath.startsWith('storage/')) {
        return `/${normalizedPath}`;
    }

    if (normalizedPath.startsWith('uploads/') || normalizedPath.startsWith('upload/')) {
        return `/storage/${normalizedPath}`;
    }

    return `/storage/${normalizedPath}`;
};

const openProductImage = (product) => {
    const url = productImage(product);

    if (!url) {
        return;
    }

    window.open(url, '_blank', 'noopener');
};

const formatPrice = (value) => {
    return Number(value || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

watch(
    [
        search,
        productTypeFilter,
        itemTypeFilter,
        statusFilter,
        categoryFilter,
        brandFilter,
        unitFilter,
        gstRateFilter,
        perPage,
    ],
    () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadProducts(1), 350);
    }
);

onMounted(() => {
    productPrefillFromQuery();
    loadReferences();
    loadProducts();
});
</script>

<template>
    <Layout
        :page="page"
        :title="title"
    >
        <template #topbar-title>
            <div class="bill-page-title">
                <span>INVENTORY MANAGEMENT</span>
                <h1>Products & Barcode</h1>
                <p>Manage product images, pricing, GST, HSN and barcode details.</p>
            </div>
        </template>
        <div class="product-page">
            <div class="page-actions">
                    <button
                        type="button"
                        class="secondary-action"
                        :disabled="exportProcessing"
                        @click="exportProducts"
                    >
                        {{ exportProcessing ? 'Exporting...' : 'Export' }}
                    </button>

                    <button
                        type="button"
                        class="secondary-action"
                        @click="openAllLabels"
                    >
                        Print Labels
                    </button>

                    <button
                        type="button"
                        class="primary-action"
                        @click="addProduct"
                    >
                        <span class="plus-icon">+</span>
                        Add Product
                    </button>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <span>Total Products</span>
                    <strong>{{ pageTotal }}</strong>
                </div>

                <div class="summary-card">
                    <span>Active on Page</span>
                    <strong>{{ activeProductsCount }}</strong>
                </div>

                <div class="summary-card">
                    <span>Inactive on Page</span>
                    <strong>{{ inactiveProductsCount }}</strong>
                </div>
            </div>

            <section class="listing-card">
                <div class="listing-toolbar">
                    <div class="search-box">
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                        >
                            <circle
                                cx="11"
                                cy="11"
                                r="7"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                            <path
                                d="m20 20-4-4"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            />
                        </svg>

                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search by product name, SKU, barcode, HSN..."
                        />
                    </div>

                    <div class="filter-group">
                        <select v-model="productTypeFilter">
                            <option value="">All Types</option>
                            <option value="goods">Goods</option>
                            <option value="service">Service</option>
                        </select>

                        <select v-model="itemTypeFilter">
                            <option value="">All Items</option>
                            <option value="stock">Stock Item</option>
                            <option value="non_stock">Non-stock</option>
                        </select>

                        <select v-model="statusFilter">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="deleted">Deleted</option>
                        </select>

                        <select v-model="categoryFilter">
                            <option value="">All Categories</option>
                            <option
                                v-for="option in categoryFilterOptions"
                                :key="`category-${option.value}`"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>

                        <select v-model="brandFilter">
                            <option value="">All Brands</option>
                            <option
                                v-for="option in brandFilterOptions"
                                :key="`brand-${option.value}`"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>

                        <select v-model="unitFilter">
                            <option value="">All Units</option>
                            <option
                                v-for="option in unitFilterOptions"
                                :key="`unit-${option.value}`"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>

                        <select v-model="gstRateFilter">
                            <option value="">All GST</option>
                            <option
                                v-for="option in gstRateFilterOptions"
                                :key="`gst-${option.value}`"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>

                        <button
                            v-if="hasFilters"
                            type="button"
                            class="clear-filter"
                            @click="clearFilters"
                        >
                            Clear
                        </button>
                    </div>
                </div>

                <div class="bulk-bar">
                    <div>
                        <strong>{{ selectedIds.length }}</strong>
                        selected
                    </div>

                    <div class="bulk-actions">
                        <button
                            type="button"
                            :disabled="!selectedIds.length || bulkProcessing"
                            @click="bulkStatusUpdate('active')"
                        >
                            Activate
                        </button>

                        <button
                            type="button"
                            :disabled="!selectedIds.length || bulkProcessing"
                            @click="bulkStatusUpdate('inactive')"
                        >
                            Deactivate
                        </button>

                        <select v-model="perPage">
                            <option :value="10">10 / page</option>
                            <option :value="15">15 / page</option>
                            <option :value="25">25 / page</option>
                            <option :value="50">50 / page</option>
                        </select>
                    </div>
                </div>

                <div class="listing-information">
                    <div>
                        <strong>Product Master</strong>
                        <span>
                            Showing {{ pagination.from || 0 }} to {{ pagination.to || 0 }}
                            of {{ pagination.total || 0 }} products
                        </span>
                    </div>
                </div>

                <div
                    v-if="loading"
                    class="loading-state"
                >
                    <div class="loader"></div>
                    <strong>Loading products...</strong>
                    <span>Please wait while product data is loaded.</span>
                </div>

                <div
                    v-else-if="!rows.length"
                    class="empty-state"
                >
                    <div class="empty-icon">
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                        >
                            <path
                                d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5v-9Z"
                                stroke="currentColor"
                                stroke-width="1.7"
                            />
                            <path
                                d="m4.5 7.5 7.5 4.3 7.5-4.3M12 12v8.5"
                                stroke="currentColor"
                                stroke-width="1.7"
                            />
                        </svg>
                    </div>

                    <h3>{{ hasFilters ? 'No matching products' : 'No products added yet' }}</h3>
                    <p>{{ hasFilters ? 'Adjust your search or filters and try again.' : 'Add your first product to start setting up inventory.' }}</p>

                    <button
                        v-if="!hasFilters"
                        type="button"
                        class="primary-action"
                        @click="addProduct"
                    >
                        <span class="plus-icon">+</span>
                        Add First Product
                    </button>
                </div>

                <CrudTable
                    v-else
                    :columns="productColumns"
                    :rows="rows"
                    :loading="loading"
                    :value-for="productValueFor"
                    selectable
                    :selected-ids="selectedIds"
                    @toggle-select-all="toggleSelectAll"
                    @toggle-row="toggleSelection"
                >
                    <template #cell-image="{ row: product }">
                        <button
                            v-if="productImage(product)"
                            type="button"
                            class="product-image product-image-button"
                            :title="`Open ${product.name} image`"
                            @click="openProductImage(product)"
                        >
                            <img :src="productImage(product)" :alt="product.name" />
                        </button>

                        <div v-else class="product-image">
                            <span>{{ String(product.name || 'P').charAt(0).toUpperCase() }}</span>
                        </div>
                    </template>

                    <template #cell-name="{ row: product }">
                        <div class="product-information">
                            <strong>{{ product.name }}</strong>
                            <span>{{ product.product_type === 'service' ? 'Service' : 'Goods' }}</span>
                        </div>
                    </template>

                    <template #cell-gst_rate="{ row: product }">
                        <span class="gst-badge">{{ Number(product.gst_rate || 0) }}%</span>
                    </template>

                    <template #actions="{ row: product }">
                        <button type="button" class="crud-action" title="Edit product" @click="editProduct(product)">Edit</button>

                        <div class="action-menu-wrap">
                            <button type="button" class="crud-action more-action" title="More actions" @click="toggleActionMenu(product.id)">More</button>

                            <div v-if="openActionMenuId === product.id" class="action-menu">
                                <button type="button" @click="openView(product)">View</button>
                                <button type="button" :disabled="duplicatingId === product.id" @click="duplicateProduct(product)">
                                    {{ duplicatingId === product.id ? 'Copying...' : 'Duplicate' }}
                                </button>
                                <button type="button" :disabled="statusUpdatingId === product.id" @click="updateProductStatus(product)">
                                    {{ product.status === 'active' ? 'Deactivate' : 'Activate' }}
                                </button>
                                <button type="button" @click="openBarcode(product)">Barcode</button>
                                <button type="button" @click="openSingleLabel(product)">Print Label</button>
                                <button type="button" class="danger" :disabled="deletingId === product.id" @click="deleteProduct(product)">
                                    {{ deletingId === product.id ? 'Deleting...' : 'Delete' }}
                                </button>
                            </div>
                        </div>
                    </template>
                </CrudTable>

                <div
                    v-if="pagination.last_page > 1"
                    class="pagination-bar"
                >
                    <button
                        type="button"
                        :disabled="pagination.current_page <= 1 || loading"
                        @click="changePage(pagination.current_page - 1)"
                    >
                        Previous
                    </button>

                    <button
                        v-for="pageNumber in visiblePages"
                        :key="pageNumber"
                        type="button"
                        :class="{ active: pageNumber === pagination.current_page }"
                        @click="changePage(pageNumber)"
                    >
                        {{ pageNumber }}
                    </button>

                    <button
                        type="button"
                        :disabled="pagination.current_page >= pagination.last_page || loading"
                        @click="changePage(pagination.current_page + 1)"
                    >
                        Next
                    </button>
                </div>
            </section>
        </div>

        <ProductForm
            v-model="showForm"
            :product="selectedProduct"
            :processing="saving"
            :errors="serverErrors"
            :references="references"
            :can-edit-gst-rate="[1, 2].includes(Number(role_id))"
            @save="saveProduct"
        />

        <BarcodeModal
            v-model="showBarcodeModal"
            :product="barcodeProduct"
        />

        <LabelModal
            v-model="showLabelModal"
            :products="labelProducts"
        />

        <div
            v-if="viewProduct"
            class="view-overlay"
            @click.self="viewProduct = null"
        >
            <aside class="view-drawer">
                <div class="view-header">
                    <div>
                        <span>Product Details</span>
                        <h2>{{ viewProduct.name }}</h2>
                    </div>

                    <button
                        type="button"
                        @click="viewProduct = null"
                    >
                        x
                    </button>
                </div>

                <div class="view-grid">
                    <div>
                        <label>SKU</label>
                        <strong>{{ viewProduct.sku || '-' }}</strong>
                    </div>
                    <div>
                        <label>Barcode</label>
                        <strong>{{ primaryBarcode(viewProduct) }}</strong>
                    </div>
                    <div>
                        <label>Category</label>
                        <strong>{{ viewProduct.category || '-' }}</strong>
                    </div>
                    <div>
                        <label>Brand</label>
                        <strong>{{ viewProduct.brand || '-' }}</strong>
                    </div>
                    <div>
                        <label>Unit</label>
                        <strong>{{ viewProduct.unit || '-' }}</strong>
                    </div>
                    <div>
                        <label>GST</label>
                        <strong>{{ Number(viewProduct.gst_rate || 0) }}%</strong>
                    </div>
                    <div>
                        <label>Selling Price</label>
                        <strong>Rs. {{ formatPrice(viewProduct.selling_price) }}</strong>
                    </div>
                    <div>
                        <label>MRP</label>
                        <strong>{{ viewProduct.mrp ? `Rs. ${formatPrice(viewProduct.mrp)}` : '-' }}</strong>
                    </div>
                </div>
            </aside>
        </div>

        <Transition name="toast-slide">
            <div
                v-if="toast.show"
                class="toast-message"
                :class="toast.type"
            >
                <span class="toast-icon"></span>

                <div>
                    <strong>{{ toast.title }}</strong>
                    <p>{{ toast.message }}</p>
                </div>
            </div>
        </Transition>

        <div
            v-if="confirmModal.show"
            class="feedback-overlay"
            @click.self="resolveConfirmation(false)"
        >
            <section
                class="feedback-modal"
                :class="{ danger: confirmModal.danger }"
            >
                <header>
                    <span class="feedback-icon"></span>
                    <h3>{{ confirmModal.title }}</h3>
                </header>

                <p>{{ confirmModal.message }}</p>

                <footer>
                    <button
                        type="button"
                        class="secondary-action"
                        @click="resolveConfirmation(false)"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        :class="confirmModal.danger ? 'danger-action' : 'primary-action'"
                        @click="resolveConfirmation(true)"
                    >
                        {{ confirmModal.confirmText }}
                    </button>
                </footer>
            </section>
        </div>
    </Layout>
</template>

<style scoped>
.product-page {
    padding: 0 0 28px;
}

.page-actions,
.filter-group,
.bulk-actions,
.row-actions,
.pagination-bar {
    display: flex;
    align-items: center;
    gap: 9px;
}

.page-actions {
    justify-content: flex-end;
    flex-wrap: wrap;
    margin: -8px 0 14px;
}

.primary-action,
.secondary-action {
    min-height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 9px 16px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 750;
    cursor: pointer;
}

.primary-action {
    color: #ffffff;
    background: #2457d6;
    border: 1px solid #2457d6;
}

.secondary-action {
    color: #35435b;
    background: #ffffff;
    border: 1px solid #d9e0ea;
}

.danger-action {
    min-height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 9px 16px;
    color: #ffffff;
    background: #d23f49;
    border: 1px solid #d23f49;
    border-radius: 10px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 750;
}

.plus-icon {
    font-size: 20px;
    font-weight: 400;
    line-height: 1;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 18px;
}

.summary-card {
    padding: 17px;
    background: #ffffff;
    border: 1px solid #e1e7f0;
    border-radius: 13px;
    box-shadow: 0 5px 18px rgba(25, 49, 83, 0.04);
}

.summary-card span,
.summary-card strong {
    display: block;
}

.summary-card span {
    margin-bottom: 3px;
    color: #7a869a;
    font-size: 11px;
}

.summary-card strong {
    color: #17233b;
    font-size: 21px;
    font-weight: 800;
}

.listing-card {
    overflow: visible;
    background: #ffffff;
    border: 1px solid #dfe6ef;
    border-radius: 15px;
    box-shadow: 0 7px 24px rgba(25, 50, 84, 0.045);
}

.listing-toolbar {
    display: grid;
    grid-template-columns: minmax(280px, 420px) minmax(0, 1fr);
    gap: 14px;
    padding: 16px 20px;
    border-bottom: 1px solid #e8edf3;
}

.search-box {
    min-height: 42px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 13px;
    background: #f7f9fc;
    border: 1px solid #dce3ec;
    border-radius: 10px;
}

.search-box svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    color: #7a869a;
}

.search-box input,
.filter-group input,
.filter-group select,
.bulk-actions select {
    min-height: 42px;
    color: #344159;
    background: #ffffff;
    border: 1px solid #dce3ec;
    border-radius: 9px;
    outline: none;
    font-size: 12px;
}

.search-box input {
    width: 100%;
    min-width: 0;
    padding: 10px 0;
    background: transparent;
    border: 0;
}

.filter-group {
    justify-content: flex-end;
    flex-wrap: wrap;
}

.filter-group input,
.filter-group select {
    width: 118px;
    padding: 9px 10px;
}

.clear-filter,
.bulk-actions button,
.pagination-bar button {
    min-height: 38px;
    padding: 8px 12px;
    color: #35435b;
    background: #ffffff;
    border: 1px solid #dce3ec;
    border-radius: 9px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}

.clear-filter {
    color: #d03b45;
    background: #fff4f5;
    border-color: #ffd7da;
}

.bulk-bar,
.listing-information,
.pagination-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 20px;
    background: #fbfcfe;
    border-bottom: 1px solid #edf1f5;
    color: #69758a;
    font-size: 11px;
}

.bulk-bar strong {
    color: #17233b;
}

.bulk-actions button:disabled,
.pagination-bar button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}

.listing-information strong,
.listing-information span {
    display: block;
}

.listing-information strong {
    margin-bottom: 3px;
    color: #24314a;
    font-size: 12px;
}

.table-wrapper {
    overflow-x: auto;
    overflow-y: visible;
    padding-bottom: 170px;
    margin-bottom: -170px;
}

.product-table {
    width: 100%;
    border-collapse: collapse;
}

.product-table th {
    padding: 13px 12px;
    color: #69758a;
    background: #f8fafc;
    border-bottom: 1px solid #e7ecf2;
    text-align: left;
    white-space: nowrap;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.45px;
    text-transform: uppercase;
}

.product-table td {
    padding: 14px 12px;
    color: #27344c;
    border-bottom: 1px solid #edf1f5;
    vertical-align: middle;
    white-space: nowrap;
    font-size: 12px;
}

.product-table tbody tr:hover {
    background: #fbfcff;
}

.select-column {
    width: 38px;
    text-align: center !important;
}

.product-image {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    overflow: hidden;
    color: #2457d6;
    background: #eaf0ff;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 800;
}

.product-image-button {
    padding: 0;
    border: 0;
    cursor: pointer;
}

.product-image-button:focus {
    outline: 2px solid #2457d6;
    outline-offset: 2px;
}

.product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.product-information {
    min-width: 180px;
}

.product-information strong,
.product-information span {
    display: block;
}

.product-information strong {
    margin-bottom: 3px;
    color: #1b2840;
    font-size: 12px;
    font-weight: 750;
}

.product-information span {
    color: #748097;
    font-size: 10px;
}

.gst-badge,
.status-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 7px;
    font-size: 9px;
    font-weight: 750;
}

.gst-badge {
    padding: 4px 7px;
    color: #2457d6;
    background: #edf2ff;
}

.status-badge {
    gap: 6px;
    padding: 5px 8px;
}

.status-badge span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
}

.status-badge.active {
    color: #168757;
    background: #eaf8f1;
}

.status-badge.active span {
    background: #20a464;
}

.status-badge.inactive {
    color: #69758a;
    background: #f0f2f5;
}

.status-badge.inactive span {
    background: #8d97a7;
}

.action-column {
    text-align: right !important;
    width: 172px;
}

.row-actions {
    justify-content: flex-end;
    flex-wrap: nowrap;
    position: relative;
}

.icon-action {
    min-width: 46px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 8px;
    color: #536179;
    background: #ffffff;
    border: 1px solid #dce3ec;
    border-radius: 8px;
    cursor: pointer;
    font-size: 10px;
    font-weight: 750;
}

.icon-action.primary {
    color: #2457d6;
    background: #edf2ff;
    border-color: #ccdaff;
}

.more-action {
    min-width: 56px;
}

.icon-action:hover {
    color: #2457d6;
    background: #edf2ff;
    border-color: #ccdaff;
}

.icon-action.danger:hover {
    color: #d23f49;
    background: #fff1f2;
    border-color: #ffd5d8;
}

.icon-action:disabled {
    cursor: not-allowed;
    opacity: 0.65;
}

.action-menu-wrap {
    position: relative;
}

.action-menu {
    position: absolute;
    right: 0;
    top: calc(100% + 7px);
    z-index: 25;
    min-width: 150px;
    padding: 6px;
    display: grid;
    gap: 3px;
    background: #ffffff;
    border: 1px solid #dce3ec;
    border-radius: 8px;
    box-shadow: 0 16px 34px rgba(15, 23, 42, 0.16);
}

.action-menu button {
    width: 100%;
    min-height: 32px;
    padding: 0 10px;
    color: #344158;
    background: transparent;
    border: 0;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 750;
    text-align: left;
}

.action-menu button:hover {
    color: #2457d6;
    background: #edf2ff;
}

.action-menu button.danger {
    color: #b4232f;
}

.action-menu button.danger:hover {
    color: #b4232f;
    background: #fff1f2;
}

.action-menu button:disabled {
    cursor: not-allowed;
    opacity: 0.62;
}

.loading-state,
.empty-state {
    min-height: 330px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    padding: 40px 20px;
    text-align: center;
}

.loader {
    width: 34px;
    height: 34px;
    margin-bottom: 15px;
    border: 3px solid #dfe7f5;
    border-top-color: #2457d6;
    border-radius: 50%;
    animation: spin 0.75s linear infinite;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.loading-state strong,
.loading-state span {
    display: block;
}

.loading-state strong {
    margin-bottom: 4px;
    color: #28354d;
    font-size: 13px;
}

.loading-state span,
.empty-state p {
    color: #8490a2;
    font-size: 11px;
}

.empty-icon {
    width: 62px;
    height: 62px;
    display: grid;
    place-items: center;
    margin-bottom: 16px;
    color: #2457d6;
    background: #edf2ff;
    border-radius: 18px;
}

.empty-icon svg {
    width: 31px;
    height: 31px;
}

.empty-state h3 {
    margin: 0 0 7px;
    color: #1d2a42;
    font-size: 17px;
    font-weight: 800;
}

.empty-state p {
    max-width: 390px;
    margin: 0 0 18px;
    line-height: 1.6;
}

.pagination-bar {
    justify-content: flex-end;
    border-top: 1px solid #edf1f5;
    border-bottom: 0;
}

.pagination-bar button.active {
    color: #ffffff;
    background: #2457d6;
    border-color: #2457d6;
}

.view-overlay {
    position: fixed;
    inset: 0;
    z-index: 1050;
    display: flex;
    justify-content: flex-end;
    background: rgba(16, 28, 52, 0.35);
}

.view-drawer {
    width: min(480px, 100%);
    height: 100%;
    overflow-y: auto;
    background: #ffffff;
    box-shadow: -18px 0 34px rgba(18, 36, 66, 0.16);
}

.view-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 22px;
    border-bottom: 1px solid #e8edf3;
}

.view-header span,
.view-grid label {
    display: block;
    color: #7a869a;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.6px;
    text-transform: uppercase;
}

.view-header h2 {
    margin: 5px 0 0;
    color: #142139;
    font-size: 20px;
    font-weight: 800;
}

.view-header button {
    width: 34px;
    height: 34px;
    color: #536179;
    background: #ffffff;
    border: 1px solid #dce3ec;
    border-radius: 9px;
    cursor: pointer;
}

.view-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    padding: 22px;
}

.view-grid div {
    padding: 14px;
    background: #f8fafc;
    border: 1px solid #e4eaf2;
    border-radius: 10px;
}

.view-grid strong {
    display: block;
    margin-top: 6px;
    color: #1f2d45;
    font-size: 13px;
}

.toast-message {
    position: fixed;
    top: 18px;
    right: 22px;
    z-index: 13000;
    width: min(360px, calc(100vw - 32px));
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 15px;
    background: #ffffff;
    border: 1px solid #dfe6ef;
    border-left: 4px solid #2457d6;
    border-radius: 10px;
    box-shadow: 0 18px 44px rgba(15, 23, 42, 0.16);
}

.toast-message strong {
    display: block;
    color: #142038;
    font-size: 13px;
    font-weight: 800;
}

.toast-message p {
    margin: 4px 0 0;
    color: #536179;
    font-size: 12px;
    line-height: 1.45;
}

.toast-icon {
    width: 24px;
    height: 24px;
    flex-shrink: 0;
    border-radius: 7px;
    background: #edf2ff;
    border: 1px solid #ccdaff;
}

.toast-message.success {
    border-left-color: #20a464;
}

.toast-message.success .toast-icon {
    background: #eaf8f1;
    border-color: #bfead5;
}

.toast-message.error {
    border-left-color: #d23f49;
}

.toast-message.error .toast-icon {
    background: #fff1f2;
    border-color: #ffd5d8;
}

.toast-message.warning {
    border-left-color: #d79a20;
}

.toast-message.warning .toast-icon {
    background: #fff8e6;
    border-color: #ffe0a3;
}

.toast-slide-enter-active,
.toast-slide-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.toast-slide-enter-from,
.toast-slide-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}

.feedback-overlay {
    position: fixed;
    inset: 0;
    z-index: 12000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(15, 23, 42, 0.5);
}

.feedback-modal {
    width: min(440px, 94vw);
    padding: 22px;
    background: #ffffff;
    border: 1px solid #dfe6ef;
    border-radius: 12px;
    box-shadow: 0 26px 70px rgba(15, 23, 42, 0.26);
}

.feedback-modal header {
    display: flex;
    align-items: center;
    gap: 12px;
}

.feedback-modal h3 {
    margin: 0;
    color: #142038;
    font-size: 18px;
    font-weight: 800;
}

.feedback-modal p {
    margin: 14px 0 20px;
    color: #536179;
    font-size: 14px;
    line-height: 1.55;
}

.feedback-modal footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.feedback-icon {
    width: 34px;
    height: 34px;
    display: inline-flex;
    flex-shrink: 0;
    border-radius: 9px;
    background: #edf2ff;
    border: 1px solid #ccdaff;
}

.feedback-modal.danger .feedback-icon {
    background: #fff1f2;
    border-color: #ffd5d8;
}

@media (max-width: 1100px) {
    .listing-toolbar {
        grid-template-columns: 1fr;
    }

    .filter-group {
        justify-content: flex-start;
    }
}

@media (max-width: 767px) {
    .bulk-bar {
        align-items: stretch;
        flex-direction: column;
    }

    .page-actions {
        width: 100%;
        flex-wrap: wrap;
        margin-top: 0;
    }

    .primary-action,
    .secondary-action {
        flex: 1;
    }

    .summary-grid,
    .view-grid {
        grid-template-columns: 1fr;
    }

    .listing-toolbar,
    .bulk-bar,
    .listing-information,
    .pagination-bar {
        padding-left: 14px;
        padding-right: 14px;
    }

    .filter-group input,
    .filter-group select {
        flex: 1;
        width: auto;
        min-width: 120px;
    }

    .pagination-bar {
        justify-content: center;
        flex-wrap: wrap;
    }
}
</style>
