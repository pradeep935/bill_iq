<script setup>
const props = defineProps({
    modelValue: Boolean,
    product: {
        type: Object,
        default: () => ({})
    }
});

const emit = defineEmits([
    'update:modelValue'
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

    <div class="modal-box">

        <div class="modal-header">

            <h3>Barcode</h3>

            <button @click="close">
                ✕
            </button>

        </div>

        <div class="modal-body">

            <h4>{{ product.name }}</h4>

            <p>
                SKU :
                {{ product.sku }}
            </p>

            <p>
                Barcode :
                {{ product.primary_barcode }}
            </p>

            <div class="barcode">

                ||||| ||||| |||||

            </div>

        </div>

        <div class="modal-footer">

            <button @click="close">
                Close
            </button>

            <button
                class="btn btn-primary"
                @click="printBarcode"
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
}

.modal-box{
    width:500px;
    background:#fff;
    border-radius:8px;
    padding:20px;
}

.modal-header,
.modal-footer{
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.barcode{
    font-size:40px;
    letter-spacing:4px;
    text-align:center;
    margin:30px 0;
}

</style>