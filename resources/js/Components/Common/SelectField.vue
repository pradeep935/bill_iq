<script setup>
import { computed, ref, onMounted, watch } from 'vue';

const { label, error, options=[], opt_id='value', opt_name='label', req=false, editable=true, select_name='Select', cls='', disabled=false, hint=''} = defineProps(['label','error','options','opt_id','opt_name','req','editable','select_name','cls','disabled','hint'])
const model = defineModel() 

const selected_name = ref('')

const optionValue = (opt = {}) => opt?.[opt_id] ?? opt?.value ?? opt?.id ?? opt?.code ?? opt?.name ?? opt?.label ?? '';
const optionLabel = (opt = {}) => opt?.[opt_name] ?? opt?.label ?? opt?.name ?? opt?.title ?? opt?.code ?? opt?.value ?? opt?.id ?? '';

const purposeHint = computed(() => {
    if (hint) return hint;

    const text = `${label || select_name}`.toLowerCase();
    if (!text.trim()) return '';
    if (text.includes('branch')) return 'Branch select karne se record us branch ke billing, stock aur reports mein link hota hai.';
    if (text.includes('warehouse') || text.includes('godown')) return 'Warehouse/godown se stock location aur availability track hoti hai.';
    if (text.includes('account')) return 'Account select karne se ledger posting aur financial reports sahi bante hain.';
    if (text.includes('category')) return 'Category grouping, filtering aur reporting ke liye use hoti hai.';
    if (text.includes('status')) return 'Status record ka workflow stage aur availability batata hai.';
    if (text.includes('type') || text.includes('mode')) return 'Type/mode workflow, validation aur report grouping decide karta hai.';

    return 'Ye selection record ko sahi master, workflow aur reports se connect karta hai.';
});

onMounted(() => {
    onChange();
})

function onChange() {
    // console.log(options)
    selected_name.value = ''
    for (let i = 0; i < options.length; i++) {
        if(optionValue(options[i]) == model.value) {
            selected_name.value = optionLabel(options[i])
        }
    }
}

watch([() => options, model], () => onChange(), { deep: true });

</script>

<template>
    <div :class="`form-group ${cls}`" v-if="editable">
        <label v-if="label">{{label}} <span class="error" v-if="req">*</span></label>
        <select class="form-control" v-model="model" @change="onChange()" :disabled="disabled">
            <option value="">{{ select_name }}</option>
            <option v-for="opt in options" :key="optionValue(opt)" :value="optionValue(opt)">{{ optionLabel(opt) }}</option>
        </select>
        <small v-if="purposeHint" class="field-hint">{{ purposeHint }}</small>
    </div>
    <div v-if="!editable">
        {{selected_name}}
    </div>
</template>

<style scoped>
.field-hint {
  color: #7b879c;
  display: block;
  font-size: 11px;
  font-weight: 650;
  line-height: 1.4;
  margin-top: 6px;
}
</style>
