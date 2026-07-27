<script setup>
const props = defineProps({
    modelValue: Boolean,
    products: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits([
    'update:modelValue',
]);

const close = () => {
    emit('update:modelValue', false);
};

const printLabels = () => {
    window.print();
};
</script>

<template>
    <div
        v-if="modelValue"
        class="modal-backdrop"
    >
        <section class="modal-box">
            <header class="modal-header">
                <div>
                    <span>BARCODE LABELS</span>
                    <h3>Print Barcode Labels</h3>
                    <p>{{ products.length }} label{{ products.length === 1 ? '' : 's' }} ready for printing.</p>
                </div>

                <button
                    type="button"
                    class="icon-button"
                    aria-label="Close"
                    @click="close"
                >
                    x
                </button>
            </header>

            <main class="modal-body">
                <div class="label-grid">
                    <article
                        v-for="product in products"
                        :key="product.id"
                        class="label-card"
                    >
                        <strong>{{ product.name }}</strong>
                        <small>SKU: {{ product.sku || '-' }}</small>

                        <div class="barcode-lines">
                            ||||| ||||| |||||
                        </div>

                        <div class="barcode-number">
                            {{ product.primary_barcode || product.barcode || '-' }}
                        </div>

                        <strong class="label-price">
                            Rs. {{ product.selling_price || 0 }}
                        </strong>
                    </article>
                </div>
            </main>

            <footer class="modal-footer">
                <button
                    type="button"
                    class="secondary-button"
                    @click="close"
                >
                    Close
                </button>

                <button
                    type="button"
                    class="primary-button"
                    @click="printLabels"
                >
                    Print
                </button>
            </footer>
        </section>
    </div>
</template>

<style scoped>
.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(15, 23, 42, 0.54);
}

.modal-box {
    width: min(760px, 94vw);
    max-height: 88vh;
    display: grid;
    grid-template-rows: auto minmax(0, 1fr) auto;
    overflow: hidden;
    background: #ffffff;
    border: 1px solid #dfe6ef;
    border-radius: 10px;
    box-shadow: 0 28px 70px rgba(15, 23, 42, 0.26);
}

.modal-header,
.modal-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 22px;
}

.modal-header {
    border-bottom: 1px solid #edf1f5;
}

.modal-header span {
    display: block;
    margin-bottom: 5px;
    color: #2457d6;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.8px;
}

.modal-header h3 {
    margin: 0;
    color: #142038;
    font-size: 20px;
    font-weight: 800;
}

.modal-header p {
    margin: 5px 0 0;
    color: #718096;
    font-size: 12px;
}

.icon-button {
    width: 34px;
    height: 34px;
    color: #536179;
    background: #f7f9fc;
    border: 1px solid #dce3ec;
    border-radius: 8px;
    cursor: pointer;
    font-size: 15px;
    font-weight: 800;
}

.modal-body {
    overflow: auto;
    padding: 22px;
    background: #f8fafc;
}

.label-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 14px;
}

.label-card {
    min-height: 130px;
    display: grid;
    align-content: center;
    justify-items: center;
    padding: 14px 12px;
    color: #17233b;
    background: #ffffff;
    border: 1px dashed #b9c4d3;
    border-radius: 8px;
    text-align: center;
}

.label-card strong {
    max-width: 100%;
    overflow: hidden;
    font-size: 14px;
    font-weight: 800;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.label-card small {
    margin-top: 3px;
    color: #536179;
    font-size: 11px;
    font-weight: 700;
}

.barcode-lines {
    margin: 9px 0 4px;
    color: #142038;
    font-size: 26px;
    font-weight: 800;
    letter-spacing: 4px;
    line-height: 1;
}

.barcode-number {
    color: #17233b;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: 0.5px;
}

.label-price {
    margin-top: 3px;
}

.modal-footer {
    border-top: 1px solid #edf1f5;
}

.primary-button,
.secondary-button {
    min-height: 38px;
    padding: 0 18px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 800;
}

.primary-button {
    color: #ffffff;
    background: #2d5be3;
    border: 1px solid #2d5be3;
}

.secondary-button {
    color: #344158;
    background: #ffffff;
    border: 1px solid #dce3ec;
}

@media print {
    .modal-backdrop {
        position: static;
        display: block;
        padding: 0;
        background: #ffffff;
    }

    .modal-box {
        width: 100%;
        max-height: none;
        border: 0;
        box-shadow: none;
    }

    .modal-header,
    .modal-footer {
        display: none;
    }

    .modal-body {
        padding: 0;
        background: #ffffff;
    }
}
</style>
