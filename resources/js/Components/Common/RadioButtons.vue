<script setup>
const { label, error, options, opt_id='value', opt_name='label' , cls='', cls2=''} = defineProps(['label','error','options','opt_id','opt_name','cls','cls2'])
const model = defineModel()

const optionValue = (opt = {}) => opt?.[opt_id] ?? opt?.value ?? opt?.id ?? opt?.code ?? opt?.name ?? opt?.label ?? '';
const optionLabel = (opt = {}) => opt?.[opt_name] ?? opt?.label ?? opt?.name ?? opt?.title ?? opt?.code ?? opt?.value ?? opt?.id ?? '';
</script>

<template>
    <div :class="`form-group ${cls}`">
        <label :class="cls2">{{label}}</label>
        <div v-for="opt in options" :key="optionValue(opt)">
            <input type="radio" v-model="model" :value="optionValue(opt)" /> <label>{{ optionLabel(opt) }}</label>
        </div>
    </div>
</template>
