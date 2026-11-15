<script setup>
import { useField } from 'vee-validate';
import { ref, computed  } from "vue";
import * as yup from 'yup';
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css'

const {name, type='text', modelValue, cls='', req=false, label='', validate = '', placeholder='', disabled=false, left_box_text=null, right_box_text=null, box_type='text', hint=''} = defineProps(['name','type','modelValue','cls','req','label','validate','placeholder', 'disabled', 'left_box_text', 'right_box_text', 'box_type', 'hint']);

// Helper text is shown only when a page passes an explicit `hint` (no auto-generated filler text).
const purposeHint = computed(() => hint || '');

const format = (date) => {
  const day = date.getDate();
  const month = date.getMonth() + 1;
  const year = date.getFullYear();

  return `${day}-${month}-${year}`;
}

const processedReq = computed(() => {

    if(!req && !validate){
      return null;
    }

    let obj = yup.string();

    if(req){
        obj = obj.required('This field is required.');
    }

    if(validate == "email"){
        obj = obj.email();
    } else if(validate == "mobile"){
        obj = obj.matches(/^[0-9]{10,15}$/, {
            message: 'Please enter a valid mobile number',
            excludeEmptyString: true,
        });
    } else if(validate == "url"){
        obj = obj.url();
    }

    return obj;
});

const { value, errorMessage } = useField(() => name, processedReq, {
  syncVModel: true,
});
</script>

<template>
    <div :class="`form-group ${cls}`">
        <label v-if="label">{{ label }} <span class="required-mark" v-if="req">*</span></label>
        <div v-if="left_box_text || right_box_text" class="input-group">
            <div v-if="left_box_text" class="input-group-text">
                <span v-if="box_type=='text'">{{ left_box_text }}</span>
                <i :class="left_box_text" v-else></i>
            </div>

                <input step="0.001" v-model="value" :type="type" :class="['form-control', { 'is-invalid': errorMessage }]" v-if="type != 'date'" :disabled="disabled"  :placeholder="placeholder" />
        
                <VueDatePicker v-model="value" :class="{ 'is-invalid': errorMessage }" :format="format" :enable-time-picker="false" auto-apply :placeholder="placeholder" :hide-input-icon="true" v-if="type == 'date'" :teleport="true" :disabled="disabled"></VueDatePicker>

            <div v-if="right_box_text" class="input-group-text">
                <span v-if="box_type=='text'">{{ right_box_text }}</span>
                <i :class="right_box_text" v-else></i>
            </div>
        </div>
        <div v-else>
            <input step="0.001" v-model="value" :type="type" :class="['form-control', { 'is-invalid': errorMessage }]" v-if="type != 'date'" :disabled="disabled"  :placeholder="placeholder" />
    
            <VueDatePicker v-model="value" :class="{ 'is-invalid': errorMessage }" :format="format" :enable-time-picker="false" auto-apply :placeholder="placeholder" :hide-input-icon="true" v-if="type == 'date'" :teleport="true" :disabled="disabled"></VueDatePicker>
        </div>
        <span v-if="purposeHint" class="field-hint">{{ purposeHint }}</span>
        <span v-if="errorMessage" class="field-error">{{ errorMessage }}</span>
    </div>
</template>

<style scoped>
.required-mark {
    color: #dc2626;
    font-weight: 900;
    margin-left: 3px;
}

.field-hint {
    color: #7b879c;
    display: block;
    font-size: 11px;
    font-weight: 650;
    line-height: 1.4;
    margin-top: 6px;
}

.field-error {
    color: #dc2626;
    display: block;
    font-size: 11px;
    font-weight: 800;
    line-height: 1.4;
    margin-top: 6px;
}
</style>
