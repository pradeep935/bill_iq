<script setup>
const props = defineProps({
    modelValue: Boolean,
    products: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits([
    'update:modelValue'
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

    <div class="modal-box large">

        <div class="modal-header">

            <h3>Print Barcode Labels</h3>

            <button @click="close">
                ✕
            </button>

        </div>

        <div class="modal-body">

            <div class="label-grid">

                <div
                    class="label-card"
                    v-for="product in products"
                    :key="product.id"
                >

                    <strong>
                        {{ product.name }}
                    </strong>

                    <small>
                        SKU : {{ product.sku }}
                    </small>

                    <div class="barcode">
                        ||||| ||||| |||||
                    </div>

                    <div>
                        {{ product.primary_barcode }}
                    </div>

                    <strong>
                        ₹ {{ product.selling_price }}
                    </strong>

                </div>

            </div>

        </div>

        <div class="modal-footer">

            <button @click="close">
                Close
            </button>

            <button
                class="btn btn-primary"
                @click="printLabels"
            >
                Print
            </button>

        </div>

    </div>

</div>

</template>

<style scoped>

.modal-backdrop{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.4);
    display:flex;
    justify-content:center;
    align-items:center;
    z-index:1000;
}

.modal-box{
    background:#fff;
    border-radius:8px;
    width:900px;
    max-height:90vh;
    overflow:auto;
    padding:20px;
}

.modal-header,
.modal-footer{
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.label-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:15px;
    margin-top:20px;
}

.label-card{
    border:1px solid #ddd;
    padding:10px;
    text-align:center;
    border-radius:6px;
}

.barcode{
    font-size:26px;
    letter-spacing:3px;
    margin:10px 0;
}

</style>