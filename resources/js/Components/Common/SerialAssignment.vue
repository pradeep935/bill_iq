<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
const props = defineProps({ modelValue: { type: Array, default: () => [] }, quantity: Number, scope: Object, purpose: { type: String, default: 'opening' }, readonly: Boolean, error: String });
const emit = defineEmits(['update:modelValue', 'error']);
const open = ref(false), candidates = ref([]), search = ref(''), pasted = ref(''), message = ref(''), loading = ref(false);
const normalize = value => String(value || '').toUpperCase().replace(/[\s-]+/g, '');
const remaining = computed(() => props.quantity - props.modelValue.length);
const valid = computed(() => Number.isInteger(props.quantity) && props.quantity > 0 && remaining.value === 0 && new Set(props.modelValue.map(s => normalize(s.serial_number))).size === props.modelValue.length);
const fail = text => { message.value = text; emit('error', text); };
let request = 0;
const load = async () => {
    const id = ++request; loading.value = true;
    try {
        const response = await axios.get(props.purpose === 'opening' ? '/app/inventory/opening-stock/serials' : '/app/sales/invoices/serials', { params: { ...props.scope, search: search.value } });
        if (id === request) candidates.value = response.data;
    } catch (error) { if (id === request) fail(error.response?.data?.message || 'Unable to load eligible serials.'); }
    finally { if (id === request) loading.value = false; }
};
const manage = () => { open.value = !open.value; if (open.value && !props.readonly) load(); };
const add = rows => {
    const merged = [...props.modelValue, ...rows];
    const keys = merged.map(row => normalize(row.serial_number));
    if (keys.some(key => !key) || new Set(keys).size !== keys.length) return fail('Each serial number must be nonempty and unique.');
    if (merged.some(row => row.serial_number.length > 120)) return fail('Serial numbers must be 120 characters or fewer.');
    message.value = ''; emit('update:modelValue', merged); pasted.value = '';
};
watch(() => JSON.stringify(props.scope), (scope, previous) => { candidates.value = []; request++; if (!props.readonly && previous && props.modelValue.length) { emit('update:modelValue', []); fail('Product or location changed. Serial assignments were cleared; select serials again.'); } if (open.value && !props.readonly) load(); });
</script>
<template>
    <div class="serial-assignment">
        <button type="button" :class="{ matched: valid, missing: !valid }" @click="manage">Serials {{ modelValue.length }}/{{ quantity }} · {{ readonly ? 'View' : 'Manage Serials' }}</button>
        <span v-if="remaining > 0" class="serial-error">{{ remaining }} more serial numbers required</span>
        <span v-else-if="remaining < 0" class="serial-error">Remove {{ -remaining }} excess serial numbers</span>
        <span v-if="error" class="serial-error" role="alert">{{ error }}</span>
        <section v-if="open" class="serial-editor" aria-label="Serial assignments">
            <p>Product and location must match this row. {{ purpose === 'opening' ? 'Existing serials must have no prior stock linkage. New serials are created only when posted.' : 'Only serials with posted saleable stock can be sold.' }}</p>
            <template v-if="!readonly">
                <input v-model="search" placeholder="Find eligible existing serial" aria-label="Find eligible existing serial" @keyup.enter="load" />
                <button type="button" :disabled="loading" @click="load">{{ loading ? 'Loading…' : 'Search serials' }}</button>
                <div class="serial-options">
                    <button v-for="serial in candidates" :key="serial.serial_number_id" type="button" @click="add([serial])">{{ serial.serial_number }} · Existing · {{ serial.status }} · {{ serial.branch }} / {{ serial.warehouse }}</button>
                    <span v-if="!loading && !candidates.length">No eligible existing serials found.</span>
                </div>
                <template v-if="purpose === 'opening'">
                    <textarea v-model="pasted" aria-label="New serial numbers" placeholder="New serial numbers, one per line" rows="3"></textarea>
                    <button type="button" @click="add(pasted.split(/\r?\n/).map(s => s.trim()).filter(Boolean).map(serial_number => ({ serial_number_id: null, serial_number })))">Add new serials</button>
                </template>
            </template>
            <ul>
                <li v-for="(serial, index) in modelValue" :key="index">
                    <span>{{ serial.serial_number }} · {{ serial.serial_number_id ? 'Existing' : 'New' }} · {{ serial.status || (readonly ? 'Posted' : 'Assigned to draft') }} · {{ serial.branch || scope.branch_name || scope.branch_id }} / {{ serial.warehouse || scope.warehouse_name || scope.warehouse_id || 'Default' }}</span>
                    <button v-if="!readonly" type="button" @click="emit('update:modelValue', modelValue.filter((_, i) => i !== index))">Remove</button>
                </li>
            </ul>
            <p v-if="message" class="serial-error" role="alert">{{ message }}</p>
        </section>
    </div>
</template>
<style scoped>
.serial-assignment { margin-top: 8px; white-space: normal; min-width: 220px; }
button { cursor: pointer; padding: 6px 9px; border: 1px solid #ccd6e4; border-radius: 6px; background: #fff; font-size: 11px; }
.matched { color: #168757; border-color: #9dd9b8; } .missing, .serial-error { color: #b42318; }
.serial-error { display: block; font-size: 11px; margin-top: 5px; }
.serial-editor { background: #f8fafc; padding: 10px; border: 1px solid #dfe6ef; border-radius: 8px; max-width: 430px; font-size: 12px; }
.serial-editor p { margin: 4px 0 8px; } input, textarea { box-sizing: border-box; width: 100%; border: 1px solid #ccd6e4; padding: 8px; border-radius: 6px; }
.serial-options { display: grid; gap: 5px; max-height: 150px; overflow: auto; margin: 8px 0; } ul { padding: 0; list-style: none; } li { display: flex; gap: 8px; justify-content: space-between; margin: 6px 0; overflow-wrap: anywhere; }
</style>
