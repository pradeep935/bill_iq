<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Layout from '../Layout.vue';

import ProductApi from './ProductApi';
import ProductForm from './ProductForm.vue';
import BarcodeModal from './BarcodeModal.vue';
import LabelModal from './LabelModal.vue';

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

const selectedIds = ref([]);
let searchTimer = null;

const rows = computed(() => products.value);

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

        alert('Products load nahi ho sake.');
    } finally {
        loading.value = false;
    }
};

const addProduct = () => {
    serverErrors.value = {};
    selectedProduct.value = {};
    showForm.value = true;
};

const editProduct = async (product) => {
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

        await loadProducts(pagination.value.current_page || 1);

        alert(response.message || 'Product successfully saved.');
    } catch (error) {
        if (error.response?.status === 422) {
            const errors = error.response.data.errors || {};
            serverErrors.value = errors;

            const firstError = Object.values(errors)?.[0]?.[0];

            alert(
                firstError ||
                error.response.data.message ||
                'Please check the form fields.'
            );

            return;
        }

        console.error(error);
        alert('Product save nahi ho saka.');
    } finally {
        saving.value = false;
    }
};

const duplicateProduct = async (product) => {
    const confirmed = window.confirm(`"${product.name}" duplicate karna hai?`);

    if (!confirmed) {
        return;
    }

    duplicatingId.value = product.id;

    try {
        const response = await ProductApi.duplicateProduct(product.id);
        await loadProducts(1);
        alert(response.message || 'Product duplicated successfully.');
    } catch (error) {
        console.error(error);
        alert(error.response?.data?.message || 'Product duplicate nahi ho saka.');
    } finally {
        duplicatingId.value = null;
    }
};

const updateProductStatus = async (product) => {
    const status = product.status === 'active' ? 'inactive' : 'active';

    statusUpdatingId.value = product.id;

    try {
        const response = await ProductApi.bulkStatus([product.id], status);
        await loadProducts(pagination.value.current_page || 1);
        alert(response.message || 'Product status updated successfully.');
    } catch (error) {
        console.error(error);
        alert(error.response?.data?.message || 'Status update nahi ho saka.');
    } finally {
        statusUpdatingId.value = null;
    }
};

const bulkStatusUpdate = async (status) => {
    if (!selectedIds.value.length) {
        alert('Pehle products select karein.');
        return;
    }

    bulkProcessing.value = true;

    try {
        const response = await ProductApi.bulkStatus(selectedIds.value, status);
        selectedIds.value = [];
        await loadProducts(pagination.value.current_page || 1);
        alert(response.message || 'Product status updated successfully.');
    } catch (error) {
        console.error(error);
        alert(error.response?.data?.message || 'Bulk status update nahi ho saka.');
    } finally {
        bulkProcessing.value = false;
    }
};

const deleteProduct = async (product) => {
    const confirmed = window.confirm(`"${product.name}" product delete karna hai?`);

    if (!confirmed) {
        return;
    }

    deletingId.value = product.id;

    try {
        const response = await ProductApi.deleteProduct(product.id);
        await loadProducts(pagination.value.current_page || 1);
        alert(response.message || 'Product deleted successfully.');
    } catch (error) {
        console.error(error);

        alert(error.response?.data?.message || 'Product delete nahi ho saka.');
    } finally {
        deletingId.value = null;
    }
};

const openBarcode = (product) => {
    barcodeProduct.value = { ...product };
    showBarcodeModal.value = true;
};

const openSingleLabel = (product) => {
    labelProducts.value = [{ ...product }];
    showLabelModal.value = true;
};

const openAllLabels = () => {
    if (!rows.value.length) {
        alert('Print karne ke liye product available nahi hai.');
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
            alert('Export karne ke liye products available nahi hain.');
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

        alert('Product export nahi ho saka.');
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
    const path = primary?.image_path || product.images?.[0]?.image_path;

    if (!path) {
        return null;
    }

    if (String(path).startsWith('http') || String(path).startsWith('/')) {
        return path;
    }

    return `/storage/${path}`;
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
    loadProducts();
});
</script>

<template>
    <Layout
        :page="page"
        :title="title"
    >
        <div class="product-page">
            <div class="page-heading">
                <div>
                    <span class="page-eyebrow">INVENTORY MANAGEMENT</span>
                    <h1>Products & Barcode</h1>
                    <p>Manage product images, pricing, GST, HSN and barcode details.</p>
                </div>

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
                            placeholder="Search product, SKU, barcode, HSN..."
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

                        <input
                            v-model="categoryFilter"
                            type="text"
                            placeholder="Category"
                        />

                        <input
                            v-model="brandFilter"
                            type="text"
                            placeholder="Brand"
                        />

                        <input
                            v-model="unitFilter"
                            type="text"
                            placeholder="Unit"
                        />

                        <input
                            v-model="gstRateFilter"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="GST %"
                        />

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
                    <p>{{ hasFilters ? 'Search ya filters change karke dobara try karein.' : 'Apna first product add karke inventory setup start karein.' }}</p>

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

                <div
                    v-else
                    class="table-wrapper"
                >
                    <table class="product-table">
                        <thead>
                            <tr>
                                <th class="select-column">
                                    <input
                                        type="checkbox"
                                        :checked="allRowsSelected"
                                        @change="toggleSelectAll"
                                    />
                                </th>
                                <th>Image</th>
                                <th>Product Name</th>
                                <th>SKU</th>
                                <th>Barcode</th>
                                <th>Category</th>
                                <th>Brand</th>
                                <th>Unit</th>
                                <th>Selling Price</th>
                                <th>MRP</th>
                                <th>GST</th>
                                <th>Status</th>
                                <th class="action-column">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="product in rows"
                                :key="product.id"
                            >
                                <td class="select-column">
                                    <input
                                        type="checkbox"
                                        :checked="selectedIds.includes(product.id)"
                                        @change="toggleSelection(product.id)"
                                    />
                                </td>

                                <td>
                                    <div class="product-image">
                                        <img
                                            v-if="productImage(product)"
                                            :src="productImage(product)"
                                            :alt="product.name"
                                        />
                                        <span v-else>
                                            {{ String(product.name || 'P').charAt(0).toUpperCase() }}
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <div class="product-information">
                                        <strong>{{ product.name }}</strong>
                                        <span>{{ product.product_type === 'service' ? 'Service' : 'Goods' }}</span>
                                    </div>
                                </td>

                                <td>{{ product.sku || '-' }}</td>
                                <td>{{ primaryBarcode(product) }}</td>
                                <td>{{ product.category || '-' }}</td>
                                <td>{{ product.brand || '-' }}</td>
                                <td>{{ product.unit || '-' }}</td>
                                <td>Rs. {{ formatPrice(product.selling_price) }}</td>
                                <td>{{ product.mrp ? `Rs. ${formatPrice(product.mrp)}` : '-' }}</td>
                                <td>
                                    <span class="gst-badge">
                                        {{ Number(product.gst_rate || 0) }}%
                                    </span>
                                </td>
                                <td>
                                    <span
                                        class="status-badge"
                                        :class="product.status"
                                    >
                                        <span></span>
                                        {{ product.status === 'active' ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>

                                <td class="action-column">
                                    <div class="row-actions">
                                        <button
                                            type="button"
                                            class="icon-action"
                                            title="View product"
                                            @click="openView(product)"
                                        >
                                            View
                                        </button>

                                        <button
                                            type="button"
                                            class="icon-action"
                                            title="Edit product"
                                            @click="editProduct(product)"
                                        >
                                            Edit
                                        </button>

                                        <button
                                            type="button"
                                            class="icon-action"
                                            title="Duplicate product"
                                            :disabled="duplicatingId === product.id"
                                            @click="duplicateProduct(product)"
                                        >
                                            {{ duplicatingId === product.id ? '...' : 'Copy' }}
                                        </button>

                                        <button
                                            type="button"
                                            class="icon-action"
                                            title="Activate or deactivate"
                                            :disabled="statusUpdatingId === product.id"
                                            @click="updateProductStatus(product)"
                                        >
                                            {{ product.status === 'active' ? 'Off' : 'On' }}
                                        </button>

                                        <button
                                            type="button"
                                            class="icon-action"
                                            title="View barcode"
                                            @click="openBarcode(product)"
                                        >
                                            Code
                                        </button>

                                        <button
                                            type="button"
                                            class="icon-action"
                                            title="Print label"
                                            @click="openSingleLabel(product)"
                                        >
                                            Print
                                        </button>

                                        <button
                                            type="button"
                                            class="icon-action danger"
                                            title="Delete product"
                                            :disabled="deletingId === product.id"
                                            @click="deleteProduct(product)"
                                        >
                                            {{ deletingId === product.id ? '...' : 'Del' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

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
    </Layout>
</template>

<style scoped>
.product-page {
    padding: 4px 0 28px;
}

.page-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 22px;
}

.page-eyebrow {
    display: block;
    margin-bottom: 5px;
    color: #2457d6;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.4px;
}

.page-heading h1 {
    margin: 0;
    color: #101c34;
    font-size: 28px;
    font-weight: 800;
}

.page-heading p {
    margin: 7px 0 0;
    color: #758197;
    font-size: 13px;
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
    overflow: hidden;
    background: #ffffff;
    border: 1px solid #dfe6ef;
    border-radius: 15px;
    box-shadow: 0 7px 24px rgba(25, 50, 84, 0.045);
}

.listing-toolbar {
    display: grid;
    grid-template-columns: minmax(260px, 420px) 1fr;
    gap: 14px;
    padding: 18px 20px;
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
}

.row-actions {
    justify-content: flex-end;
    flex-wrap: nowrap;
}

.icon-action {
    min-width: 38px;
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

@media (max-width: 1100px) {
    .listing-toolbar {
        grid-template-columns: 1fr;
    }

    .filter-group {
        justify-content: flex-start;
    }
}

@media (max-width: 767px) {
    .page-heading,
    .bulk-bar {
        align-items: stretch;
        flex-direction: column;
    }

    .page-heading h1 {
        font-size: 23px;
    }

    .page-actions {
        width: 100%;
        flex-wrap: wrap;
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
