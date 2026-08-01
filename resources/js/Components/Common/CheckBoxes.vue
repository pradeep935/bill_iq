<script setup>
const { label, error, options, opt_id='value', opt_name='label', cls='' } = defineProps(['label','error','options','opt_id','opt_name','cls'])
const model = defineModel() 

const optionValue = (opt = {}) => opt?.[opt_id] ?? opt?.value ?? opt?.id ?? opt?.code ?? opt?.name ?? opt?.label ?? '';
const optionLabel = (opt = {}) => opt?.[opt_name] ?? opt?.label ?? opt?.name ?? opt?.title ?? opt?.code ?? opt?.value ?? opt?.id ?? '';

function addItem(item) {
    var idx = model.value.indexOf(item);
    if (idx == -1) {
        model.value.push(item);
    } else {
        model.value.splice(idx, 1);
    }
}

</script>

<template>
    <div :class="`form-group ${cls}`">
        <label>{{label}}</label>
        <div v-for="opt in options" :key="optionValue(opt)">
            <input type="checkbox" @click="addItem(optionValue(opt))" :checked="model.indexOf(optionValue(opt)) > -1" /> {{ optionLabel(opt) }}
        </div>
    </div>
</template>

<style scoped>
    div.max-h {
        max-height: 200px;
        overflow-y: auto;
    }
</style>
