<script setup>
import { useField } from 'vee-validate';
import { ref, computed  } from "vue";
import * as yup from 'yup';

const {name, type='text', modelValue, cls='', req=false, label='', placeholder='', options=[], opt_id='value', opt_name='label', select_name='Select', disabled=false, left_box_text=null, right_box_text=null, box_type='text', hint=''} = defineProps(['name','type','modelValue','cls','req','label','placeholder','options','opt_id','opt_name','select_name', 'disabled', 'left_box_text', 'right_box_text', 'box_type', 'hint']);

const processReq = computed(() => {
    if(!req){
      return null;
    } else {
        return yup.string().required('This field is required.'); 
    }
});

const { value, errorMessage } = useField(() => name, processReq, {
  syncVModel: true,
});

const optionValue = (opt = {}) => opt?.[opt_id] ?? opt?.value ?? opt?.id ?? opt?.code ?? opt?.name ?? opt?.label ?? '';
const optionLabel = (opt = {}) => opt?.[opt_name] ?? opt?.label ?? opt?.name ?? opt?.title ?? opt?.code ?? opt?.value ?? opt?.id ?? '';
</script>

<template>
    <div :class="`form-group ${cls}`">
        <label v-if="label">{{ label }} <span class="required-mark" v-if="req">*</span></label>
        <div v-if="left_box_text || right_box_text" class="input-group">
            <div v-if="left_box_text" class="input-group-text">
                <span v-if="box_type=='text'">{{ left_box_text }}</span>
                <i :class="left_box_text" v-else></i>
            </div>

            <select :class="['form-control', { 'is-invalid': errorMessage }]" v-model="value" :disabled="disabled">
                <option value="">{{ select_name }}</option>
                <option v-for="opt in options" :key="optionValue(opt)" :value="optionValue(opt)">{{ optionLabel(opt) }}</option>
            </select>

            <div v-if="right_box_text" class="input-group-text">
                <span v-if="box_type=='text'">{{ right_box_text }}</span>
                <i :class="right_box_text" v-else></i>
            </div>
        </div>
        <div v-else>
            <select :class="['form-control', { 'is-invalid': errorMessage }]" v-model="value" :disabled="disabled">
                <option value="">{{ select_name }}</option>
                <option v-for="opt in options" :key="optionValue(opt)" :value="optionValue(opt)">{{ optionLabel(opt) }}</option>
            </select>
        </div>
        <span v-if="hint" class="field-hint">{{ hint }}</span>
        <span v-if="errorMessage" class="field-error">{{ errorMessage }}</span>
    </div>

</template>
