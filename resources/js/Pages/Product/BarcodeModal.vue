<script setup>
const props = defineProps({
    modelValue: Boolean,
    product: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits([
    'update:modelValue',
]);

const close = () => {
    emit('update:modelValue', false);
};

const printBarcode = () => {
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
                    <span>PRODUCT BARCODE</span>
                    <h3>{{ product.name || 'Barcode' }}</h3>
                    <p>SKU: {{ product.sku || '-' }}</p>
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
                <div class="barcode-card">
                    <strong>{{ product.name || '-' }}</strong>
                    <small>{{ product.primary_barcode || product.barcode || '-' }}</small>

                    <div class="barcode-lines">
                        ||||| ||||| |||||
                    </div>
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
                    @click="printBarcode"
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
    width: min(480px, 94vw);
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
    padding: 24px;
    background: #f8fafc;
}

.barcode-card {
    display: grid;
    justify-items: center;
    padding: 22px;
    color: #17233b;
    background: #ffffff;
    border: 1px dashed #b9c4d3;
    border-radius: 8px;
    text-align: center;
}

.barcode-card strong {
    max-width: 100%;
    overflow: hidden;
    font-size: 17px;
    font-weight: 800;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.barcode-card small {
    margin-top: 5px;
    color: #536179;
    font-size: 14px;
    font-weight: 800;
}

.barcode-lines {
    margin-top: 18px;
    color: #142038;
    font-size: 42px;
    font-weight: 800;
    letter-spacing: 5px;
    line-height: 1;
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
