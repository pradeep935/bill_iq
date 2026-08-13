<script setup>
import { computed } from 'vue';
import { code128SvgMarkup } from './barcodeRenderer';

const props = defineProps({ value: { type: String, default: '' }, title: { type: String, default: '' }, subtitle: { type: String, default: '' }, price: { type: [String, Number], default: '' } });
const barcodeSvg = computed(() => {
    try {
        return code128SvgMarkup(props.value, { moduleWidth: 2, height: 58, quietZone: 12 });
    } catch {
        return '';
    }
});
</script>

<template>
    <article class="barcode-preview">
        <strong>{{ title || 'Barcode' }}</strong>
        <small>{{ subtitle }}</small>
        <div v-if="barcodeSvg" class="barcode-graphic" v-html="barcodeSvg"></div>
        <div v-else class="barcode-empty">{{ value ? 'Unsupported barcode text' : 'No Barcode' }}</div>
        <span>{{ value || '-' }}</span>
        <b v-if="price !== ''">Rs. {{ price }}</b>
    </article>
</template>

<style scoped>
.barcode-preview{align-content:center;background:#fff;border:1px dashed #b9c4d3;border-radius:8px;color:#17233b;display:grid;justify-items:center;min-height:136px;padding:14px 12px;text-align:center}.barcode-preview strong{font-size:14px;font-weight:800;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.barcode-preview small{color:#536179;font-size:11px;font-weight:700;margin-top:3px}.barcode-graphic{height:46px;margin:10px 0 6px;max-width:240px;width:88%}.barcode-graphic :deep(svg){display:block;height:100%;width:100%}.barcode-empty{align-items:center;background:#f8fafc;border:1px dashed #d8e0eb;color:#8b98ac;display:flex;font-size:11px;font-weight:800;height:42px;justify-content:center;margin:10px 0 6px;max-width:220px;width:80%}.barcode-preview span{font-size:15px;font-weight:800;letter-spacing:.5px}.barcode-preview b{margin-top:4px}
</style>
