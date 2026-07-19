<script setup>
import { computed, onMounted, ref } from 'vue';
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
const loading = ref(false);
const saving = ref(false);
const deletingId = ref(null);

const search = ref('');
const productTypeFilter = ref('');
const statusFilter = ref('');

const showForm = ref(false);
const selectedProduct = ref({});

const showBarcodeModal = ref(false);
const barcodeProduct = ref({});

const showLabelModal = ref(false);
const labelProducts = ref([]);

const filteredProducts = computed(() => {
    const keyword = search.value.trim().toLowerCase();

    return products.value.filter((product) => {
        const matchesSearch =
            !keyword ||
            String(product.name || '')
                .toLowerCase()
                .includes(keyword) ||
            String(product.sku || '')
                .toLowerCase()
                .includes(keyword) ||
            String(product.primary_barcode || '')
                .toLowerCase()
                .includes(keyword) ||
            String(product.hsn_code || '')
                .toLowerCase()
                .includes(keyword) ||
            String(product.category || '')
                .toLowerCase()
                .includes(keyword) ||
            String(product.brand || '')
                .toLowerCase()
                .includes(keyword);

        const matchesType =
            !productTypeFilter.value ||
            product.product_type === productTypeFilter.value;

        const matchesStatus =
            !statusFilter.value ||
            product.status === statusFilter.value;

        return matchesSearch && matchesType && matchesStatus;
    });
});

const activeProductsCount = computed(() => {
    return products.value.filter(
        (product) => product.status === 'active'
    ).length;
});

const lowStockCount = computed(() => {
    return products.value.filter((product) => {
        if (product.product_type !== 'goods') {
            return false;
        }

        const currentStock = Number(product.opening_stock || 0);
        const minimumStock = Number(product.minimum_stock || 0);

        return minimumStock > 0 && currentStock <= minimumStock;
    }).length;
});

const loadProducts = async () => {
    loading.value = true;

    try {
        const response = await ProductApi.getProducts();

        products.value = Array.isArray(response)
            ? response
            : response.products || [];
    } catch (error) {
        console.error(error);

        alert('Products load nahi ho sake.');
    } finally {
        loading.value = false;
    }
};

const addProduct = () => {
    selectedProduct.value = {};

    showForm.value = true;
};

const editProduct = (product) => {
    selectedProduct.value = {
        ...product,
    };

    showForm.value = true;
};

const saveProduct = async (form) => {
    saving.value = true;

    try {
        const response = await ProductApi.saveProduct(form);

        showForm.value = false;

        await loadProducts();

        alert(
            response.message ||
            'Product successfully saved.'
        );
    } catch (error) {
        if (error.response?.status === 422) {
            const errors =
                error.response.data.errors || {};

            const firstError =
                Object.values(errors)?.[0]?.[0];

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

const deleteProduct = async (product) => {
    const confirmed = window.confirm(
        `"${product.name}" product delete karna hai?`
    );

    if (!confirmed) {
        return;
    }

    deletingId.value = product.id;

    try {
        const response =
            await ProductApi.deleteProduct(product.id);

        await loadProducts();

        alert(
            response.message ||
            'Product deleted successfully.'
        );
    } catch (error) {
        console.error(error);

        alert(
            error.response?.data?.message ||
            'Product delete nahi ho saka.'
        );
    } finally {
        deletingId.value = null;
    }
};

const openBarcode = (product) => {
    barcodeProduct.value = {
        ...product,
    };

    showBarcodeModal.value = true;
};

const openSingleLabel = (product) => {
    labelProducts.value = [
        {
            ...product,
        },
    ];

    showLabelModal.value = true;
};

const openAllLabels = () => {
    if (!filteredProducts.value.length) {
        alert('Print karne ke liye product available nahi hai.');

        return;
    }

    labelProducts.value = filteredProducts.value.map(
        (product) => ({
            ...product,
        })
    );

    showLabelModal.value = true;
};

const clearFilters = () => {
    search.value = '';
    productTypeFilter.value = '';
    statusFilter.value = '';
};

const formatPrice = (value) => {
    return Number(value || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};

const stockStatus = (product) => {
    if (product.product_type === 'service') {
        return {
            label: 'Service',
            className: 'service',
        };
    }

    const currentStock = Number(product.opening_stock || 0);
    const minimumStock = Number(product.minimum_stock || 0);

    if (currentStock <= 0) {
        return {
            label: 'Out of Stock',
            className: 'out',
        };
    }

    if (
        minimumStock > 0 &&
        currentStock <= minimumStock
    ) {
        return {
            label: 'Low Stock',
            className: 'low',
        };
    }

    return {
        label: 'In Stock',
        className: 'available',
    };
};

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

            <!-- Page heading -->
            <div class="page-heading">
                <div>
                    <span class="page-eyebrow">
                        INVENTORY MANAGEMENT
                    </span>

                    <h1>Products & Barcode</h1>

                    <p>
                        Manage products, GST, HSN, pricing,
                        stock and barcode settings.
                    </p>
                </div>

                <div class="page-actions">
                    <button
                        type="button"
                        class="secondary-action"
                        @click="openAllLabels"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                        >
                            <path
                                d="M6 9V4h12v5M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />

                            <path
                                d="M6 14h12v7H6z"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                        </svg>

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

            <!-- Summary cards -->
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-icon blue">
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                        >
                            <path
                                d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5v-9Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />

                            <path
                                d="m4.5 7.5 7.5 4.3 7.5-4.3M12 12v8.5"
                                stroke="currentColor"
                                stroke-width="1.8"
                            />
                        </svg>
                    </div>

                    <div>
                        <span>Total Products</span>
                        <strong>{{ products.length }}</strong>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-icon green">
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                        >
                            <path
                                d="m5 12 4 4L19 6"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </div>

                    <div>
                        <span>Active Products</span>
                        <strong>{{ activeProductsCount }}</strong>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-icon amber">
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                        >
                            <path
                                d="M12 4 3.5 19h17L12 4Z"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linejoin="round"
                            />

                            <path
                                d="M12 9v4M12 16.5v.1"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            />
                        </svg>
                    </div>

                    <div>
                        <span>Low Stock</span>
                        <strong>{{ lowStockCount }}</strong>
                    </div>
                </div>
            </div>

            <!-- Listing card -->
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
                            placeholder="Search by product, SKU, barcode, HSN..."
                        />
                    </div>

                    <div class="filter-group">
                        <select v-model="productTypeFilter">
                            <option value="">
                                All Types
                            </option>

                            <option value="goods">
                                Goods
                            </option>

                            <option value="service">
                                Services
                            </option>
                        </select>

                        <select v-model="statusFilter">
                            <option value="">
                                All Status
                            </option>

                            <option value="active">
                                Active
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>
                        </select>

                        <button
                            v-if="
                                search ||
                                productTypeFilter ||
                                statusFilter
                            "
                            type="button"
                            class="clear-filter"
                            @click="clearFilters"
                        >
                            Clear
                        </button>
                    </div>
                </div>

                <div class="listing-information">
                    <div>
                        <strong>
                            Product Master
                        </strong>

                        <span>
                            Showing
                            {{ filteredProducts.length }}
                            of
                            {{ products.length }}
                            products
                        </span>
                    </div>
                </div>

                <!-- Loading -->
                <div
                    v-if="loading"
                    class="loading-state"
                >
                    <div class="loader"></div>

                    <strong>
                        Loading products...
                    </strong>

                    <span>
                        Please wait while product data is loaded.
                    </span>
                </div>

                <!-- Empty state -->
                <div
                    v-else-if="!filteredProducts.length"
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

                    <h3>
                        {{
                            products.length
                                ? 'No matching products'
                                : 'No products added yet'
                        }}
                    </h3>

                    <p>
                        {{
                            products.length
                                ? 'Search ya filters change karke dobara try karein.'
                                : 'Apna first product add karke inventory setup start karein.'
                        }}
                    </p>

                    <button
                        v-if="!products.length"
                        type="button"
                        class="primary-action"
                        @click="addProduct"
                    >
                        <span class="plus-icon">+</span>

                        Add First Product
                    </button>
                </div>

                <!-- Table -->
                <div
                    v-else
                    class="table-wrapper"
                >
                    <table class="product-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU / Barcode</th>
                                <th>HSN & GST</th>
                                <th>Pricing</th>
                                <th>Stock</th>
                                <th>Status</th>
                                <th class="action-column">
                                    Actions
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="product in filteredProducts"
                                :key="product.id"
                            >
                                <td>
                                    <div class="product-information">
                                        <div class="product-avatar">
                                            {{
                                                String(
                                                    product.name || 'P'
                                                )
                                                    .charAt(0)
                                                    .toUpperCase()
                                            }}
                                        </div>

                                        <div>
                                            <strong>
                                                {{ product.name }}
                                            </strong>

                                            <span>
                                                {{
                                                    product.brand ||
                                                    product.category ||
                                                    'Uncategorized'
                                                }}
                                            </span>

                                            <small>
                                                {{
                                                    product.product_type ===
                                                    'service'
                                                        ? 'Service'
                                                        : product.unit ||
                                                          'PCS'
                                                }}
                                            </small>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <div class="code-information">
                                        <strong>
                                            {{ product.sku || '—' }}
                                        </strong>

                                        <span>
                                            {{
                                                product.primary_barcode ||
                                                'No barcode'
                                            }}
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <div class="tax-information">
                                        <strong>
                                            {{ product.hsn_code || '—' }}
                                        </strong>

                                        <span class="gst-badge">
                                            {{
                                                Number(
                                                    product.gst_rate || 0
                                                )
                                            }}%
                                            GST
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <div class="price-information">
                                        <strong>
                                            ₹
                                            {{
                                                formatPrice(
                                                    product.selling_price
                                                )
                                            }}
                                        </strong>

                                        <span v-if="product.mrp">
                                            MRP ₹
                                            {{
                                                formatPrice(product.mrp)
                                            }}
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <div class="stock-information">
                                        <strong>
                                            {{
                                                product.product_type ===
                                                'service'
                                                    ? '—'
                                                    : product.opening_stock ||
                                                      0
                                            }}
                                        </strong>

                                        <span
                                            class="stock-badge"
                                            :class="
                                                stockStatus(product)
                                                    .className
                                            "
                                        >
                                            {{
                                                stockStatus(product)
                                                    .label
                                            }}
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <span
                                        class="status-badge"
                                        :class="product.status"
                                    >
                                        <span></span>

                                        {{
                                            product.status ===
                                            'active'
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </span>
                                </td>

                                <td class="action-column">
                                    <div class="row-actions">
                                        <button
                                            type="button"
                                            class="icon-action"
                                            title="Edit product"
                                            @click="
                                                editProduct(product)
                                            "
                                        >
                                            <svg
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M4 20h4l11-11-4-4L4 16v4Z"
                                                    stroke="currentColor"
                                                    stroke-width="1.8"
                                                    stroke-linejoin="round"
                                                />

                                                <path
                                                    d="m13.5 6.5 4 4"
                                                    stroke="currentColor"
                                                    stroke-width="1.8"
                                                />
                                            </svg>
                                        </button>

                                        <button
                                            type="button"
                                            class="icon-action"
                                            title="View barcode"
                                            @click="
                                                openBarcode(product)
                                            "
                                        >
                                            <svg
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M4 5v14M7 5v14M11 5v14M14 5v14M18 5v14M20 5v14"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                />
                                            </svg>
                                        </button>

                                        <button
                                            type="button"
                                            class="icon-action"
                                            title="Print label"
                                            @click="
                                                openSingleLabel(product)
                                            "
                                        >
                                            <svg
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M6 9V4h12v5M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                    stroke-linecap="round"
                                                />

                                                <path
                                                    d="M6 14h12v7H6z"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                />
                                            </svg>
                                        </button>

                                        <button
                                            type="button"
                                            class="icon-action danger"
                                            title="Delete product"
                                            :disabled="
                                                deletingId ===
                                                product.id
                                            "
                                            @click="
                                                deleteProduct(product)
                                            "
                                        >
                                            <span
                                                v-if="
                                                    deletingId ===
                                                    product.id
                                                "
                                                class="mini-loader"
                                            ></span>

                                            <svg
                                                v-else
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M4 7h16M9 7V4h6v3M8 10v7M12 10v7M16 10v7M6 7l1 14h10l1-14"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <ProductForm
            v-model="showForm"
            :product="selectedProduct"
            :processing="saving"
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

.page-actions {
    display: flex;
    align-items: center;
    gap: 10px;
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
    box-shadow: 0 6px 15px rgba(36, 87, 214, 0.2);
}

.primary-action:hover {
    background: #1d49bb;
    border-color: #1d49bb;
}

.secondary-action {
    color: #35435b;
    background: #ffffff;
    border: 1px solid #d9e0ea;
}

.secondary-action:hover {
    background: #f5f7fa;
}

.primary-action svg,
.secondary-action svg {
    width: 17px;
    height: 17px;
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
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 17px;
    background: #ffffff;
    border: 1px solid #e1e7f0;
    border-radius: 13px;
    box-shadow: 0 5px 18px rgba(25, 49, 83, 0.04);
}

.summary-icon {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border-radius: 11px;
}

.summary-icon svg {
    width: 21px;
    height: 21px;
}

.summary-icon.blue {
    color: #2457d6;
    background: #eaf0ff;
}

.summary-icon.green {
    color: #168757;
    background: #e9f8f0;
}

.summary-icon.amber {
    color: #a87512;
    background: #fff6dc;
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
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 18px 20px;
    border-bottom: 1px solid #e8edf3;
}

.search-box {
    width: min(440px, 100%);
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

.search-box input {
    width: 100%;
    min-width: 0;
    padding: 10px 0;
    color: #1b2840;
    background: transparent;
    border: 0;
    outline: none;
    font-size: 12px;
}

.search-box input::placeholder {
    color: #9aa4b4;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 9px;
}

.filter-group select {
    min-width: 130px;
    min-height: 42px;
    padding: 9px 11px;
    color: #344159;
    background: #ffffff;
    border: 1px solid #dce3ec;
    border-radius: 9px;
    outline: none;
    font-size: 12px;
}

.clear-filter {
    min-height: 42px;
    padding: 8px 12px;
    color: #d03b45;
    background: #fff4f5;
    border: 1px solid #ffd7da;
    border-radius: 9px;
    font-size: 11px;
    font-weight: 700;
}

.listing-information {
    display: flex;
    justify-content: space-between;
    padding: 14px 20px;
    background: #fbfcfe;
    border-bottom: 1px solid #edf1f5;
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

.listing-information span {
    color: #8490a2;
    font-size: 10px;
}

.table-wrapper {
    overflow-x: auto;
}

.product-table {
    width: 100%;
    border-collapse: collapse;
}

.product-table th {
    padding: 13px 16px;
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
    padding: 15px 16px;
    color: #27344c;
    border-bottom: 1px solid #edf1f5;
    vertical-align: middle;
    font-size: 12px;
}

.product-table tbody tr:hover {
    background: #fbfcff;
}

.product-table tbody tr:last-child td {
    border-bottom: 0;
}

.product-information {
    min-width: 220px;
    display: flex;
    align-items: center;
    gap: 11px;
}

.product-avatar {
    width: 38px;
    height: 38px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    color: #2457d6;
    background: #eaf0ff;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 800;
}

.product-information strong,
.product-information span,
.product-information small,
.code-information strong,
.code-information span,
.tax-information strong,
.price-information strong,
.price-information span {
    display: block;
}

.product-information strong {
    margin-bottom: 3px;
    color: #1b2840;
    font-size: 12px;
    font-weight: 750;
}

.product-information span {
    margin-bottom: 2px;
    color: #748097;
    font-size: 10px;
}

.product-information small {
    color: #9aa4b3;
    font-size: 9px;
}

.code-information {
    min-width: 140px;
}

.code-information strong {
    margin-bottom: 4px;
    color: #2b3850;
    font-size: 11px;
    font-weight: 700;
}

.code-information span {
    color: #8590a2;
    font-size: 10px;
}

.tax-information {
    min-width: 115px;
}

.tax-information strong {
    margin-bottom: 5px;
    color: #2a3750;
    font-size: 11px;
}

.gst-badge {
    display: inline-flex;
    padding: 4px 7px;
    color: #2457d6;
    background: #edf2ff;
    border-radius: 6px;
    font-size: 9px;
    font-weight: 750;
}

.price-information {
    min-width: 110px;
}

.price-information strong {
    margin-bottom: 4px;
    color: #17243d;
    font-size: 12px;
}

.price-information span {
    color: #8994a6;
    font-size: 9px;
}

.stock-information {
    min-width: 100px;
}

.stock-information strong {
    display: block;
    margin-bottom: 5px;
    color: #253149;
    font-size: 12px;
}

.stock-badge {
    display: inline-flex;
    padding: 4px 7px;
    border-radius: 6px;
    font-size: 9px;
    font-weight: 750;
}

.stock-badge.available {
    color: #168757;
    background: #eaf8f1;
}

.stock-badge.low {
    color: #9b6a0c;
    background: #fff4d4;
}

.stock-badge.out {
    color: #ce3d48;
    background: #fff0f1;
}

.stock-badge.service {
    color: #5f6b80;
    background: #f0f2f6;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 8px;
    border-radius: 7px;
    font-size: 9px;
    font-weight: 750;
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
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
}

.icon-action {
    width: 32px;
    height: 32px;
    display: grid;
    place-items: center;
    padding: 0;
    color: #536179;
    background: #ffffff;
    border: 1px solid #dce3ec;
    border-radius: 8px;
    cursor: pointer;
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

.icon-action svg {
    width: 16px;
    height: 16px;
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

.mini-loader {
    width: 14px;
    height: 14px;
    border: 2px solid #f2bfc3;
    border-top-color: #d23f49;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
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

.loading-state span {
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
    color: #7f8b9e;
    font-size: 11px;
    line-height: 1.6;
}

@media (max-width: 1100px) {
    .listing-toolbar {
        align-items: stretch;
        flex-direction: column;
    }

    .search-box {
        width: 100%;
    }

    .filter-group {
        flex-wrap: wrap;
    }
}

@media (max-width: 767px) {
    .page-heading {
        align-items: stretch;
        flex-direction: column;
    }

    .page-heading h1 {
        font-size: 23px;
    }

    .page-actions {
        width: 100%;
    }

    .primary-action,
    .secondary-action {
        flex: 1;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }

    .listing-toolbar,
    .listing-information {
        padding-left: 14px;
        padding-right: 14px;
    }

    .filter-group select {
        flex: 1;
        min-width: 120px;
    }

    .product-table th,
    .product-table td {
        padding-left: 12px;
        padding-right: 12px;
    }
}
</style>