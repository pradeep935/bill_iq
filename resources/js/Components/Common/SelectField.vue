<script setup>
import { ref, onMounted, watch } from 'vue';

const { label, error, options=[], opt_id='value', opt_name='label', req=false, editable=true, select_name='Select', cls='', disabled=false} = defineProps(['label','error','options','opt_id','opt_name','req','editable','select_name','cls','disabled'])
const model = defineModel() 

const selected_name = ref('')

const optionValue = (opt = {}) => opt?.[opt_id] ?? opt?.value ?? opt?.id ?? opt?.code ?? opt?.name ?? opt?.label ?? '';
const optionLabel = (opt = {}) => opt?.[opt_name] ?? opt?.label ?? opt?.name ?? opt?.title ?? opt?.code ?? opt?.value ?? opt?.id ?? '';

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
    </div>
    <div v-if="!editable">
        {{selected_name}}
    </div>
</template>
