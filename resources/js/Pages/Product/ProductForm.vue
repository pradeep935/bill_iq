<script setup>
import { computed, reactive, watch } from 'vue';
import { Form } from 'vee-validate';

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
});

const emit = defineEmits([
    'update:modelValue',
    'save',
]);

const initialForm = () => ({
    id: '',

    name: '',
    product_type: 'goods',
    category: '',
    brand: '',
    variant: '',
    unit: 'PCS',

    sku: '',
    primary_barcode: '',
    extra_barcodes: '',

    hsn_master_id: '',
    hsn_code: '',
    taxability: 'taxable',
    gst_rate: '0',
    cess_rate: '0',
    reverse_charge: 'no',
    invoice_description: '',

    cost_price: '',
    selling_price: '',
    mrp: '',

    opening_stock: '0',
    minimum_stock: '0',
    reorder_stock: '0',
    tracking_type: 'none',

    status: 'active',
});

const form = reactive(initialForm());

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
        category: product?.category || '',
        brand: product?.brand || '',
        variant: product?.variant || '',
        unit: product?.unit || 'PCS',

        sku: product?.sku || '',
        primary_barcode: product?.primary_barcode || '',
        extra_barcodes: product?.extra_barcodes || '',

        hsn_master_id: product?.hsn_master_id || '',
        hsn_code: product?.hsn_code || '',
        taxability: product?.taxability || 'taxable',
        gst_rate: String(product?.gst_rate ?? '0'),
        cess_rate: String(product?.cess_rate ?? '0'),
        reverse_charge: product?.reverse_charge || 'no',
        invoice_description:
            product?.invoice_description || '',

        cost_price: product?.cost_price ?? '',
        selling_price: product?.selling_price ?? '',
        mrp: product?.mrp ?? '',

        opening_stock: product?.opening_stock ?? '0',
        minimum_stock: product?.minimum_stock ?? '0',
        reorder_stock: product?.reorder_stock ?? '0',
        tracking_type: product?.tracking_type || 'none',

        status: product?.status || 'active',
    });
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
            document.body.classList.add('product-drawer-open');
        } else {
            document.body.classList.remove('product-drawer-open');
        }
    }
);

const closeDrawer = () => {
    if (props.processing) {
        return;
    }

    emit('update:modelValue', false);
};

const saveProduct = () => {
    emit('save', {
        ...form,

        cost_price: form.cost_price || 0,
        selling_price: form.selling_price || 0,
        mrp: form.mrp || null,

        opening_stock:
            form.product_type === 'goods'
                ? form.opening_stock || 0
                : 0,

        minimum_stock:
            form.product_type === 'goods'
                ? form.minimum_stock || 0
                : 0,

        reorder_stock:
            form.product_type === 'goods'
                ? form.reorder_stock || 0
                : 0,

        tracking_type:
            form.product_type === 'goods'
                ? form.tracking_type
                : 'none',
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
                            <span aria-hidden="true">×</span>
                        </Button2>
                    </header>

                    <Form
                        class="product-form"
                        @submit="saveProduct"
                    >
                        <main class="product-drawer-content">

                            <!-- Basic details -->
                            <section class="product-section">
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
                                        v-model="form.unit"
                                        name="unit"
                                        label="Unit"
                                        cls="product-field"
                                        :options="unitOptions"
                                        select_name="Select unit"
                                        :req="true"
                                    />

                                    <FormInput
                                        v-model="form.category"
                                        name="category"
                                        label="Category"
                                        placeholder="Example: Smartphones"
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
                                </div>
                            </section>

                            <!-- SKU and barcode -->
                            <section class="product-section">
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

                                <div class="form-grid">
                                    <FormInput
                                        v-model="form.sku"
                                        name="sku"
                                        label="SKU"
                                        placeholder="Example: SG25-256-BLK"
                                        cls="product-field"
                                        :req="true"
                                    />

                                    <FormInput
                                        v-model="form.primary_barcode"
                                        name="primary_barcode"
                                        label="Primary Barcode"
                                        placeholder="Scan or enter barcode"
                                        cls="product-field"
                                    />

                                    <FormText
                                        v-model="form.extra_barcodes"
                                        name="extra_barcodes"
                                        label="Additional Barcodes"
                                        placeholder="Enter additional barcodes separated by commas"
                                        cls="product-field field-span-2"
                                        :rows="3"
                                    />

                                    <div class="field-help field-span-2">
                                        <span class="help-icon">i</span>

                                        <span>
                                            Primary barcode unique hona chahiye.
                                            Additional barcodes comma se
                                            separate kar sakte hain.
                                        </span>
                                    </div>
                                </div>
                            </section>

                            <!-- GST and HSN -->
                            <section class="product-section">
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
                                    <FormInput
                                        v-model="form.hsn_code"
                                        name="hsn_code"
                                        label="HSN / SAC Code"
                                        placeholder="Search or enter HSN code"
                                        cls="product-field"
                                        :req="true"
                                    />

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
                                        :req="true"
                                    />

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
                            <section class="product-section">
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
                                        left_box_text="₹"
                                    />

                                    <FormInput
                                        v-model="form.selling_price"
                                        name="selling_price"
                                        type="number"
                                        label="Selling Price"
                                        placeholder="0.00"
                                        cls="product-field"
                                        left_box_text="₹"
                                        :req="true"
                                    />

                                    <FormInput
                                        v-model="form.mrp"
                                        name="mrp"
                                        type="number"
                                        label="MRP"
                                        placeholder="0.00"
                                        cls="product-field"
                                        left_box_text="₹"
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
                                v-if="form.product_type === 'goods'"
                                class="product-section"
                            >
                                <div class="section-header">
                                    <div class="section-number">
                                        05
                                    </div>

                                    <div>
                                        <h3>Inventory Settings</h3>

                                        <p>
                                            Opening quantity, stock alerts and
                                            product tracking.
                                        </p>
                                    </div>
                                </div>

                                <div class="form-grid">
                                    <FormInput
                                        v-model="form.opening_stock"
                                        name="opening_stock"
                                        type="number"
                                        label="Opening Stock"
                                        placeholder="0"
                                        cls="product-field"
                                    />

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

                            <!-- Status -->
                            <section class="product-section">
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