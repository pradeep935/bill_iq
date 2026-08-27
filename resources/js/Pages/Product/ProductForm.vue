<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
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

    references: {
        type: Object,
        default: () => ({
            categories: [],
            sub_categories: [],
        }),
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
    category_id: '',
    sub_category_id: '',
    unit_id: '',
    category: '',
    subcategory: '',
    brand_id: '',
    brand: '',
    variant: '',
    unit: 'PCS',
    description: '',

    sku: '',
    primary_barcode: '',

    hsn_master_id: '',
    hsn_tax_rate_id: '',
    hsn_code: '',
    taxability: 'taxable',
    gst_rate: '0',
    cess_rate: '0',
    tax_source: 'manual_confirmation',
    tax_override_reason: '',
    tax_override_reference: '',
    reverse_charge: 'no',
    tax_inclusive: false,
    invoice_description: '',

    cost_price: '',
    selling_price: '0',
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
const selectedHsnRecord = ref(null);
let hsnSearchTimer = null;
let hsnSuggestTimer = null;
const clientErrors = ref({});
const attemptedSave = ref(false);
const fillingForm = ref(false);
const imageUploads = ref({});

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

const normalizedProductName = computed(() => form.name.trim().replace(/\s+/g, ' '));

const titleCase = (value = '') => value
    .toLowerCase()
    .replace(/\b[a-z0-9]/g, (char) => char.toUpperCase());

const compactToken = (value = '') => String(value)
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, ' ')
    .trim();

const initials = (value = '', fallback = 'PRD') => {
    const words = compactToken(value).split(' ').filter(Boolean);

    if (!words.length) {
        return fallback;
    }

    const code = words
        .slice(0, 4)
        .map((word) => word.slice(0, word.length <= 3 ? 3 : 2))
        .join('');

    return code.slice(0, 12) || fallback;
};

const extractVariant = (name = '') => {
    const parts = [];
    const storage = name.match(/\b\d+\s?(GB|TB|ML|L|KG|GM|PCS|W|V|A)\b/i);
    const pack = name.match(/\b(Pack of \d+|\d+\s?(pcs|piece|set|pair|pack|box))\b/i);
    const color = name.match(/\b(black|white|blue|red|green|yellow|silver|gold|grey|gray|pink|purple|brown|orange)\b/i);
    const size = name.match(/\b(XS|S|M|L|XL|XXL|XXXL)\b/i);

    [storage, pack, color, size].forEach((match) => {
        if (match?.[0] && !parts.includes(titleCase(match[0]))) {
            parts.push(titleCase(match[0]));
        }
    });

    return parts.join(' / ');
};

const suggestedSku = computed(() => {
    const nameCode = compactToken(normalizedProductName.value)
        .split(' ')
        .filter(Boolean)
        .slice(0, 3)
        .map((word) => word.slice(0, 8))
        .join('-');
    const brandCode = form.brand ? initials(form.brand, '').slice(0, 4) : '';
    const variantCode = form.variant || extractVariant(normalizedProductName.value);
    const variantSuffix = initials(variantCode, '').slice(0, 6);
    const parts = [brandCode, nameCode, variantSuffix].filter(Boolean);

    return (parts.join('-').replace(/-+/g, '-').slice(0, 32) || 'PRD');
});

const suggestedBarcode = computed(() => {
    const seed = compactToken(`${form.brand} ${normalizedProductName.value} ${form.variant}`).replace(/\s/g, '');
    let hash = 0;

    for (let index = 0; index < seed.length; index++) {
        hash = (hash * 31 + seed.charCodeAt(index)) % 1000000000;
    }

    return `89${String(hash || Date.now()).padStart(10, '0').slice(0, 10)}`;
});

const suggestedShortName = computed(() => normalizedProductName.value
    ? normalizedProductName.value.slice(0, 48)
    : '');

const suggestedVariant = computed(() => extractVariant(normalizedProductName.value));
const hsnSacLabel = computed(() => form.product_type === 'service' ? 'SAC Code' : 'HSN Code');
const isTaxable = computed(() => !['exempt', 'non_gst', 'nil_rated'].includes(form.taxability));
const canEditCurrentGstRate = computed(() => props.canEditGstRate && isTaxable.value);
const profitAmount = computed(() => Number(form.selling_price || 0) - Number(form.cost_price || 0));
const profitPercent = computed(() => {
    const cost = Number(form.cost_price || 0);
    return cost > 0 ? (profitAmount.value / cost) * 100 : 0;
});
const marginPercent = computed(() => {
    const selling = Number(form.selling_price || 0);
    return selling > 0 ? (profitAmount.value / selling) * 100 : 0;
});

const parsePrice = (value) => {
    const normalized = String(value).trim();
    const parsed = Number(normalized);
    return Number.isFinite(parsed) ? parsed : 0;
};

const numericCostPrice = computed(() => parsePrice(form.cost_price));
const numericSellingPrice = computed(() => parsePrice(form.selling_price));
const numericMrp = computed(() => parsePrice(form.mrp));
const numericWholesalePrice = computed(() => parsePrice(form.wholesale_price));
const numericDealerPrice = computed(() => parsePrice(form.dealer_price));
const numericOnlinePrice = computed(() => parsePrice(form.online_price));

const pricingErrors = computed(() => {
    const errors = {};

    if (form.cost_price === '' || form.cost_price === null || form.cost_price === undefined) {
        errors.cost_price = 'Cost price is required.';
    }

    if (form.selling_price === '' || form.selling_price === null || form.selling_price === undefined) {
        errors.selling_price = 'Selling price is required.';
    }

    if (numericMrp.value > 0 && numericSellingPrice.value > numericMrp.value) {
        errors.selling_price = 'Selling price cannot exceed MRP.';
    }

    if (numericWholesalePrice.value > 0 && numericWholesalePrice.value > numericSellingPrice.value) {
        errors.wholesale_price = 'Wholesale price cannot exceed Selling Price.';
    }

    if (numericDealerPrice.value > 0 && numericDealerPrice.value > numericSellingPrice.value) {
        errors.dealer_price = 'Dealer price cannot exceed Selling Price.';
    }

    if (numericMrp.value > 0 && numericOnlinePrice.value > numericMrp.value) {
        errors.online_price = 'Online price cannot exceed MRP.';
    }

    return errors;
});

const pricingWarnings = computed(() => {
    if (
        numericSellingPrice.value > 0 &&
        numericCostPrice.value > 0 &&
        numericSellingPrice.value < numericCostPrice.value
    ) {
        return ['Selling price is below cost price.'];
    }

    return [];
});

const pricingStatus = computed(() => {
    if (Object.keys(pricingErrors.value).length) {
        return {
            label: 'Validation Error',
            description: 'Fix pricing fields highlighted below.',
            tone: 'error',
        };
    }

    if (pricingWarnings.value.length) {
        return {
            label: 'Warning',
            description: pricingWarnings.value[0],
            tone: 'warning',
        };
    }

    if (form.cost_price !== '' || form.selling_price !== '') {
        return {
            label: 'Ready to Save',
            description: 'Pricing values are valid and ready.',
            tone: 'success',
        };
    }

    return {
        label: 'Ready to Save',
        description: 'Enter cost and selling price to calculate profit.',
        tone: 'neutral',
    };
});

const formatMoney = (value) => {
    const amount = Number(value);
    return Number.isFinite(amount) ? amount.toFixed(2) : '0.00';
};

const fieldError = (field) => {
    const shouldShowPricingError = attemptedSave.value || activeTab.value === 'pricing';

    return (
        clientErrors.value[field] ||
        (shouldShowPricingError ? pricingErrors.value[field] : '') ||
        props.errors?.[field]?.[0] ||
        ''
    );
};

const allErrors = computed(() => {
    return [
        ...Object.values(clientErrors.value),
        ...(attemptedSave.value ? Object.values(pricingErrors.value) : []),
        ...Object.values(props.errors || {})
            .map((value) => value?.[0])
            .filter(Boolean),
    ];
});

const displayNameWithBrand = computed(() => {
    const name = normalizedProductName.value;
    const brand = String(form.brand || '').trim();

    if (!brand || name.toLowerCase().includes(brand.toLowerCase())) {
        return name;
    }

    return `${brand} ${name}`;
});

const categoryLabel = computed(() => {
    const selectedCategory = categoryOptions.value.find((category) => {
        return String(category.value ?? category.id ?? category.name) === String(form.category_id || form.category);
    });

    return selectedCategory?.label || selectedCategory?.name || form.category || '';
});

const unitDescription = computed(() => {
    const unit = String(form.unit || '').toUpperCase();
    const map = {
        PCS: 'per piece',
        NOS: 'per number',
        BOX: 'per box',
        PKT: 'per packet',
        SET: 'per set',
        PAIR: 'per pair',
        KG: 'by kilogram',
        GM: 'by gram',
        LTR: 'by litre',
        ML: 'by millilitre',
        MTR: 'by meter',
        HRS: 'by hour',
    };

    return map[unit] || (unit ? `in ${unit}` : '');
});

const suggestedDescription = computed(() => {
    if (!normalizedProductName.value) {
        return '';
    }

    const categoryText = categoryLabel.value
        ? `${String(categoryLabel.value).toLowerCase()} item`
        : form.product_type === 'service'
            ? 'service item'
            : 'product';
    const sentences = [
        `${displayNameWithBrand.value} is a ${categoryText}${unitDescription.value ? ` sold ${unitDescription.value}` : ''}.`,
    ];

    if (form.variant || suggestedVariant.value) {
        sentences.push(`Variant: ${form.variant || suggestedVariant.value}.`);
    }

    return sentences.join(' ');
});

const canSmartFill = computed(() => Boolean(normalizedProductName.value));

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

const optionText = (option = {}) => String(option.label || option.name || option.code || option.value || '').trim();

const selectedOption = (options = [], value = '') => {
    return options.find((option) => String(option.value) === String(value)) || null;
};

const findMatchingOption = (options = [], source = '') => {
    const sourceText = String(source || '').toLowerCase();

    if (!sourceText) {
        return null;
    }

    return options.find((option) => {
        const label = optionText(option).toLowerCase();

        return label && sourceText.includes(label);
    }) || null;
};

const applyHsn = (hsn) => {
    if (!hsn) {
        return;
    }

    form.hsn_master_id = hsn.id || '';
    form.hsn_tax_rate_id = '';
    form.hsn_code = hsn.hsn_code || '';
    hsnSearch.value = `${hsn.hsn_code || ''} - ${hsn.description || ''}`.trim();
    selectedHsnRecord.value = hsn;

    if (hsn.tax_resolution?.status === 'single_verified_rule' && hsn.tax_resolution.rule) {
        const rule = hsn.tax_resolution.rule;
        form.hsn_tax_rate_id = rule.id || '';
        form.taxability = rule.taxability || 'taxable';
        form.gst_rate = String(rule.gst_rate ?? '');
        form.cess_rate = String(rule.cess_rate ?? '0');
        form.tax_source = 'verified_rule';

        return;
    }

    form.tax_source = hsn.tax_resolution?.status === 'multiple_verified_rules'
        ? 'manual_confirmation'
        : 'manual_confirmation';

    if (hsn.gst_rate !== null && hsn.gst_rate !== undefined) {
        form.taxability = hsn.taxability || 'taxable';
        form.gst_rate = String(hsn.gst_rate);
        form.cess_rate = String(hsn.cess_rate ?? '0');
        form.tax_source = hsn.rate_verified ? 'verified_rule' : 'master_suggested';
    }
};

const findMatchingHsn = () => {
    const source = [
        normalizedProductName.value,
        categoryLabel.value,
        form.description,
    ].join(' ').toLowerCase();
    const words = source.split(/\s+/).filter((word) => word.length > 3);

    if (!source.trim()) {
        return null;
    }

    return hsnOptions.value.find((hsn) => {
        const code = String(hsn.hsn_code || '').toLowerCase();
        const description = String(hsn.description || '').toLowerCase();

        return (code && source.includes(code)) ||
            words.some((word) => description.includes(word));
    }) || null;
};

const syncPrimaryBarcode = () => {
    if (!form.primary_barcode) {
        return;
    }

    if (!barcodes.value.length) {
        barcodes.value.push({
            barcode: form.primary_barcode,
            barcode_type: 'primary',
            is_primary: true,
        });

        return;
    }

    barcodes.value[0] = {
        ...barcodes.value[0],
        barcode: form.primary_barcode,
        barcode_type: 'primary',
        is_primary: true,
    };
};

const applySuggestedBarcode = (force = false) => {
    if (!suggestedBarcode.value || (!force && form.primary_barcode)) {
        return;
    }

    form.primary_barcode = suggestedBarcode.value;
    syncPrimaryBarcode();
};

const fillSmartFields = (force = false) => {
    if (!canSmartFill.value) {
        return;
    }

    if (force || !form.short_name) form.short_name = suggestedShortName.value;
    if (force || !form.sku) form.sku = suggestedSku.value;
    if (force || !form.brand_id) {
        const matchingBrand = findMatchingOption(brandOptions.value, normalizedProductName.value);

        if (matchingBrand) {
            form.brand_id = String(matchingBrand.value);
            form.brand = matchingBrand.name || matchingBrand.label || '';
        }
    }
    if (force || !form.category_id) {
        const matchingCategory = findMatchingOption(categoryOptions.value, normalizedProductName.value);

        if (matchingCategory) {
            form.category_id = /^\d+$/.test(String(matchingCategory.value || '')) ? String(matchingCategory.value) : '';
            form.category = matchingCategory.label || matchingCategory.name || '';
        }
    }
    if ((force || !form.variant) && suggestedVariant.value) form.variant = suggestedVariant.value;
    if (force || !form.description) form.description = suggestedDescription.value;
    if (force || !form.invoice_description) form.invoice_description = form.short_name || normalizedProductName.value;
    applySuggestedBarcode(force);

};

const hsnSuggestionQuery = computed(() => [
    normalizedProductName.value,
    form.brand,
    categoryLabel.value,
    form.subcategory,
    form.description,
].filter(Boolean).join(' ').trim());

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

const categoryOptions = computed(() => normalizeReferenceOptions(props.references?.categories || [], ['name', 'label', 'code']));
const subCategoryOptions = computed(() => normalizeReferenceOptions(props.references?.sub_categories || [], ['name', 'label', 'code']));
const hsnOptions = computed(() => {
    const expectedCodeType = form.product_type === 'service' ? 'SAC' : 'HSN';

    return (props.references?.hsn_codes || [])
        .filter((hsn) => !hsn.code_type || hsn.code_type === expectedCodeType);
});
const selectedHsn = computed(() => selectedHsnRecord.value || hsnOptions.value.find((hsn) => String(hsn.id) === String(form.hsn_master_id)) || null);
const hsnTaxLocked = computed(() => ['verified_rule', 'master_suggested'].includes(form.tax_source));
const brandOptions = computed(() => {
    const options = normalizeReferenceOptions(props.references?.brands || [], ['name', 'label', 'code']);

    if (form.brand && !options.some((brand) => brand.name === form.brand || brand.label === form.brand)) {
        options.push({
            value: `legacy:${form.brand}`,
            label: form.brand,
            name: form.brand,
        });
    }

    return options;
});

const taxabilityOptions = [
    { value: 'taxable', label: 'Taxable' },
    { value: 'exempt', label: 'Exempt' },
    { value: 'nil_rated', label: 'Nil Rated' },
    { value: 'non_gst', label: 'Non-Taxable' },
];

const fallbackGstRateOptions = [
    { value: '0', label: '0%', is_common: true },
    { value: '5', label: '5%', is_common: true },
    { value: '12', label: '12%', is_common: true },
    { value: '18', label: '18%', is_common: true },
    { value: '28', label: '28%', is_common: true },
];
const gstRateOptions = computed(() => {
    const slabs = props.references?.gst_rate_slabs || [];

    return slabs.length ? slabs : fallbackGstRateOptions;
});

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
    { value: 'discontinued', label: 'Discontinued' },
];

const fillForm = (product = {}) => {
    fillingForm.value = true;

    Object.assign(form, initialForm(), {
        id: product?.id || '',

        name: product?.name || '',
        product_type: product?.product_type || 'goods',
        item_type: product?.item_type || 'stock',
        short_name: product?.short_name || '',
        category_id: product?.category_id || '',
        sub_category_id: product?.sub_category_id || '',
        unit_id: product?.unit_id || '',
        category: product?.category || '',
        subcategory: product?.subcategory || '',
        brand_id: product?.brand_id ? String(product.brand_id) : (product?.brand ? `legacy:${product.brand}` : ''),
        brand: product?.brand || '',
        variant: product?.variant || '',
        unit: product?.unit || 'PCS',
        description: product?.description || '',

        sku: product?.sku || '',
        primary_barcode: product?.primary_barcode || '',

        hsn_master_id: product?.hsn_master_id || '',
        hsn_tax_rate_id: product?.hsn_tax_rate_id || '',
        hsn_code: product?.hsn_code || '',
        taxability: product?.taxability || 'taxable',
        gst_rate: String(product?.gst_rate ?? '0'),
        cess_rate: String(product?.cess_rate ?? '0'),
        tax_source: product?.tax_source || 'manual_confirmation',
        tax_override_reason: product?.tax_override_reason || '',
        tax_override_reference: product?.tax_override_reference || '',
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
    selectedHsnRecord.value = (props.references?.hsn_codes || []).find((hsn) => String(hsn.id) === String(product?.hsn_master_id || product?.hsn_id)) || null;
    clientErrors.value = {};
    attemptedSave.value = false;

    nextTick(() => {
        fillingForm.value = false;
    });
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
              preview_url:
                  typeof image === 'string'
                      ? ''
                      : image?.preview_url || '',
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
                  preview_url: '',
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

        const expectedCodeType = productType === 'service' ? 'SAC' : 'HSN';
        const currentHsn = (props.references?.hsn_codes || []).find((hsn) => String(hsn.id) === String(form.hsn_master_id));
        if (currentHsn?.code_type && currentHsn.code_type !== expectedCodeType) {
            form.hsn_master_id = '';
            form.hsn_code = '';
            hsnSearch.value = '';
            selectedHsnRecord.value = null;
            form.tax_source = 'manual_confirmation';
        }

        suggestHsnFromProduct();
    }
);

watch(
    () => form.category_id,
    () => {
        if (fillingForm.value) {
            return;
        }

        form.sub_category_id = '';
        form.subcategory = '';
    }
);

watch(
    () => form.category_id,
    (categoryId) => {
        const category = selectedOption(categoryOptions.value, categoryId);

        if (category) {
            form.category = category.name || category.label;
        } else if (!categoryId) {
            form.category = '';
        }
    }
);

watch(
    () => form.sub_category_id,
    (subCategoryId) => {
        const subCategory = selectedOption(subCategoryOptions.value, subCategoryId);

        if (subCategory) {
            form.subcategory = subCategory.name || subCategory.label;
        } else if (!subCategoryId) {
            form.subcategory = '';
        }
    }
);

watch(
    () => form.taxability,
    () => {
        if (!isTaxable.value) {
            form.gst_rate = '0';
        }
    },
    { immediate: true }
);

watch(
    normalizedProductName,
    () => {
        fillSmartFields(false);
    }
);

watch(
    hsnSuggestionQuery,
    () => {
        suggestHsnFromProduct();
    }
);

watch(
    () => form.brand_id,
    (brandId) => {
        const selectedBrand = brandOptions.value.find((brand) => String(brand.value) === String(brandId));

        if (selectedBrand) {
            form.brand = selectedBrand.name || selectedBrand.label;
        } else if (!brandId) {
            form.brand = '';
        }
    }
);

const closeDrawer = () => {
    if (props.processing) {
        return;
    }

    emit('update:modelValue', false);
};

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
        barcode_type: barcodes.value.length ? 'alternate' : 'primary',
        is_primary: !barcodes.value.length,
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
        preview_url: '',
        image_type: images.value.length ? 'gallery' : 'main',
        sort_order: images.value.length,
        is_primary: !images.value.length,
    });
};

const removeImage = (index) => {
    const wasPrimary = images.value[index]?.is_primary;
    images.value.splice(index, 1);

    if (!images.value.length) {
        addImage();
        return;
    }

    if (wasPrimary) {
        images.value[0].is_primary = true;
        images.value[0].image_type = 'main';
    }
};

const setPrimaryImage = (index) => {
    images.value = images.value.map((image, imageIndex) => ({
        ...image,
        is_primary: imageIndex === index,
        image_type: imageIndex === index ? 'main' : (image.image_type === 'main' ? 'gallery' : image.image_type),
    }));
};

const imagePreviewUrl = (image = {}) => {
    const path = String(image.preview_url || image.image_path || '').trim();

    if (!path) {
        return '';
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

    if (normalizedPath.startsWith('storage/uploads/')) {
        return `/${normalizedPath.replace(/^storage\//, '')}`;
    }

    if (normalizedPath.startsWith('storage/')) {
        return `/${normalizedPath}`;
    }

    if (normalizedPath.startsWith('uploads/') || normalizedPath.startsWith('upload/')) {
        return `/${normalizedPath}`;
    }

    return `/${normalizedPath}`;
};

const uploadImage = async (event, index) => {
    const file = event.target.files?.[0];

    if (!file) {
        return;
    }

    if (images.value[index].preview_url?.startsWith('blob:')) {
        URL.revokeObjectURL(images.value[index].preview_url);
    }

    images.value[index].preview_url = URL.createObjectURL(file);

    imageUploads.value = {
        ...imageUploads.value,
        [index]: {
            uploading: true,
            error: '',
        },
    };

    try {
        const response = await ProductApi.uploadImage(file);
        images.value[index].image_path = response.path || '';
        images.value[index].preview_url = response.url || images.value[index].preview_url;

        if (!images.value.some((image) => image.is_primary)) {
            setPrimaryImage(index);
        }
    } catch (error) {
        imageUploads.value = {
            ...imageUploads.value,
            [index]: {
                uploading: false,
                error: error.response?.data?.message || 'Image upload failed.',
            },
        };

        return;
    }

    imageUploads.value = {
        ...imageUploads.value,
        [index]: {
            uploading: false,
            error: '',
        },
    };
    event.target.value = '';
};

const clearImage = (index) => {
    if (images.value[index].preview_url?.startsWith('blob:')) {
        URL.revokeObjectURL(images.value[index].preview_url);
    }

    images.value[index].image_path = '';
    images.value[index].preview_url = '';
    imageUploads.value = {
        ...imageUploads.value,
        [index]: {
            uploading: false,
            error: '',
        },
    };
};

const openImage = (image = {}) => {
    const url = imagePreviewUrl(image);

    if (!url) {
        return;
    }

    window.open(url, '_blank', 'noopener');
};

const searchHsn = async () => {
    const keyword = hsnSearch.value.trim();
    const directCode = keyword.match(/^\d{2,8}/)?.[0] || keyword;
    form.hsn_code = directCode;
    form.hsn_master_id = '';
    selectedHsnRecord.value = null;
    form.tax_source = 'manual_confirmation';

    if (hsnSearchTimer) {
        clearTimeout(hsnSearchTimer);
    }

    const isCodeSearch = /^\d{2,8}$/.test(keyword);
    if (!isCodeSearch && keyword.length < 3) {
        hsnResults.value = [];

        return;
    }

    const normalizedKeyword = keyword.toLowerCase();
    hsnResults.value = hsnOptions.value
        .filter((hsn) => {
            return String(hsn.hsn_code || '').toLowerCase().includes(normalizedKeyword) ||
                String(hsn.description || '').toLowerCase().includes(normalizedKeyword);
        })
        .slice(0, 8);

    hsnSearchTimer = setTimeout(async () => {
        hsnSearching.value = true;

        try {
            const results = await ProductApi.searchHsn(keyword, form.product_type, {
                product_name: hsnSuggestionQuery.value || form.name || form.product_name || '',
                category_id: form.category_id || '',
                limit: 20,
            });
            hsnResults.value = results.length ? results : hsnResults.value;
        } finally {
            hsnSearching.value = false;
        }
    }, 300);
};

const suggestHsnFromProduct = () => {
    if (fillingForm.value || form.hsn_master_id) {
        return;
    }

    if (hsnSuggestTimer) {
        clearTimeout(hsnSuggestTimer);
    }

    const keyword = hsnSuggestionQuery.value;
    if (keyword.length < 3) {
        hsnResults.value = [];
        return;
    }

    hsnSuggestTimer = setTimeout(async () => {
        hsnSearching.value = true;

        try {
            const results = await ProductApi.searchHsn(keyword, form.product_type, {
                product_name: keyword,
                category_id: form.category_id || '',
                limit: 12,
            });

            hsnResults.value = results;
            if (!hsnSearch.value || hsnSearch.value === form.hsn_code) {
                hsnSearch.value = keyword;
            }
        } finally {
            hsnSearching.value = false;
        }
    }, 450);
};

const selectHsn = (hsn) => {
    applyHsn(hsn);
    hsnResults.value = [];
};

const validateBeforeSave = () => {
    const errors = {};
    let firstErrorTab = '';
    const requiredChecks = [
        ['name', 'Product name is required.', 'basic'],
        ['product_type', 'Product type is required.', 'basic'],
        ['item_type', 'Item type is required.', 'basic'],
        ['unit', 'Unit is required.', 'basic'],
        ['sku', 'SKU is required.', 'basic'],
        ['cost_price', 'Cost price is required.', 'pricing'],
        ['selling_price', 'Selling price is required.', 'pricing'],
        ['status', 'Status is required.', 'advanced'],
    ];

    requiredChecks.forEach(([field, message, tab]) => {
        if (form[field] === '' || form[field] === null || form[field] === undefined) {
            errors[field] = message;
            firstErrorTab ||= tab;
        }
    });

    if (isTaxable.value && !form.hsn_code) {
        errors.hsn_code = `Enter or select ${hsnSacLabel.value}.`;
        firstErrorTab ||= 'gst';
    }

    if (isTaxable.value && form.gst_rate === '') {
        errors.gst_rate = 'GST rate is required for taxable products.';
        firstErrorTab ||= 'gst';
    }

    if (form.mrp !== '' && Number(form.mrp) > 0) {
        [
            ['selling_price', 'Selling price'],
            ['wholesale_price', 'Wholesale price'],
            ['dealer_price', 'Dealer price'],
            ['online_price', 'Online price'],
        ].forEach(([field, label]) => {
            if (form[field] !== '' && Number(form[field] || 0) > Number(form.mrp)) {
                errors[field] = `${label} cannot be greater than MRP.`;
                firstErrorTab ||= 'pricing';
            }
        });
    }

    if (form.wholesale_price !== '' && Number(form.wholesale_price || 0) > Number(form.selling_price || 0)) {
        errors.wholesale_price = 'Wholesale price cannot exceed Selling Price.';
        firstErrorTab ||= 'pricing';
    }

    if (form.dealer_price !== '' && Number(form.dealer_price || 0) > Number(form.selling_price || 0)) {
        errors.dealer_price = 'Dealer price cannot exceed Selling Price.';
        firstErrorTab ||= 'pricing';
    }

    if (form.product_type === 'goods') {
        const minimumStock = Number(form.minimum_stock || 0);
        const reorderStock = Number(form.reorder_stock || 0);
        const maximumStock = Number(form.maximum_stock || 0);

        if (reorderStock > 0 && minimumStock > 0 && reorderStock < minimumStock) {
            errors.reorder_stock = 'Reorder stock should be equal to or greater than minimum stock.';
            firstErrorTab ||= 'inventory';
        }

        if (maximumStock > 0 && minimumStock > 0 && maximumStock < minimumStock) {
            errors.maximum_stock = 'Maximum stock should be equal to or greater than minimum stock.';
            firstErrorTab ||= 'inventory';
        }

        if (maximumStock > 0 && reorderStock > 0 && maximumStock < reorderStock) {
            errors.maximum_stock = 'Maximum stock should be equal to or greater than reorder stock.';
            firstErrorTab ||= 'inventory';
        }
    }

    const filledBarcodes = barcodes.value
        .map((barcode) => barcode.barcode.trim())
        .filter(Boolean);
    const uniqueBarcodes = new Set(filledBarcodes);

    if (filledBarcodes.length !== uniqueBarcodes.size) {
        errors.barcodes = 'Duplicate barcodes are not allowed.';
        firstErrorTab ||= 'barcodes';
    }

    clientErrors.value = errors;
    if (firstErrorTab) {
        activeTab.value = firstErrorTab;
    }

    return !Object.keys(errors).length;
};

const saveProduct = () => {
    attemptedSave.value = true;
    fillSmartFields(false);

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

        category_id: /^\d+$/.test(String(form.category_id || '')) ? form.category_id : null,
        sub_category_id: /^\d+$/.test(String(form.sub_category_id || '')) ? form.sub_category_id : null,
        brand_id: /^\d+$/.test(String(form.brand_id || '')) ? form.brand_id : null,
        category: form.category || selectedOption(categoryOptions.value, form.category_id)?.label || null,
        subcategory: form.subcategory || selectedOption(subCategoryOptions.value, form.sub_category_id)?.label || null,
        brand: form.brand || selectedOption(brandOptions.value, form.brand_id)?.label || null,
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

                                <div class="field-help tab-purpose-help">
                                    <span class="help-icon">i</span>

                                    <span>
                                        Use this tab to define the product identity used across bills, stock reports and searches. Enter the product name, SKU, unit, category, brand and short description here.
                                    </span>
                                </div>

                                <div class="smart-fill-box">
                                    <div>
                                        <strong>Smart Fill</strong>
                                        <span>
                                            Suggests short name, SKU, brand,
                                            category, variant and description
                                            from the product name.
                                        </span>
                                    </div>

                                    <div class="smart-fill-actions">
                                        <button
                                            type="button"
                                            :disabled="!canSmartFill"
                                            @click="fillSmartFields(false)"
                                        >
                                            Fill Empty
                                        </button>

                                        <button
                                            type="button"
                                            :disabled="!canSmartFill"
                                            @click="fillSmartFields(true)"
                                        >
                                            Regenerate
                                        </button>
                                    </div>
                                </div>

                                <div
                                    v-if="canSmartFill"
                                    class="suggestion-chips"
                                >
                                    <button
                                        v-if="suggestedSku"
                                        type="button"
                                        @click="form.sku = suggestedSku"
                                    >
                                        SKU: {{ suggestedSku }}
                                    </button>

                                    <button
                                        v-if="suggestedVariant"
                                        type="button"
                                        @click="form.variant = suggestedVariant"
                                    >
                                        Variant: {{ suggestedVariant }}
                                    </button>

                                    <button
                                        type="button"
                                        @click="form.description = suggestedDescription"
                                    >
                                        Description
                                    </button>
                                </div>

                                <div class="form-grid">
                                    <FormInput
                                        v-model="form.name"
                                        name="name"
                                        label="Product Name"
                                        placeholder="Example: Samsung Galaxy S25"
                                        hint="This product name appears on bills, purchases, stock reports and search results."
                                        cls="product-field field-span-2"
                                        :req="true"
                                    />

                                    <FormInput
                                        v-model="form.short_name"
                                        name="short_name"
                                        label="Short Name"
                                        placeholder="Invoice display name"
                                        hint="Use this when invoices or compact lists need a shorter display name."
                                        cls="product-field"
                                    />

                                    <FormSelect
                                        v-model="form.product_type"
                                        name="product_type"
                                        label="Product Type"
                                        cls="product-field"
                                        :options="productTypeOptions"
                                        select_name="Select product type"
                                        hint="Goods can be tracked in stock. Services are used for billing and do not maintain stock."
                                        :req="true"
                                    />

                                    <FormSelect
                                        v-model="form.item_type"
                                        name="item_type"
                                        label="Item Type"
                                        cls="product-field"
                                        :options="itemTypeOptions"
                                        select_name="Select item type"
                                        hint="Stock items maintain inventory balances. Non-stock items are used only on billing or purchase lines."
                                        :req="true"
                                    />

                                    <FormSelect
                                        v-model="form.unit"
                                        name="unit"
                                        label="Unit"
                                        cls="product-field"
                                        :options="unitOptions"
                                        select_name="Select unit"
                                        hint="Select the unit used for billing and stock, such as PCS, KG, LTR or HRS."
                                        :req="true"
                                    />

                                    <FormInput
                                        v-model="form.sku"
                                        name="sku"
                                        label="SKU"
                                        placeholder="Example: SG25-256-BLK"
                                        hint="Unique item code. Duplicate SKUs cannot be saved in the same business."
                                        cls="product-field"
                                        :req="true"
                                    />

                                    <span
                                        v-if="fieldError('sku')"
                                        class="field-error product-field"
                                    >
                                        {{ fieldError('sku') }}
                                    </span>

                                    <FormSelect
                                        v-model="form.category_id"
                                        name="category_id"
                                        label="Category"
                                        cls="product-field"
                                        :options="categoryOptions"
                                        select_name="Select category"
                                        hint="Use categories to group products, such as Mobile, Grocery or Service."
                                    />

                                    <FormSelect
                                        v-model="form.sub_category_id"
                                        name="sub_category_id"
                                        label="Sub Category"
                                        cls="product-field"
                                        :options="subCategoryOptions"
                                        select_name="Select sub category"
                                        hint="Use subcategories for detailed grouping, such as Charger, Cable or Spare Part."
                                    />

                                    <FormSelect
                                        v-model="form.brand_id"
                                        name="brand_id"
                                        label="Brand"
                                        cls="product-field"
                                        :options="brandOptions"
                                        select_name="Select brand"
                                        hint="Manufacturer or brand name. The selected brand text will be saved automatically."
                                    />

                                    <FormInput
                                        v-model="form.variant"
                                        name="variant"
                                        label="Variant"
                                        placeholder="Example: 256GB / Black"
                                        hint="Use this for size, color, storage or pack details."
                                        cls="product-field field-span-2"
                                    />

                                    <FormText
                                        v-model="form.description"
                                        name="description"
                                        label="Description"
                                        placeholder="Internal product description"
                                        hint="Internal notes and search help. Invoice text is managed separately on the GST tab."
                                        cls="product-field field-span-2"
                                        :rows="3"
                                    />
                                </div>
                            </section>

                            <!-- Barcodes -->
                            <section
                                v-show="activeTab === 'barcodes'"
                                class="product-section"
                            >
                                <div class="section-header">
                                    <div class="section-number">
                                        05
                                    </div>

                                    <div>
                                        <h3>Barcodes</h3>

                                        <p>
                                            Add primary and alternate scanner-ready
                                            barcode details.
                                        </p>
                                    </div>
                                </div>

                                <div class="field-help tab-purpose-help">
                                    <span class="help-icon">i</span>

                                    <span>
                                        Use this tab to manage scanner barcodes for the product. The primary barcode is used first during billing, stock entry and barcode label printing.
                                    </span>
                                </div>

                                <div
                                    v-if="canSmartFill"
                                    class="suggestion-chips"
                                >
                                    <button
                                        type="button"
                                        @click="applySuggestedBarcode(false)"
                                    >
                                        Suggested barcode: {{ suggestedBarcode }}
                                    </button>

                                    <button
                                        type="button"
                                        @click="applySuggestedBarcode(true)"
                                    >
                                        Regenerate barcode
                                    </button>
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
                                            The first barcode is treated as the
                                            primary barcode. Additional barcodes
                                            can be added when needed.
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
                                        <h3>GST & {{ hsnSacLabel }} Details</h3>

                                        <p>
                                            Product tax classification and
                                            invoice taxation settings.
                                        </p>
                                    </div>
                                </div>

                                <div class="field-help tab-purpose-help">
                                    <span class="help-icon">i</span>

                                    <span>
                                        Use this tab to set tax classification for invoices and reports. Goods use HSN Code, services use SAC Code, and GST rate is applied during billing.
                                    </span>
                                </div>

                                <div class="form-grid">
                                    <div class="product-field hsn-search-field">
                                        <label>
                                            {{ hsnSacLabel }}
                                            <span
                                                v-if="isTaxable"
                                                class="required-mark"
                                            >*</span>
                                        </label>

                                        <div class="hsn-input-row">
                                            <input
                                            v-model="hsnSearch"
                                            type="text"
                                            :class="['form-control', { 'is-invalid': fieldError('hsn_code') }]"
                                            :placeholder="`Type product name or ${hsnSacLabel}`"
                                            @input="searchHsn"
                                            @focus="suggestHsnFromProduct"
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
                                                    {{ hsn.code_type || hsnSacLabel }} {{ hsn.hsn_code }}
                                                </strong>

                                                <span>
                                                    {{ hsn.description }}
                                                </span>

                                                <small>
                                                    {{ hsn.taxability || 'taxable' }} |
                                                    {{ hsn.gst_rate === null || hsn.gst_rate === undefined ? 'Rate pending' : `${Number(hsn.gst_rate || 0)}% GST` }}
                                                    <template v-if="hsn.rate_verified"> | Verified</template>
                                                    <template v-else-if="hsn.gst_rate !== null && hsn.gst_rate !== undefined"> | Suggested</template>
                                                </small>
                                            </button>
                                        </div>

                                        <span
                                            v-if="fieldError('hsn_code')"
                                            class="field-error"
                                        >
                                            {{ fieldError('hsn_code') }}
                                        </span>

                                        <span class="field-hint">
                                            Product name/category se HSN/SAC Master suggestions aayenge. Manual code bhi enter kar sakte hain; master select karne par GST rate auto-fill ho jayega.
                                        </span>
                                    </div>

                                    <div
                                        v-if="selectedHsn"
                                        class="field-help field-span-2"
                                    >
                                        <span class="help-icon">i</span>

                                        <span>
                                            {{ selectedHsn.code_type || hsnSacLabel }} {{ selectedHsn.hsn_code }} - {{ selectedHsn.description }}. One classification can be linked with many products.
                                            <template v-if="form.tax_source === 'master_suggested'"> GST rate is suggested from master and can be verified from HSN/SAC Master.</template>
                                        </span>
                                    </div>

                                    <div
                                        v-else-if="form.hsn_code"
                                        class="field-help manual-hsn field-span-2"
                                    >
                                        <span class="help-icon">i</span>

                                        <span>
                                            Manual {{ hsnSacLabel }} {{ form.hsn_code }} use ho raha hai. Master select karenge to GST rate auto-fill ho jayega.
                                        </span>
                                    </div>

                                    <FormSelect
                                        v-model="form.taxability"
                                        name="taxability"
                                        label="Taxability"
                                        cls="product-field"
                                        :options="taxabilityOptions"
                                        select_name="Select taxability"
                                        :disabled="hsnTaxLocked"
                                        hint="Taxable items charge GST. Exempt, nil-rated and non-GST items use a 0% GST rate."
                                        :req="true"
                                    />

                                    <FormSelect
                                        v-model="form.gst_rate"
                                        name="gst_rate"
                                        label="GST Rate"
                                        cls="product-field"
                                        :options="gstRateOptions"
                                        select_name="Select GST rate"
                                        :disabled="hsnTaxLocked || !canEditCurrentGstRate"
                                        hint="This GST percentage is applied to the item amount during billing."
                                        :req="isTaxable"
                                    />

                                    <div
                                        v-if="hsnTaxLocked || !canEditCurrentGstRate"
                                        class="field-help"
                                    >
                                        <span class="help-icon">i</span>

                                        <span>
                                            {{ form.tax_source === 'verified_rule' ? 'Verified tax rule applied.' : (form.tax_source === 'master_suggested' ? 'GST rate filled from HSN/SAC Master suggestion.' : 'Manual tax confirmation required.') }}
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
                                        :disabled="hsnTaxLocked"
                                        hint="Optional compensation cess percentage applied in addition to GST."
                                    />

                                    <FormSelect
                                        v-model="form.reverse_charge"
                                        name="reverse_charge"
                                        label="Reverse Charge"
                                        cls="product-field"
                                        :options="reverseChargeOptions"
                                        select_name="Select option"
                                        hint="Under reverse charge, tax liability is reported on the buyer or customer side."
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

                                        <small class="toggle-hint">When enabled, the entered selling price is treated as GST-inclusive.</small>
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
                                        hint="Description shown on the customer invoice line. It can be different from the internal product description."
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
                                        02
                                    </div>

                                    <div>
                                        <h3>Pricing Details</h3>

                                        <p>
                                            Configure purchase cost, selling
                                            price and printed MRP.
                                        </p>
                                    </div>
                                </div>

                                <div class="field-help tab-purpose-help">
                                    <span class="help-icon">i</span>

                                    <span>
                                        Use this tab to maintain cost, selling price, MRP and customer price levels. Profit and margin values are calculated automatically for review.
                                    </span>
                                </div>

                                <div class="form-grid pricing-grid">
                                    <FormInput
                                        v-model="form.cost_price"
                                        name="cost_price"
                                        type="number"
                                        label="Cost Price"
                                        placeholder="Enter Cost Price"
                                        cls="product-field"
                                        left_box_text="Rs."
                                        hint="Purchase or landing cost. Profit and margin are calculated from this value."
                                        :req="true"
                                    />

                                    <FormInput
                                        v-model="form.selling_price"
                                        name="selling_price"
                                        type="number"
                                        label="Selling Price"
                                        placeholder="Enter Selling Price"
                                        cls="product-field"
                                        left_box_text="Rs."
                                        hint="Default customer selling rate. When MRP is set, selling price cannot be higher than MRP."
                                        :req="true"
                                    />

                                    <div
                                        v-if="fieldError('cost_price')"
                                        class="field-error"
                                    >
                                        {{ fieldError('cost_price') }}
                                    </div>

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
                                        placeholder="Enter MRP (Optional)"
                                        cls="product-field"
                                        left_box_text="Rs."
                                        hint="Printed Maximum Retail Price. Used to validate selling and online prices."
                                    />

                                    <FormInput
                                        v-model="form.wholesale_price"
                                        name="wholesale_price"
                                        type="number"
                                        label="Wholesale Price"
                                        placeholder="Enter Wholesale Price"
                                        cls="product-field"
                                        left_box_text="Rs."
                                        hint="Optional price level for bulk or wholesale customers."
                                    />

                                    <div
                                        v-if="fieldError('wholesale_price')"
                                        class="field-error"
                                    >
                                        {{ fieldError('wholesale_price') }}
                                    </div>

                                    <FormInput
                                        v-model="form.dealer_price"
                                        name="dealer_price"
                                        type="number"
                                        label="Dealer Price"
                                        placeholder="Enter Dealer Price"
                                        cls="product-field"
                                        left_box_text="Rs."
                                        hint="Optional price level for dealer or distributor customers."
                                    />

                                    <div
                                        v-if="fieldError('dealer_price')"
                                        class="field-error"
                                    >
                                        {{ fieldError('dealer_price') }}
                                    </div>

                                    <FormInput
                                        v-model="form.online_price"
                                        name="online_price"
                                        type="number"
                                        label="Online Price"
                                        placeholder="Enter Online Price"
                                        cls="product-field"
                                        left_box_text="Rs."
                                        hint="Optional price level for website or marketplace sales."
                                    />

                                    <div
                                        v-if="fieldError('online_price')"
                                        class="field-error"
                                    >
                                        {{ fieldError('online_price') }}
                                    </div>
                                </div>

                                <div class="pricing-panel">
                                    <div class="pricing-status-card" :class="pricingStatus.tone">
                                        <span class="status-label">{{ pricingStatus.label }}</span>
                                        <strong>{{ pricingStatus.description }}</strong>
                                    </div>

                                    <div class="pricing-statistics">
                                        <div class="pricing-card">
                                            <span>Profit Amount</span>
                                            <strong :class="{ 'positive': profitAmount >= 0, 'negative': profitAmount < 0 }">
                                                Rs. {{ formatMoney(profitAmount) }}
                                            </strong>
                                        </div>

                                        <div class="pricing-card">
                                            <span>Profit %</span>
                                            <strong :class="{ 'positive': profitPercent >= 0, 'negative': profitPercent < 0 }">
                                                {{ formatMoney(profitPercent) }}%
                                            </strong>
                                        </div>

                                        <div class="pricing-card">
                                            <span>Margin %</span>
                                            <strong :class="{ 'positive': marginPercent >= 0, 'negative': marginPercent < 0 }">
                                                {{ formatMoney(marginPercent) }}%
                                            </strong>
                                        </div>
                                    </div>

                                    <div class="pricing-summary-card">
                                        <h4>Pricing Summary</h4>

                                        <div class="pricing-summary-row">
                                            <span>Cost Price</span>
                                            <strong>Rs. {{ formatMoney(numericCostPrice) }}</strong>
                                        </div>

                                        <div class="pricing-summary-row">
                                            <span>Selling Price</span>
                                            <strong>Rs. {{ formatMoney(numericSellingPrice) }}</strong>
                                        </div>

                                        <div class="pricing-summary-row">
                                            <span>Profit</span>
                                            <strong>Rs. {{ formatMoney(profitAmount) }}</strong>
                                        </div>

                                        <div class="pricing-summary-row">
                                            <span>Profit %</span>
                                            <strong>{{ formatMoney(profitPercent) }}%</strong>
                                        </div>

                                        <div class="pricing-summary-row">
                                            <span>Margin %</span>
                                            <strong>{{ formatMoney(marginPercent) }}%</strong>
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
                                        04
                                    </div>

                                    <div>
                                        <h3>Inventory Settings</h3>

                                        <p>
                                            Stock alerts and product tracking
                                            settings.
                                        </p>
                                    </div>
                                </div>

                                <div class="field-help tab-purpose-help">
                                    <span class="help-icon">i</span>

                                    <span>
                                        Use this tab to configure stock alerts and tracking rules. Opening Stock is handled through Opening Stock Entry or Stock Transactions, not from Product Master.
                                    </span>
                                </div>

                                <div class="form-grid">
                                    <FormInput
                                        v-model="form.minimum_stock"
                                        name="minimum_stock"
                                        type="number"
                                        label="Minimum Stock"
                                        placeholder="Example: 5"
                                        hint="Low-stock alerts and report warnings are shown when stock falls below this quantity."
                                        cls="product-field"
                                    />

                                    <div
                                        v-if="fieldError('minimum_stock')"
                                        class="field-error"
                                    >
                                        {{ fieldError('minimum_stock') }}
                                    </div>

                                    <FormInput
                                        v-model="form.reorder_stock"
                                        name="reorder_stock"
                                        type="number"
                                        label="Reorder Stock"
                                        placeholder="Example: 20"
                                        hint="Recommended purchase or order quantity when stock reaches the minimum level."
                                        cls="product-field"
                                    />

                                    <div
                                        v-if="fieldError('reorder_stock')"
                                        class="field-error"
                                    >
                                        {{ fieldError('reorder_stock') }}
                                    </div>

                                    <FormInput
                                        v-model="form.maximum_stock"
                                        name="maximum_stock"
                                        type="number"
                                        label="Maximum Stock"
                                        placeholder="Example: 100"
                                        hint="Recommended upper stock limit. Used for overstock checks and reporting."
                                        cls="product-field"
                                    />

                                    <div
                                        v-if="fieldError('maximum_stock')"
                                        class="field-error"
                                    >
                                        {{ fieldError('maximum_stock') }}
                                    </div>

                                    <FormSelect
                                        v-model="form.tracking_type"
                                        name="tracking_type"
                                        label="Tracking Method"
                                        cls="product-field"
                                        :options="trackingOptions"
                                        select_name="Select tracking"
                                        hint="Select this when stock must be traced by batch, expiry date, serial number or IMEI."
                                        :req="true"
                                    />

                                    <div class="inventory-explanation field-span-2">
                                        <div>
                                            <strong>Opening Stock</strong>

                                            <span>
                                                Opening Stock is managed through
                                                Opening Stock Entry or Stock
                                                Transactions. Current stock
                                                should never be edited directly
                                                from Product Master.
                                            </span>
                                        </div>

                                        <div>
                                            <strong>Minimum Stock</strong>

                                            <span>
                                                Low stock warning level.
                                            </span>
                                        </div>

                                        <div>
                                            <strong>Reorder Stock</strong>

                                            <span>
                                                Recommended quantity to purchase
                                                when stock reaches the minimum
                                                level.
                                            </span>
                                        </div>

                                        <div>
                                            <strong>Maximum Stock</strong>

                                            <span>
                                                Recommended maximum inventory
                                                quantity.
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
                                        06
                                    </div>

                                    <div>
                                        <h3>Images</h3>

                                        <p>
                                            Store the main product image and
                                            multiple gallery images.
                                        </p>
                                    </div>
                                </div>

                                <div class="field-help tab-purpose-help">
                                    <span class="help-icon">i</span>

                                    <span>
                                        Use this tab to upload the main product image and gallery images. Images appear in the product list and can be opened for quick verification.
                                    </span>
                                </div>

                                <div class="repeat-list">
                                    <div
                                        v-for="(image, index) in images"
                                        :key="index"
                                        class="repeat-row image-row"
                                    >
                                        <label class="radio-field">
                                            <input
                                                :checked="image.is_primary"
                                                type="checkbox"
                                                @change="setPrimaryImage(index)"
                                            />

                                            <span>{{ index === 0 ? 'Main Product Image' : 'Gallery Image' }}</span>
                                        </label>

                                        <div class="image-upload-field">
                                            <button
                                                type="button"
                                                class="image-preview"
                                                :class="{ empty: !imagePreviewUrl(image) }"
                                                :disabled="!imagePreviewUrl(image)"
                                                @click="openImage(image)"
                                            >
                                                <img
                                                    v-if="imagePreviewUrl(image)"
                                                    :src="imagePreviewUrl(image)"
                                                    alt=""
                                                />

                                                <span v-else>No Image</span>
                                            </button>

                                            <div class="image-path-wrap">
                                                <div class="image-actions">
                                                    <label class="image-upload-button">
                                                        <input
                                                            class="image-file-input"
                                                            type="file"
                                                            accept="image/*"
                                                            :disabled="imageUploads[index]?.uploading"
                                                            @change="uploadImage($event, index)"
                                                        />

                                                        <span>
                                                            {{ imageUploads[index]?.uploading ? 'Uploading...' : image.image_path ? 'Replace Image' : 'Upload Image' }}
                                                        </span>
                                                    </label>

                                                    <button
                                                        v-if="imagePreviewUrl(image)"
                                                        type="button"
                                                        class="image-open-button"
                                                        :disabled="imageUploads[index]?.uploading"
                                                        @click="openImage(image)"
                                                    >
                                                        Open Image
                                                    </button>

                                                    <button
                                                        v-if="imagePreviewUrl(image)"
                                                        type="button"
                                                        class="image-clear-button"
                                                        :disabled="imageUploads[index]?.uploading"
                                                        @click="clearImage(index)"
                                                    >
                                                        Clear
                                                    </button>
                                                </div>

                                                <span
                                                    v-if="imageUploads[index]?.uploading"
                                                    class="image-upload-note"
                                                >
                                                    Uploading...
                                                </span>

                                                <span
                                                    v-if="imageUploads[index]?.error"
                                                    class="field-error"
                                                >
                                                    {{ imageUploads[index].error }}
                                                </span>
                                            </div>
                                        </div>

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
                                        07
                                    </div>

                                    <div>
                                        <h3>Advanced Product Settings</h3>

                                        <p>
                                            Additional tracking and product
                                            configuration.
                                        </p>
                                    </div>
                                </div>

                                <div class="field-help tab-purpose-help">
                                    <span class="help-icon">i</span>

                                    <span>
                                        Use this tab for additional product configuration such as dimensions, batch or serial tracking requirements and product availability status.
                                    </span>
                                </div>

                                <div class="form-grid">
                                    <FormInput
                                        v-model="form.weight"
                                        name="weight"
                                        type="number"
                                        label="Weight"
                                        placeholder="0.000"
                                        hint="Product weight used for shipping, packing and logistics calculations."
                                        cls="product-field"
                                    />

                                    <FormInput
                                        v-model="form.length"
                                        name="length"
                                        type="number"
                                        label="Length"
                                        placeholder="0.000"
                                        hint="Package or product length used for logistics and catalog details."
                                        cls="product-field"
                                    />

                                    <FormInput
                                        v-model="form.width"
                                        name="width"
                                        type="number"
                                        label="Width"
                                        placeholder="0.000"
                                        hint="Package or product width used for logistics and catalog details."
                                        cls="product-field"
                                    />

                                    <FormInput
                                        v-model="form.height"
                                        name="height"
                                        type="number"
                                        label="Height"
                                        placeholder="0.000"
                                        hint="Package or product height used for logistics and catalog details."
                                        cls="product-field"
                                    />

                                    <label class="toggle-field">
                                        <input
                                            v-model="form.batch_required"
                                            type="checkbox"
                                        />

                                        <span>Batch Required</span>

                                        <small class="toggle-hint">When enabled, stock must be received and sold with batch numbers.</small>
                                    </label>

                                    <label class="toggle-field">
                                        <input
                                            v-model="form.expiry_required"
                                            type="checkbox"
                                        />

                                        <span>Expiry Required</span>

                                        <small class="toggle-hint">When enabled, batch expiry dates must be captured and reported.</small>
                                    </label>

                                    <label class="toggle-field">
                                        <input
                                            v-model="form.serial_required"
                                            type="checkbox"
                                        />

                                        <span>Serial Number Required</span>

                                        <small class="toggle-hint">When enabled, each piece is tracked by serial number or IMEI.</small>
                                    </label>

                                    <FormSelect
                                        v-model="form.status"
                                        name="status"
                                        label="Status"
                                        cls="product-field"
                                        :options="statusOptions"
                                        select_name="Select status"
                                        hint="Active products are available for billing. Inactive or discontinued products can be hidden from new bills."
                                        :req="true"
                                    />

                                    <div
                                        class="status-preview"
                                        :class="{
                                            inactive:
                                                form.status !== 'active',
                                        }"
                                    >
                                        <span class="status-dot"></span>

                                        <div>
                                            <strong>
                                                {{
                                                    form.status === 'active'
                                                        ? 'Active Product'
                                                        : form.status === 'discontinued'
                                                            ? 'Discontinued Product'
                                                            : 'Inactive Product'
                                                }}
                                            </strong>

                                            <small>
                                                {{
                                                    form.status === 'active'
                                                        ? 'Product can be selected during billing.'
                                                        : form.status === 'discontinued'
                                                            ? 'Product is discontinued and unavailable for new bills.'
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

                                <button
                                    class="btn product-save-button"
                                    type="button"
                                    :disabled="processing"
                                    @click="saveProduct"
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
                                    <span
                                        v-if="processing"
                                        class="spinner-border text-white spinner-border-sm"
                                        role="status"
                                        style="margin-left: 5px"
                                    ></span>
                                </button>
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

.smart-fill-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin: -3px 0 18px;
    padding: 13px 14px;
    background: #f7faff;
    border: 1px solid #dbe6fb;
    border-radius: 12px;
}

.smart-fill-box strong,
.smart-fill-box span {
    display: block;
}

.smart-fill-box strong {
    color: #17233b;
    font-size: 12px;
    font-weight: 800;
}

.smart-fill-box span {
    margin-top: 3px;
    color: #69758a;
    font-size: 11px;
}

.smart-fill-actions,
.suggestion-chips {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.smart-fill-actions button,
.suggestion-chips button {
    min-height: 34px;
    padding: 7px 11px;
    color: #2457d6;
    background: #ffffff;
    border: 1px solid #cfdcff;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 800;
    cursor: pointer;
}

.smart-fill-actions button:first-child {
    color: #ffffff;
    background: #2457d6;
    border-color: #2457d6;
}

.smart-fill-actions button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}

.suggestion-chips {
    margin: -8px 0 18px;
}

.suggestion-chips button {
    color: #344159;
    background: #ffffff;
    border-color: #dce3ec;
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

:deep(.product-field .required-mark),
.required-mark {
    display: inline;
    margin-left: 3px;
    color: #dc2626;
    font-weight: 900;
}

:deep(.product-field .form-control.is-invalid),
.hsn-search-field .form-control.is-invalid {
    color: #7f1d1d;
    background: #fffafa;
    border-color: #dc2626;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
}

:deep(.product-field .form-control.is-invalid:focus),
.hsn-search-field .form-control.is-invalid:focus {
    border-color: #b91c1c;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.16);
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

:deep(.product-field .field-error),
.field-error {
    display: block;
    margin-top: 6px;
    color: #dc2626;
    font-size: 11px !important;
    font-weight: 700;
}

:deep(.product-field .field-hint) {
    display: block;
    margin-top: 6px;
    color: #6b778c;
    font-size: 10.5px;
    line-height: 1.45;
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

.manual-hsn {
    color: #43536d;
    background: #fffaf0;
    border-color: #f4d48b;
}

.tab-purpose-help {
    margin: -3px 0 18px;
}

.field-error {
    color: #d83946;
    font-size: 11px;
    font-weight: 700;
}

.pricing-panel {
    margin-top: 22px;
    display: grid;
    gap: 18px;
}

.pricing-status-card {
    padding: 16px 18px;
    border-radius: 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 13px;
    border: 1px solid transparent;
}

.pricing-status-card.success {
    background: #ecfdf5;
    border-color: #a7f3d0;
    color: #166534;
}

.pricing-status-card.warning {
    background: #fef3c7;
    border-color: #fde68a;
    color: #92400e;
}

.pricing-status-card.error {
    background: #fee2e2;
    border-color: #fecaca;
    color: #991b1b;
}

.pricing-status-card.neutral {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
}

.status-label {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.pricing-statistics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.pricing-card {
    padding: 16px;
    border-radius: 14px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 6px 24px rgba(15, 23, 42, 0.04);
}

.pricing-card span {
    display: block;
    color: #64748b;
    font-size: 12px;
    margin-bottom: 10px;
}

.pricing-card strong {
    display: block;
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
}

.pricing-card strong.positive {
    color: #166534;
}

.pricing-card strong.negative {
    color: #b91c1c;
}

.pricing-summary-card {
    padding: 20px;
    border-radius: 16px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    display: grid;
    gap: 12px;
}

.pricing-summary-card h4 {
    margin: 0;
    font-size: 14px;
    color: #0f172a;
    letter-spacing: 0.02em;
    text-transform: uppercase;
}

.pricing-summary-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    color: #475569;
    font-size: 13px;
}

.pricing-summary-row strong {
    color: #0f172a;
    font-weight: 700;
}

@media (max-width: 900px) {
    .pricing-statistics {
        grid-template-columns: 1fr;
    }
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
    grid-template-columns: 96px minmax(0, 1fr) 86px;
}

.image-upload-field {
    display: grid;
    grid-template-columns: 72px minmax(0, 1fr);
    gap: 12px;
    align-items: center;
    min-width: 0;
}

.image-preview {
    width: 72px;
    height: 58px;
    overflow: hidden;
    background: #eef4ff;
    border: 1px solid #d8e0eb;
    border-radius: 8px;
    cursor: pointer;
    padding: 0;
}

.image-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.image-preview.empty {
    display: grid;
    place-items: center;
    color: #8b98ad;
    font-size: 11px;
    font-weight: 800;
    cursor: default;
}

.image-path-wrap {
    display: grid;
    gap: 6px;
    min-width: 0;
}

.image-actions {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}

.image-upload-button,
.image-open-button,
.image-clear-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 34px;
    width: max-content;
    padding: 7px 11px;
    color: #2457d6;
    background: #ffffff;
    border: 1px solid #cfdcff;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 800;
    cursor: pointer;
}

.image-open-button {
    color: #344159;
    border-color: #d8e0eb;
}

.image-clear-button {
    color: #d83946;
    border-color: #ffd2d2;
}

.image-file-input {
    display: none;
}

.image-upload-note {
    color: #66738a;
    font-size: 11px;
    font-weight: 700;
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
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 3px 9px;
    align-items: center;
    padding: 11px 13px;
    color: #344159;
    background: #f7f9fc;
    border: 1px solid #dfe6ef;
    border-radius: 9px;
    font-size: 12px;
    font-weight: 700;
}

.toggle-field input {
    grid-row: span 2;
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

.toggle-hint {
    color: #778399;
    font-size: 10px;
    font-weight: 600;
    line-height: 1.35;
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

    .smart-fill-box {
        align-items: stretch;
        flex-direction: column;
    }

    .smart-fill-actions button {
        flex: 1;
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
