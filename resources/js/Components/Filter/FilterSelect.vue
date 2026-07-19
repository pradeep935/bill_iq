<script setup>
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';

const {name, type='text', modelValue, cls='', req=false, label='', placeholder='', options=[], opt_id='value', opt_name='label', select_name='Select', disabled=false, left_box_text=null, right_box_text=null, box_type='text'} = defineProps(['name','type','modelValue','cls','req','label','placeholder','options','opt_id','opt_name','select_name', 'disabled', 'left_box_text', 'right_box_text', 'box_type']); 

const model = defineModel()

const format = (date) => {
  const day = date.getDate();
  const month = date.getMonth() + 1;
  const year = date.getFullYear();

  return `${day}-${month}-${year}`;
}

</script>

<template>
    <div :class="`col-sm-6 col-md-4 col-lg-3 ${cls}`">
        <div class="filter-group-label">{{label}}</div>
        <select class="form-select form-select-sm" v-model="model" :placeholder="placeholder" >
            <option value="">{{ select_name }}</option>
            <option v-for="opt in options" :value="opt[opt_id]">{{ opt[opt_name] }}</option>
        </select>
    </div>
</template>