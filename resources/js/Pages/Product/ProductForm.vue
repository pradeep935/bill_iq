<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Form } from 'vee-validate';
import ProductApi from './ProductApi';

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },

    product: {
        type: Object,
        default: () => ({}),
    },

    processing: {
        type: Boolean,
        default: false,
    },

    canEditGstRate: {
        type: Boolean,
        default: false,
    },

    errors: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits([
    'update:modelValue',
    'save',
]);

const initialForm = () => ({
    id: '',

    name: '',
    product_type: 'goods',
    item_type: 'stock',
    short_name: '',
    category: '',
    subcategory: '',
    brand: '',
    variant: '',
    unit: 'PCS',
    description: '',

    sku: '',
    primary_barcode: '',

    hsn_master_id: '',
    hsn_code: '',
    taxability: 'taxable',
    gst_rate: '0',
    cess_rate: '0',
    reverse_charge: 'no',
    tax_inclusive: false,
    invoice_description: '',

    cost_price: '',
    selling_price: '',
    mrp: '',
    wholesale_price: '',
    dealer_price: '',
    online_price: '',

    minimum_stock: '0',
    reorder_stock: '0',
    maximum_stock: '0',
    tracking_type: 'none',

    weight: '',
    length: '',
    width: '',
    height: '',
    batch_required: false,
    expiry_required: false,
    serial_required: false,
    status: 'active',
});

const form = reactive(initialForm());
const activeTab = ref('basic');
const barcodes = ref([]);
const images = ref([]);
const hsnSearch = ref('');
const hsnResults = ref([]);
const hsnSearching = ref(false);
const clientErrors = ref({});

const productTabs = computed(() => [
    { key: 'basic', label: 'Basic' },
    { key: 'pricing', label: 'Pricing' },
    { key: 'gst', label: 'GST' },
    { key: 'inventory', label: 'Inventory' },
    { key: 'barcodes', label: 'Barcodes' },
    { key: 'images', label: 'Images' },
    { key: 'advanced', label: 'Advanced' },
].filter((tab) => {
    return form.product_type === 'goods' || tab.key !== 'inventory';
}));

const drawerTitle = computed(() => {
    return form.id ? 'Edit Product' : 'Add New Product';
});

const drawerDescription = computed(() => {
    return form.id
        ? 'Update product, taxation, pricing and inventory details.'
        : 'Create a new product with GST, barcode and stock settings.';
});

const productTypeOptions = [
    {
        value: 'goods',
        label: 'Goods / Physical Product',
    },
    {
        value: 'service',
        label: 'Service',
    },
];

const itemTypeOptions = [
    { value: 'stock', label: 'Stock Item' },
    { value: 'non_stock', label: 'Non Stock Item' },
];

const unitOptions = [
    { value: 'PCS', label: 'Pieces (PCS)' },
    { value: 'NOS', label: 'Numbers (NOS)' },
    { value: 'BOX', label: 'Box' },
    { value: 'PKT', label: 'Packet' },
    { value: 'SET', label: 'Set' },
    { value: 'PAIR', label: 'Pair' },
    { value: 'KG', label: 'Kilogram (KG)' },
    { value: 'GM', label: 'Gram (GM)' },
    { value: 'LTR', label: 'Litre (LTR)' },
    { value: 'ML', label: 'Millilitre (ML)' },
    { value: 'MTR', label: 'Meter (MTR)' },
    { value: 'HRS', label: 'Hours' },
];

const taxabilityOptions = [
    { value: 'taxable', label: 'Taxable' },
    { value: 'exempt', label: 'Exempt' },
    { value: 'nil_rated', label: 'Nil Rated' },
    { value: 'non_gst', label: 'Non-GST Supply' },
];

const gstRateOptions = [
    { value: '0', label: '0%' },
    { value: '0.1', label: '0.1%' },
    { value: '0.25', label: '0.25%' },
    { value: '1.5', label: '1.5%' },
    { value: '3', label: '3%' },
    { value: '5', label: '5%' },
    { value: '6', label: '6%' },
    { value: '12', label: '12%' },
    { value: '18', label: '18%' },
    { value: '28', label: '28%' },
];

const reverseChargeOptions = [
    { value: 'no', label: 'No' },
    { value: 'yes', label: 'Yes' },
];

const trackingOptions = [
    { value: 'none', label: 'Normal Stock Tracking' },
    { value: 'batch', label: 'Batch Tracking' },
    {
        value: 'batch_expiry',
        label: 'Batch and Expiry Tracking',
    },
    { value: 'serial', label: 'Serial Number Tracking' },
    { value: 'imei', label: 'IMEI Tracking' },
];

const statusOptions = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

const fillForm = (product = {}) => {
    Object.assign(form, initialForm(), {
        id: product?.id || '',

        name: product?.name || '',
        product_type: product?.product_type || 'goods',
        item_type: product?.item_type || 'stock',
        short_name: product?.short_name || '',
        category: product?.category || '',
        subcategory: product?.subcategory || '',
        brand: product?.brand || '',
        variant: product?.variant || '',
        unit: product?.unit || 'PCS',
        description: product?.description || '',

        sku: product?.sku || '',
        primary_barcode: product?.primary_barcode || '',

        hsn_master_id: product?.hsn_master_id || '',
        hsn_code: product?.hsn_code || '',
        taxability: product?.taxability || 'taxable',
        gst_rate: String(product?.gst_rate ?? '0'),
        cess_rate: String(product?.cess_rate ?? '0'),
        reverse_charge: product?.reverse_charge || 'no',
        tax_inclusive: Boolean(product?.tax_inclusive),
        invoice_description:
            product?.invoice_description || '',

        cost_price: product?.cost_price ?? '',
        selling_price: product?.selling_price ?? '',
        mrp: product?.mrp ?? '',
        wholesale_price: product?.wholesale_price ?? '',
        dealer_price: product?.dealer_price ?? '',
        online_price: product?.online_price ?? '',

        minimum_stock: product?.minimum_stock ?? '0',
        reorder_stock: product?.reorder_stock ?? '0',
        maximum_stock: product?.maximum_stock ?? '0',
        tracking_type: product?.tracking_type || 'none',

        weight: product?.weight ?? '',
        length: product?.length ?? '',
        width: product?.width ?? '',
        height: product?.height ?? '',
        batch_required: Boolean(product?.batch_required),
        expiry_required: Boolean(product?.expiry_required),
        serial_required: Boolean(product?.serial_required),
        status: product?.status || 'active',
    });

    barcodes.value = normalizeBarcodes(product);
    images.value = normalizeImages(product);
    hsnSearch.value = product?.hsn_code || '';
    hsnResults.value = [];
    clientErrors.value = {};
};

const normalizeBarcodes = (product = {}) => {
    const rows = Array.isArray(product?.barcodes)
        ? product.barcodes.map((barcode) => ({
              barcode: barcode?.barcode || '',
              barcode_type: barcode?.barcode_type || 'alternate',
              is_primary: Boolean(barcode?.is_primary),
          }))
        : [];

    if (product?.primary_barcode) {
        const existingPrimary = rows.some(
            (row) => row.barcode === product.primary_barcode
        );

        if (!existingPrimary) {
            rows.unshift({
                barcode: product.primary_barcode,
                barcode_type: 'primary',
                is_primary: true,
            });
        }
    }

    return rows.length
        ? rows
        : [
              {
                  barcode: '',
                  barcode_type: 'primary',
                  is_primary: true,
              },
          ];
};

const normalizeImages = (product = {}) => {
    const rows = Array.isArray(product?.images)
        ? product.images.map((image, index) => ({
              image_path:
                  typeof image === 'string'
                      ? image
                      : image?.image_path || '',
              image_type:
                  typeof image === 'string'
                      ? 'gallery'
                      : image?.image_type || 'gallery',
              sort_order:
                  typeof image === 'string'
                      ? index
                      : image?.sort_order ?? index,
              is_primary:
                  typeof image === 'string'
                      ? index === 0
                      : Boolean(image?.is_primary),
          }))
        : [];

    return rows.length
        ? rows
        : [
              {
                  image_path: '',
                  image_type: 'gallery',
                  sort_order: 0,
                  is_primary: true,
              },
          ];
};

watch(
    () => props.product,
    (product) => {
        fillForm(product);
    },
    {
        immediate: true,
        deep: true,
    }
);

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            fillForm(props.product);
            activeTab.value = 'basic';
            document.body.classList.add('product-drawer-open');
        } else {
            document.body.classList.remove('product-drawer-open');
        }
    }
);

watch(
    () => form.product_type,
    (productType) => {
        if (productType === 'service' && activeTab.value === 'inventory') {
            activeTab.value = 'basic';
        }
    }
);

const closeDrawer = () => {
    if (props.processing) {
        return;
    }

    emit('update:modelValue', false);
};

const fieldError = (field) => {
    return (
        clientErrors.value[field] ||
        props.errors?.[field]?.[0] ||
        ''
    );
};

const allErrors = computed(() => {
    return [
        ...Object.values(clientErrors.value),
        ...Object.values(props.errors || {})
            .map((value) => value?.[0])
            .filter(Boolean),
    ];
});

const setPrimaryBarcode = (index) => {
    barcodes.value = barcodes.value.map((barcode, barcodeIndex) => ({
        ...barcode,
        is_primary: barcodeIndex === index,
        barcode_type:
            barcodeIndex === index
                ? 'primary'
                : barcode.barcode_type === 'primary'
                  ? 'alternate'
                  : barcode.barcode_type,
    }));
};

const addBarcode = () => {
    barcodes.value.push({
        barcode: '',
        barcode_type: 'alternate',
        is_primary: false,
    });
};

const removeBarcode = (index) => {
    if (barcodes.value.length === 1) {
        barcodes.value = [
            {
                barcode: '',
                barcode_type: 'primary',
                is_primary: true,
            },
        ];

        return;
    }

    const wasPrimary = barcodes.value[index]?.is_primary;
    barcodes.value.splice(index, 1);

    if (wasPrimary && barcodes.value.length) {
        setPrimaryBarcode(0);
    }
};

const addImage = () => {
    images.value.push({
        image_path: '',
        image_type: 'gallery',
        sort_order: images.value.length,
        is_primary: false,
    });
};

const removeImage = (index) => {
    images.value.splice(index, 1);

    if (!images.value.length) {
        addImage();
    }
};

const searchHsn = async () => {
    const keyword = hsnSearch.value.trim();
    form.hsn_code = keyword;
    form.hsn_master_id = '';

    if (keyword.length < 2) {
        hsnResults.value = [];

        return;
    }

    hsnSearching.value = true;

    try {
        hsnResults.value = await ProductApi.searchHsn(keyword);
    } finally {
        hsnSearching.value = false;
    }
};

const selectHsn = (hsn) => {
    form.hsn_master_id = hsn.id;
    form.hsn_code = hsn.hsn_code;
    hsnSearch.value = hsn.hsn_code;
    form.cess_rate = String(hsn.cess_rate ?? '0');
    form.gst_rate = String(hsn.gst_rate ?? '0');

    hsnResults.value = [];
};

const validateBeforeSave = () => {
    const errors = {};

    if (
        form.mrp !== '' &&
        Number(form.mrp) > 0 &&
        Number(form.selling_price || 0) > Number(form.mrp)
    ) {
        errors.selling_price =
            'Selling price cannot be greater than MRP.';
        activeTab.value = 'pricing';
    }

    if (!form.hsn_code) {
        errors.hsn_code = 'HSN code is required.';
        activeTab.value = 'gst';
    }

    const filledBarcodes = barcodes.value
        .map((barcode) => barcode.barcode.trim())
        .filter(Boolean);
    const uniqueBarcodes = new Set(filledBarcodes);

    if (filledBarcodes.length !== uniqueBarcodes.size) {
        errors.barcodes = 'Duplicate barcodes are not allowed.';
        activeTab.value = 'barcodes';
    }

    clientErrors.value = errors;

    return !Object.keys(errors).length;
};

const saveProduct = () => {
    if (props.processing || !validateBeforeSave()) {
        return;
    }

    const barcodeRows = barcodes.value
        .map((barcode) => ({
            barcode: barcode.barcode.trim(),
            barcode_type: barcode.barcode_type || 'alternate',
            is_primary: Boolean(barcode.is_primary),
        }))
        .filter((barcode) => barcode.barcode);
    const primaryBarcode =
        barcodeRows.find((barcode) => barcode.is_primary)
            ?.barcode ||
        barcodeRows[0]?.barcode ||
        '';
    const imageRows = images.value
        .map((image, index) => ({
            image_path: image.image_path.trim(),
            image_type: image.image_type || 'gallery',
            sort_order: image.sort_order || index,
            is_primary: Boolean(image.is_primary),
        }))
        .filter((image) => image.image_path);
    const priceRows = [
        { price_type: 'Retail', price: form.selling_price || 0 },
        {
            price_type: 'Wholesale',
            price: form.wholesale_price || 0,
        },
        { price_type: 'Dealer', price: form.dealer_price || 0 },
        { price_type: 'Online', price: form.online_price || 0 },
    ];

    emit('save', {
        ...form,

        cost_price: form.cost_price || 0,
        selling_price: form.selling_price || 0,
        mrp: form.mrp || null,
        wholesale_price: form.wholesale_price || 0,
        dealer_price: form.dealer_price || 0,
        online_price: form.online_price || 0,

        primary_barcode: primaryBarcode,
        extra_barcodes: barcodeRows
            .filter((barcode) => !barcode.is_primary)
            .map((barcode) => barcode.barcode)
            .join(','),
        opening_stock: 0,

        minimum_stock:
            form.product_type === 'goods'
                ? form.minimum_stock || 0
                : 0,

        reorder_stock:
            form.product_type === 'goods'
                ? form.reorder_stock || 0
                : 0,
        maximum_stock:
            form.product_type === 'goods'
                ? form.maximum_stock || 0
                : 0,

        tracking_type:
            form.product_type === 'goods'
                ? form.tracking_type
                : 'none',
        tax_inclusive: Boolean(form.tax_inclusive),
        batch_required: Boolean(form.batch_required),
        expiry_required: Boolean(form.expiry_required),
        serial_required: Boolean(form.serial_required),
        images: imageRows,
        barcodes: barcodeRows,
        prices: priceRows,
        batches: [],
    });
};
</script>

<template>
    <Teleport to="body">
        <Transition name="product-drawer">
            <div
                v-if="modelValue"
                class="product-drawer-wrapper"
            >
                <div
                    class="product-drawer-backdrop"
                    @click="closeDrawer"
                ></div>

                <aside class="product-drawer-panel">
                    <!-- Header -->
                    <header class="product-drawer-header">
                        <div class="drawer-heading">
                            <div class="drawer-heading-icon">
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    aria-hidden="true"
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
                                <span class="drawer-eyebrow">
                                    PRODUCT MASTER
                                </span>

                                <h2>{{ drawerTitle }}</h2>

                                <p>{{ drawerDescription }}</p>
                            </div>
                        </div>

                        <Button2
                            cls="drawer-close-button"
                            @clickFn="closeDrawer"
                        >
                            <span aria-hidden="true">x</span>
                        </Button2>
                    </header>

                    <Form
                        class="product-form"
                        @submit="saveProduct"
                    >
                        <nav class="product-tabs">
                            <button
                                v-for="tab in productTabs"
                                :key="tab.key"
                                type="button"
                                :class="{ active: activeTab === tab.key }"
                                @click="activeTab = tab.key"
                            >
                                {{ tab.label }}
                            </button>
                        </nav>

                        <div
                            v-if="allErrors.length"
                            class="form-error-summary"
                        >
                            <strong>Please check these fields</strong>

                            <span
                                v-for="(error, index) in allErrors"
                                :key="index"
                            >
                                {{ error }}
                            </span>
                        </div>

                        <main class="product-drawer-content">

                            <!-- Basic details -->
                            <section
                                v-show="activeTab === 'basic'"
                                class="product-section"
                            >
                                <div class="section-header">
                                    <div class="section-number">
                                        01
                                    </div>

                                    <div>
                                        <h3>Basic Information</h3>

                                        <p>
                                            Product name, type, category and
                                            unit details.
                                        </p>
                                    </div>
                                </div>

                                <div class="form-grid">
                                    <FormInput
                                        v-model="form.name"
                                        name="name"
                                        label="Product Name"
                                        placeholder="Example: Samsung Galaxy S25"
                                        cls="product-field field-span-2"
                                        :req="true"
                                    />

                                    <FormInput
                                        v-model="form.short_name"
                                        name="short_name"
                                        label="Short Name"
                                        placeholder="Invoice display name"
                                        cls="product-field"
                                    />

                                    <FormSelect
                                        v-model="form.product_type"
                                        name="product_type"
                                        label="Product Type"
                                        cls="product-field"
                                        :options="productTypeOptions"
                                        select_name="Select product type"
                                        :req="true"
                                    />

                                    <FormSelect
                                        v-model="form.item_type"
                                        name="item_type"
                                        label="Item Type"
                                        cls="product-field"
                                        :options="itemTypeOptions"
                                        select_name="Select item type"
                                        :req="true"
                                    />

                                    <FormSelect
                                        v-model="form.unit"
                                        name="unit"
                                        label="Unit"
                                        cls="product-field"
                                        :options="unitOptions"
                                        select_name="Select unit"
                                        :req="true"
                                    />

                                    <FormInput
                                        v-model="form.sku"
                                        name="sku_basic"
                                        label="SKU"
                                        placeholder="Example: SG25-256-BLK"
                                        cls="product-field"
                                        :req="true"
                                    />

                                    <FormInput
                                        v-model="form.primary_barcode"
                                        name="barcode_basic"
                                        label="Barcode"
                                        placeholder="Scan or enter barcode"
                                        cls="product-field"
                                    />

                                    <FormInput
                                        v-model="form.category"
                                        name="category"
                                        label="Category"
                                        placeholder="Example: Smartphones"
                                        cls="product-field"
                                    />

                                    <FormInput
                                        v-model="form.subcategory"
                                        name="subcategory"
                                        label="Sub Category"
                                        placeholder="Example: Android Phones"
                                        cls="product-field"
                                    />

                                    <FormInput
                                        v-model="form.brand"
                                        name="brand"
                                        label="Brand"
                                        placeholder="Example: Samsung"
                                        cls="product-field"
                                    />

                                    <FormInput
                                        v-model="form.variant"
                                        name="variant"
                                        label="Variant"
                                        placeholder="Example: 256GB / Black"
                                        cls="product-field field-span-2"
                                    />

                                    <FormText
                                        v-model="form.description"
                                        name="description"
                                        label="Description"
                                        placeholder="Internal product description"
                                        cls="product-field field-span-2"
                                        :rows="3"
                                    />
                                </div>
                            </section>

                            <!-- SKU and barcode -->
                            <section
                                v-show="activeTab === 'barcodes'"
                                class="product-section"
                            >
                                <div class="section-header">
                                    <div class="section-number">
                                        02
                                    </div>

                                    <div>
                                        <h3>SKU & Barcode</h3>

                                        <p>
                                            Add internal SKU and scanner-ready
                                            barcode details.
                                        </p>
                                    </div>
                                </div>

                                <div class="repeat-list">
                                    <div
                                        v-for="(barcode, index) in barcodes"
                                        :key="index"
                                        class="repeat-row barcode-row"
                                    >
                                        <label class="radio-field">
                                            <input
                                                type="radio"
                                                :checked="barcode.is_primary"
                                                @change="setPrimaryBarcode(index)"
                                            />

                                            <span>Primary</span>
                                        </label>

                                        <input
                                            v-model="barcode.barcode"
                                            type="text"
                                            class="form-control"
                                            placeholder="Scan or enter barcode"
                                        />

                                        <select
                                            v-model="barcode.barcode_type"
                                            class="form-control"
                                        >
                                            <option value="primary">
                                                Primary
                                            </option>

                                            <option value="alternate">
                                                Alternate
                                            </option>

                                            <option value="manufacturer">
                                                Manufacturer
                                            </option>

                                            <option value="internal">
                                                Internal
                                            </option>
                                        </select>

                                        <button
                                            type="button"
                                            class="row-remove"
                                            :disabled="processing"
                                            @click="removeBarcode(index)"
                                        >
                                            Remove
                                        </button>
                                    </div>

                                    <button
                                        type="button"
                                        class="row-add"
                                        :disabled="processing"
                                        @click="addBarcode"
                                    >
                                        Add Barcode
                                    </button>

                                    <div
                                        v-if="fieldError('barcodes')"
                                        class="field-error"
                                    >
                                        {{ fieldError('barcodes') }}
                                    </div>

                                    <div class="field-help">
                                        <span class="help-icon">i</span>

                                        <span>
                                            Primary barcode unique hona chahiye.
                                            Additional barcode rows add/remove
                                            kar sakte hain.
                                        </span>
                                    </div>
                                </div>
                            </section>

                            <!-- GST and HSN -->
                            <section
                                v-show="activeTab === 'gst'"
                                class="product-section"
                            >
                                <div class="section-header">
                                    <div class="section-number">
                                        03
                                    </div>

                                    <div>
                                        <h3>GST & HSN Details</h3>

                                        <p>
                                            Product tax classification and
                                            invoice taxation settings.
                                        </p>
                                    </div>
                                </div>

                                <div class="form-grid">
                                    <div class="product-field hsn-search-field">
                                        <label>
                                            HSN / SAC Code
                                            <span class="text-danger">*</span>
                                        </label>

                                        <div class="hsn-input-row">
                                            <input
                                                v-model="hsnSearch"
                                                type="text"
                                                class="form-control"
                                                placeholder="Search HSN by code or description"
                                                @input="searchHsn"
                                            />

                                            <span
                                                v-if="hsnSearching"
                                                class="inline-loader"
                                            ></span>
                                        </div>

                                        <div
                                            v-if="hsnResults.length"
                                            class="hsn-results"
                                        >
                                            <button
                                                v-for="hsn in hsnResults"
                                                :key="hsn.id"
                                                type="button"
                                                @click="selectHsn(hsn)"
                                            >
                                                <strong>
                                                    {{ hsn.hsn_code }}
                                                </strong>

                                                <span>
                                                    {{ hsn.description }}
                                                </span>

                                                <small>
                                                    {{ Number(hsn.gst_rate || 0) }}%
                                                    GST
                                                </small>
                                            </button>
                                        </div>

                                        <input
                                            v-model="form.hsn_code"
                                            type="text"
                                            class="form-control selected-hsn"
                                            placeholder="Selected HSN code"
                                        />

                                        <span
                                            v-if="fieldError('hsn_code')"
                                            class="field-error"
                                        >
                                            {{ fieldError('hsn_code') }}
                                        </span>
                                    </div>

                                    <FormSelect
                                        v-model="form.taxability"
                                        name="taxability"
                                        label="Taxability"
                                        cls="product-field"
                                        :options="taxabilityOptions"
                                        select_name="Select taxability"
                                        :req="true"
                                    />

                                    <FormSelect
                                        v-model="form.gst_rate"
                                        name="gst_rate"
                                        label="GST Rate"
                                        cls="product-field"
                                        :options="gstRateOptions"
                                        select_name="Select GST rate"
                                        :disabled="!canEditGstRate"
                                        :req="true"
                                    />

                                    <div
                                        v-if="!canEditGstRate"
                                        class="field-help"
                                    >
                                        <span class="help-icon">i</span>

                                        <span>
                                            GST rate HSN Master se auto-fill
                                            hota hai.
                                        </span>
                                    </div>

                                    <FormInput
                                        v-model="form.cess_rate"
                                        name="cess_rate"
                                        type="number"
                                        label="Cess Rate"
                                        placeholder="0"
                                        cls="product-field"
                                        right_box_text="%"
                                    />

                                    <FormSelect
                                        v-model="form.reverse_charge"
                                        name="reverse_charge"
                                        label="Reverse Charge"
                                        cls="product-field"
                                        :options="reverseChargeOptions"
                                        select_name="Select option"
                                        :req="true"
                                    />

                                    <label class="toggle-field">
                                        <input
                                            v-model="form.tax_inclusive"
                                            type="checkbox"
                                        />

                                        <span>
                                            Tax Inclusive Pricing
                                        </span>
                                    </label>

                                    <div class="product-field tax-summary">
                                        <span>Tax Preview</span>

                                        <strong>
                                            {{ form.gst_rate || 0 }}% GST
                                            <template
                                                v-if="
                                                    Number(form.cess_rate) > 0
                                                "
                                            >
                                                +
                                                {{ form.cess_rate }}% Cess
                                            </template>
                                        </strong>
                                    </div>

                                    <FormText
                                        v-model="form.invoice_description"
                                        name="invoice_description"
                                        label="Invoice Description"
                                        placeholder="Description displayed on customer invoice"
                                        cls="product-field field-span-2"
                                        :rows="3"
                                    />
                                </div>
                            </section>

                            <!-- Pricing -->
                            <section
                                v-show="activeTab === 'pricing'"
                                class="product-section"
                            >
                                <div class="section-header">
                                    <div class="section-number">
                                        04
                                    </div>

                                    <div>
                                        <h3>Pricing Details</h3>

                                        <p>
                                            Configure purchase cost, selling
                                            price and printed MRP.
                                        </p>
                                    </div>
                                </div>

                                <div class="form-grid pricing-grid">
                                    <FormInput
                                        v-model="form.cost_price"
                                        name="cost_price"
                                        type="number"
                                        label="Cost Price"
                                        placeholder="0.00"
                                        cls="product-field"
                                        left_box_text="Rs."
                                    />

                                    <FormInput
                                        v-model="form.selling_price"
                                        name="selling_price"
                                        type="number"
                                        label="Selling Price"
                                        placeholder="0.00"
                                        cls="product-field"
                                        left_box_text="Rs."
                                        :req="true"
                                    />

                                    <div
                                        v-if="fieldError('selling_price')"
                                        class="field-error"
                                    >
                                        {{ fieldError('selling_price') }}
                                    </div>

                                    <FormInput
                                        v-model="form.mrp"
                                        name="mrp"
                                        type="number"
                                        label="MRP"
                                        placeholder="0.00"
                                        cls="product-field"
                                        left_box_text="Rs."
                                    />

                                    <FormInput
                                        v-model="form.wholesale_price"
                                        name="wholesale_price"
                                        type="number"
                                        label="Wholesale Price"
                                        placeholder="0.00"
                                        cls="product-field"
                                        left_box_text="Rs."
                                    />

                                    <FormInput
                                        v-model="form.dealer_price"
                                        name="dealer_price"
                                        type="number"
                                        label="Dealer Price"
                                        placeholder="0.00"
                                        cls="product-field"
                                        left_box_text="Rs."
                                    />

                                    <FormInput
                                        v-model="form.online_price"
                                        name="online_price"
                                        type="number"
                                        label="Online Price"
                                        placeholder="0.00"
                                        cls="product-field"
                                        left_box_text="Rs."
                                    />

                                    <div class="pricing-rule">
                                        <div class="pricing-rule-icon">
                                            !
                                        </div>

                                        <div>
                                            <strong>MRP validation</strong>

                                            <span>
                                                Selling price MRP se zyada
                                                nahi honi chahiye.
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <!-- Inventory -->
                            <section
                                v-show="
                                    activeTab === 'inventory' &&
                                    form.product_type === 'goods'
                                "
                                class="product-section"
                            >
                                <div class="section-header">
                                    <div class="section-number">
                                        05
                                    </div>

                                    <div>
                                        <h3>Inventory Settings</h3>

                                        <p>
                                            Stock alerts and product tracking
                                            settings.
                                        </p>
                                    </div>
                                </div>

                                <div class="form-grid">
                                    <FormInput
                                        v-model="form.minimum_stock"
                                        name="minimum_stock"
                                        type="number"
                                        label="Minimum Stock"
                                        placeholder="0"
                                        cls="product-field"
                                    />

                                    <FormInput
                                        v-model="form.reorder_stock"
                                        name="reorder_stock"
                                        type="number"
                                        label="Reorder Stock"
                                        placeholder="0"
                                        cls="product-field"
                                    />

                                    <FormInput
                                        v-model="form.maximum_stock"
                                        name="maximum_stock"
                                        type="number"
                                        label="Maximum Stock"
                                        placeholder="0"
                                        cls="product-field"
                                    />

                                    <FormSelect
                                        v-model="form.tracking_type"
                                        name="tracking_type"
                                        label="Tracking Method"
                                        cls="product-field"
                                        :options="trackingOptions"
                                        select_name="Select tracking"
                                        :req="true"
                                    />

                                    <div class="inventory-explanation field-span-2">
                                        <div>
                                            <strong>Opening Stock</strong>

                                            <span>
                                                Opening stock Product Master
                                                se edit nahi hota; opening
                                                stock transaction se maintain
                                                karein.
                                            </span>
                                        </div>

                                        <div>
                                            <strong>Minimum Stock</strong>

                                            <span>
                                                Stock is level se neeche jaane
                                                par low-stock alert milega.
                                            </span>
                                        </div>

                                        <div>
                                            <strong>Reorder Stock</strong>

                                            <span>
                                                Recommended quantity jo stock
                                                replenish karte waqt order
                                                karni hai.
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <!-- Images -->
                            <section
                                v-show="activeTab === 'images'"
                                class="product-section"
                            >
                                <div class="section-header">
                                    <div class="section-number">
                                        07
                                    </div>

                                    <div>
                                        <h3>Images</h3>

                                        <p>
                                            Store primary and additional image
                                            paths for catalog and POS.
                                        </p>
                                    </div>
                                </div>

                                <div class="repeat-list">
                                    <div
                                        v-for="(image, index) in images"
                                        :key="index"
                                        class="repeat-row image-row"
                                    >
                                        <label class="radio-field">
                                            <input
                                                v-model="image.is_primary"
                                                type="checkbox"
                                            />

                                            <span>Primary</span>
                                        </label>

                                        <input
                                            v-model="image.image_path"
                                            type="text"
                                            class="form-control"
                                            placeholder="/uploads/product.jpg"
                                        />

                                        <select
                                            v-model="image.image_type"
                                            class="form-control"
                                        >
                                            <option value="gallery">
                                                Gallery
                                            </option>

                                            <option value="thumbnail">
                                                Thumbnail
                                            </option>

                                            <option value="catalog">
                                                Catalog
                                            </option>
                                        </select>

                                        <button
                                            type="button"
                                            class="row-remove"
                                            :disabled="processing"
                                            @click="removeImage(index)"
                                        >
                                            Remove
                                        </button>
                                    </div>

                                    <button
                                        type="button"
                                        class="row-add"
                                        :disabled="processing"
                                        @click="addImage"
                                    >
                                        Add Image
                                    </button>
                                </div>
                            </section>

                            <!-- Status -->
                            <section
                                v-show="activeTab === 'advanced'"
                                class="product-section"
                            >
                                <div class="section-header">
                                    <div class="section-number">
                                        {{
                                            form.product_type === 'goods'
                                                ? '06'
                                                : '05'
                                        }}
                                    </div>

                                    <div>
                                        <h3>Product Status</h3>

                                        <p>
                                            Control whether this product is
                                            available for billing.
                                        </p>
                                    </div>
                                </div>

                                <div class="form-grid">
                                    <FormInput
                                        v-model="form.weight"
                                        name="weight"
                                        type="number"
                                        label="Weight"
                                        placeholder="0.000"
                                        cls="product-field"
                                    />

                                    <FormInput
                                        v-model="form.length"
                                        name="length"
                                        type="number"
                                        label="Length"
                                        placeholder="0.000"
                                        cls="product-field"
                                    />

                                    <FormInput
                                        v-model="form.width"
                                        name="width"
                                        type="number"
                                        label="Width"
                                        placeholder="0.000"
                                        cls="product-field"
                                    />

                                    <FormInput
                                        v-model="form.height"
                                        name="height"
                                        type="number"
                                        label="Height"
                                        placeholder="0.000"
                                        cls="product-field"
                                    />

                                    <label class="toggle-field">
                                        <input
                                            v-model="form.batch_required"
                                            type="checkbox"
                                        />

                                        <span>Batch Required</span>
                                    </label>

                                    <label class="toggle-field">
                                        <input
                                            v-model="form.expiry_required"
                                            type="checkbox"
                                        />

                                        <span>Expiry Required</span>
                                    </label>

                                    <label class="toggle-field">
                                        <input
                                            v-model="form.serial_required"
                                            type="checkbox"
                                        />

                                        <span>Serial Number Required</span>
                                    </label>

                                    <FormSelect
                                        v-model="form.status"
                                        name="status"
                                        label="Status"
                                        cls="product-field"
                                        :options="statusOptions"
                                        select_name="Select status"
                                        :req="true"
                                    />

                                    <div
                                        class="status-preview"
                                        :class="{
                                            inactive:
                                                form.status === 'inactive',
                                        }"
                                    >
                                        <span class="status-dot"></span>

                                        <div>
                                            <strong>
                                                {{
                                                    form.status === 'active'
                                                        ? 'Active Product'
                                                        : 'Inactive Product'
                                                }}
                                            </strong>

                                            <small>
                                                {{
                                                    form.status === 'active'
                                                        ? 'Product can be selected during billing.'
                                                        : 'Product will not be available for new bills.'
                                                }}
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </main>

                        <!-- Footer -->
                        <footer class="product-drawer-footer">
                            <div class="footer-help">
                                Fields marked with
                                <span>*</span>
                                are required.
                            </div>

                            <div class="footer-actions">
                                <Button2
                                    cls="btn product-cancel-button"
                                    :disabled="processing"
                                    @clickFn="closeDrawer"
                                >
                                    Cancel
                                </Button2>

                                <FormButton
                                    cls="product-save-button"
                                    :processing="processing"
                                >
                                    <svg
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        aria-hidden="true"
                                    >
                                        <path
                                            d="m5 12 4 4L19 6"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                    </svg>

                                    {{
                                        form.id
                                            ? 'Update Product'
                                            : 'Save Product'
                                    }}
                                </FormButton>
                            </div>
                        </footer>
                    </Form>
                </aside>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.product-drawer-wrapper {
    position: fixed;
    inset: 0;
    z-index: 9999;
}

.product-drawer-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(5, 18, 38, 0.62);
    backdrop-filter: blur(3px);
}

.product-drawer-panel {
    position: absolute;
    top: 0;
    right: 0;
    width: min(960px, 100%);
    height: 100vh;
    display: flex;
    flex-direction: column;
    background: #f4f7fb;
    box-shadow: -24px 0 60px rgba(7, 25, 51, 0.22);
}

.product-drawer-header {
    min-height: 96px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    padding: 19px 28px;
    background: #ffffff;
    border-bottom: 1px solid #e3e9f2;
    z-index: 10;
}

.drawer-heading {
    display: flex;
    align-items: center;
    gap: 15px;
}

.drawer-heading-icon {
    width: 48px;
    height: 48px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    color: #2457d6;
    background: linear-gradient(
        145deg,
        #edf3ff,
        #dce7ff
    );
    border: 1px solid #d4e1ff;
    border-radius: 14px;
}

.drawer-heading-icon svg {
    width: 25px;
    height: 25px;
}

.drawer-eyebrow {
    display: block;
    margin-bottom: 2px;
    color: #2457d6;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.5px;
}

.drawer-heading h2 {
    margin: 0;
    color: #101c34;
    font-size: 22px;
    font-weight: 800;
    line-height: 1.25;
}

.drawer-heading p {
    margin: 4px 0 0;
    color: #738098;
    font-size: 12px;
}

:deep(.drawer-close-button) {
    width: 40px;
    height: 40px;
    display: grid;
    place-items: center;
    padding: 0 !important;
    color: #536078;
    background: #f4f6fa;
    border: 1px solid #dfe5ee;
    border-radius: 11px;
    font-size: 25px;
    font-weight: 300;
    line-height: 1;
}

:deep(.drawer-close-button:hover) {
    color: #d23b45;
    background: #fff0f1;
    border-color: #ffd4d7;
}

.product-form {
    min-height: 0;
    display: flex;
    flex: 1;
    flex-direction: column;
}

.product-tabs {
    display: flex;
    gap: 7px;
    padding: 12px 28px;
    overflow-x: auto;
    background: #ffffff;
    border-bottom: 1px solid #e3e9f2;
}

.product-tabs button {
    min-height: 34px;
    flex-shrink: 0;
    padding: 7px 13px;
    color: #5e6a7f;
    background: #f6f8fb;
    border: 1px solid #dfe6ef;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 750;
    cursor: pointer;
}

.product-tabs button.active {
    color: #ffffff;
    background: #2457d6;
    border-color: #2457d6;
}

.form-error-summary {
    display: grid;
    gap: 4px;
    margin: 12px 28px 0;
    padding: 11px 13px;
    color: #96333a;
    background: #fff3f4;
    border: 1px solid #ffd4d8;
    border-radius: 9px;
    font-size: 11px;
}

.form-error-summary strong {
    color: #7d2730;
    font-size: 12px;
}

.product-drawer-content {
    min-height: 0;
    flex: 1;
    padding: 22px 28px 30px;
    overflow-y: auto;
}

.product-section {
    margin-bottom: 18px;
    padding: 22px;
    background: #ffffff;
    border: 1px solid #e1e7f0;
    border-radius: 15px;
    box-shadow: 0 6px 20px rgba(27, 52, 87, 0.045);
}

.section-header {
    display: flex;
    align-items: flex-start;
    gap: 13px;
    margin-bottom: 21px;
    padding-bottom: 16px;
    border-bottom: 1px solid #edf1f6;
}

.section-number {
    min-width: 38px;
    height: 30px;
    display: grid;
    place-items: center;
    color: #2457d6;
    background: #eaf0ff;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 800;
}

.section-header h3 {
    margin: 0;
    color: #15223b;
    font-size: 15px;
    font-weight: 800;
}

.section-header p {
    margin: 4px 0 0;
    color: #7b879c;
    font-size: 12px;
}

.form-grid {
    display: grid;
    grid-template-columns:
        minmax(0, 1fr)
        minmax(0, 1fr);
    gap: 18px 20px;
}

.field-span-2 {
    grid-column: span 2;
}

:deep(.product-field) {
    min-width: 0;
    width: 100%;
    margin: 0;
}

:deep(.product-field label) {
    display: block;
    margin-bottom: 7px;
    color: #344159;
    font-size: 12px;
    font-weight: 700;
}

:deep(.product-field .form-control) {
    width: 100% !important;
    min-width: 0;
    min-height: 44px;
    padding: 10px 12px;
    color: #17233b;
    background: #ffffff;
    border: 1px solid #d8e0eb;
    border-radius: 9px;
    outline: none;
    font-size: 13px;
    transition:
        border-color 0.15s ease,
        box-shadow 0.15s ease;
}

:deep(.product-field .form-control::placeholder) {
    color: #a0a9b8;
}

:deep(.product-field .form-control:focus) {
    border-color: #2457d6;
    box-shadow: 0 0 0 3px rgba(36, 87, 214, 0.1);
}

:deep(.product-field select.form-control) {
    appearance: auto;
    cursor: pointer;
}

:deep(.product-field textarea.form-control) {
    min-height: 82px;
    resize: vertical;
}

:deep(.product-field .input-group) {
    width: 100%;
    display: flex;
}

:deep(.product-field .input-group .form-control) {
    flex: 1;
}

:deep(.product-field .input-group-text) {
    min-width: 43px;
    display: grid;
    place-items: center;
    color: #57647b;
    background: #f3f6fa;
    border: 1px solid #d8e0eb;
    font-size: 13px;
    font-weight: 700;
}

:deep(.product-field .input-group-text:first-child) {
    border-right: 0;
    border-radius: 9px 0 0 9px;
}

:deep(
    .product-field
    .input-group
    .input-group-text:first-child
    + .form-control
) {
    border-radius: 0 9px 9px 0;
}

:deep(.product-field .text-danger) {
    display: block;
    margin-top: 4px;
    font-size: 11px !important;
}

.field-help {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    padding: 11px 13px;
    color: #66738a;
    background: #f6f8fc;
    border: 1px dashed #d7deea;
    border-radius: 9px;
    font-size: 11px;
    line-height: 1.5;
}

.field-error {
    color: #d83946;
    font-size: 11px;
    font-weight: 700;
}

.repeat-list {
    display: grid;
    gap: 10px;
}

.repeat-row {
    display: grid;
    grid-template-columns: 96px minmax(0, 1fr) 150px 86px;
    gap: 9px;
    align-items: center;
    padding: 10px;
    background: #f8fafc;
    border: 1px solid #e1e7ef;
    border-radius: 10px;
}

.repeat-row.image-row {
    grid-template-columns: 96px minmax(0, 1fr) 140px 86px;
}

.repeat-row .form-control,
.hsn-search-field .form-control {
    width: 100%;
    min-height: 40px;
    padding: 9px 11px;
    color: #17233b;
    background: #ffffff;
    border: 1px solid #d8e0eb;
    border-radius: 8px;
    font-size: 12px;
}

.radio-field {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 0;
    color: #465269;
    font-size: 11px;
    font-weight: 750;
}

.radio-field input {
    width: 15px;
    height: 15px;
}

.row-add,
.row-remove {
    min-height: 36px;
    padding: 7px 12px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 750;
    cursor: pointer;
}

.row-add {
    justify-self: start;
    color: #2457d6;
    background: #edf2ff;
    border: 1px solid #ccd9ff;
}

.row-remove {
    color: #d23b45;
    background: #fff1f2;
    border: 1px solid #ffd2d6;
}

.row-add:disabled,
.row-remove:disabled {
    cursor: not-allowed;
    opacity: 0.65;
}

.hsn-search-field {
    position: relative;
}

.hsn-input-row {
    position: relative;
}

.inline-loader {
    position: absolute;
    top: 11px;
    right: 11px;
    width: 16px;
    height: 16px;
    border: 2px solid #d9e2f3;
    border-top-color: #2457d6;
    border-radius: 50%;
    animation: spin 0.75s linear infinite;
}

.hsn-results {
    position: absolute;
    top: 68px;
    right: 0;
    left: 0;
    z-index: 20;
    max-height: 220px;
    overflow-y: auto;
    background: #ffffff;
    border: 1px solid #dce4ef;
    border-radius: 9px;
    box-shadow: 0 12px 30px rgba(15, 34, 66, 0.12);
}

.hsn-results button {
    width: 100%;
    display: grid;
    grid-template-columns: 72px minmax(0, 1fr) 58px;
    gap: 8px;
    padding: 10px 12px;
    color: #26344d;
    background: #ffffff;
    border: 0;
    border-bottom: 1px solid #eef2f6;
    text-align: left;
    cursor: pointer;
}

.hsn-results button:hover {
    background: #f6f8fc;
}

.hsn-results strong {
    font-size: 12px;
}

.hsn-results span,
.hsn-results small {
    color: #6f7c91;
    font-size: 11px;
}

.selected-hsn {
    margin-top: 8px;
}

.toggle-field {
    min-height: 44px;
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 11px 13px;
    color: #344159;
    background: #f7f9fc;
    border: 1px solid #dfe6ef;
    border-radius: 9px;
    font-size: 12px;
    font-weight: 700;
}

.toggle-field input {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

.help-icon {
    width: 18px;
    height: 18px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    color: #2457d6;
    background: #e4ecff;
    border-radius: 50%;
    font-size: 11px;
    font-weight: 800;
}

.tax-summary {
    min-height: 70px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 12px 14px;
    background: #f7f9fc;
    border: 1px solid #e1e6ef;
    border-radius: 9px;
}

.tax-summary span {
    margin-bottom: 4px;
    color: #7a869b;
    font-size: 11px;
}

.tax-summary strong {
    color: #23304a;
    font-size: 14px;
}

.pricing-grid {
    align-items: end;
}

.pricing-rule {
    min-height: 70px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 13px;
    color: #735816;
    background: #fff9e8;
    border: 1px solid #f4dfa1;
    border-radius: 9px;
}

.pricing-rule-icon {
    width: 25px;
    height: 25px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    color: #8c6812;
    background: #ffefbc;
    border-radius: 50%;
    font-weight: 800;
}

.pricing-rule strong,
.pricing-rule span {
    display: block;
}

.pricing-rule strong {
    margin-bottom: 2px;
    font-size: 11px;
}

.pricing-rule span {
    font-size: 10px;
    line-height: 1.4;
}

.inventory-explanation {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    padding: 13px;
    background: #f7f9fc;
    border: 1px solid #e1e7ef;
    border-radius: 10px;
}

.inventory-explanation > div {
    padding: 3px 5px;
}

.inventory-explanation strong,
.inventory-explanation span {
    display: block;
}

.inventory-explanation strong {
    margin-bottom: 4px;
    color: #344159;
    font-size: 11px;
}

.inventory-explanation span {
    color: #778399;
    font-size: 10px;
    line-height: 1.5;
}

.status-preview {
    min-height: 70px;
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 12px 14px;
    background: #effaf4;
    border: 1px solid #cdeedb;
    border-radius: 9px;
}

.status-preview.inactive {
    background: #f7f8fa;
    border-color: #e0e4ea;
}

.status-dot {
    width: 10px;
    height: 10px;
    flex-shrink: 0;
    background: #20a464;
    border-radius: 50%;
    box-shadow: 0 0 0 5px rgba(32, 164, 100, 0.12);
}

.status-preview.inactive .status-dot {
    background: #8993a4;
    box-shadow: 0 0 0 5px rgba(137, 147, 164, 0.12);
}

.status-preview strong,
.status-preview small {
    display: block;
}

.status-preview strong {
    margin-bottom: 3px;
    color: #27344c;
    font-size: 12px;
}

.status-preview small {
    color: #768399;
    font-size: 10px;
}

.product-drawer-footer {
    min-height: 74px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    gap: 16px;
    padding: 14px 28px;
    background: #ffffff;
    border-top: 1px solid #dfe6ef;
    box-shadow: 0 -5px 18px rgba(18, 40, 71, 0.05);
    z-index: 10;
}

.footer-help {
    color: #7c8799;
    font-size: 11px;
}

.footer-help span {
    color: #d83946;
    font-weight: 800;
}

.footer-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

:deep(.product-cancel-button) {
    min-width: 94px;
    min-height: 42px;
    padding: 9px 18px;
    color: #465269;
    background: #ffffff;
    border: 1px solid #d8dfe9;
    border-radius: 9px;
    font-size: 12px;
    font-weight: 700;
}

:deep(.product-cancel-button:hover) {
    color: #25324a;
    background: #f5f7fa;
}

:deep(.product-save-button) {
    min-width: 150px;
    min-height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 9px 19px;
    color: #ffffff;
    background: #2457d6;
    border: 1px solid #2457d6;
    border-radius: 9px;
    box-shadow: 0 5px 14px rgba(36, 87, 214, 0.22);
    font-size: 12px;
    font-weight: 750;
}

:deep(.product-save-button:hover) {
    color: #ffffff;
    background: #1c49bd;
    border-color: #1c49bd;
}

:deep(.product-save-button svg) {
    width: 17px;
    height: 17px;
}

.product-drawer-enter-active,
.product-drawer-leave-active {
    transition: opacity 0.22s ease;
}

.product-drawer-enter-active .product-drawer-panel,
.product-drawer-leave-active .product-drawer-panel {
    transition: transform 0.25s ease;
}

.product-drawer-enter-from,
.product-drawer-leave-to {
    opacity: 0;
}

.product-drawer-enter-from .product-drawer-panel,
.product-drawer-leave-to .product-drawer-panel {
    transform: translateX(100%);
}

@media (max-width: 767px) {
    .product-drawer-header {
        min-height: 84px;
        padding: 15px 16px;
    }

    .drawer-heading-icon {
        display: none;
    }

    .drawer-heading p {
        display: none;
    }

    .product-drawer-content {
        padding: 15px 14px 24px;
    }

    .product-section {
        padding: 17px 15px;
        border-radius: 12px;
    }

    .form-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }

    .field-span-2 {
        grid-column: span 1;
    }

    .inventory-explanation {
        grid-template-columns: 1fr;
    }

    .repeat-row,
    .repeat-row.image-row,
    .hsn-results button {
        grid-template-columns: 1fr;
    }

    .product-drawer-footer {
        padding: 12px 14px;
    }

    .footer-help {
        display: none;
    }

    .footer-actions {
        width: 100%;
    }

    :deep(.product-cancel-button),
    :deep(.product-save-button) {
        flex: 1;
    }
}
</style>
