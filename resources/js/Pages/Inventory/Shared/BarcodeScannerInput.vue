<script setup>
import { ref } from 'vue';

defineProps({ placeholder: { type: String, default: 'Scan barcode and press Enter' } });
const emit = defineEmits(['scan']);
const value = ref('');
let lastScan = '';
let lastAt = 0;

const submit = () => {
    const now = Date.now();
    const scanned = value.value.trim();
    if (!scanned) return;
    if (scanned === lastScan && now - lastAt < 800) return;
    lastScan = scanned;
    lastAt = now;
    emit('scan', scanned);
    value.value = '';
};
</script>

<template>
    <input v-model="value" :placeholder="placeholder" autocomplete="off" @keyup.enter="submit" />
</template>
