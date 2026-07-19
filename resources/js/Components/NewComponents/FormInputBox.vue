<script setup>
import { useField } from 'vee-validate';
import { ref, computed  } from "vue";
import * as yup from 'yup';
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css'

const {name, type='text', modelValue, cls='', req=false, label='', validate = '', placeholder='', disabled=false, box_entry="N/A", display_position='right'} = defineProps(['name','type','modelValue','cls','req','label','validate','placeholder','disabled','box_entry','display_position']); 

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
        obj = obj.required();
    }

    if(validate == "email"){
        obj = obj.email();
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
        <label v-if="label">{{label}} <span class="error" v-if="req">*</span></label>
        <div class="input-group">
            <span class="input-group-text" v-if="display_position != 'right'">{{ box_entry }}</span>

            <input v-model="value" :type="type" class="form-control" v-if="type != 'date'" :disabled="disabled"  :placeholder="placeholder" />
            <VueDatePicker v-model="value" :format="format" :enable-time-picker="false" auto-apply :placeholder="placeholder" :hide-input-icon="true" v-if="type == 'date'" :teleport="true" :disabled="disabled"></VueDatePicker>
            <span class="input-group-text" v-if="display_position == 'right'">{{ box_entry }}</span>
        </div>
        
        <span class="text-danger" style="font-size: 11px">{{ errorMessage }}</span>
    </div>
</template>