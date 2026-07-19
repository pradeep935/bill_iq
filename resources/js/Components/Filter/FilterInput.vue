<script setup>
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';

// const { label="", error={}, req = false, type="text", editable = true, cls='', placeholder=''} = defineProps(['label','error','req','type', 'editable','cls','placeholder']);


const {name, type='text', modelValue, cls='', req=false, label='', validate = '', placeholder='', disabled=false, } = defineProps(['name','type','modelValue','cls','req','label','validate','placeholder', 'disabled']); 

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
        <input v-if="type != 'date'" :type="type" v-model="model" :placeholder="placeholder" class="form-control form-control-sm" />

        <VueDatePicker v-model="model" :format="format" :enable-time-picker="false" auto-apply :placeholder="placeholder" :hide-input-icon="true" v-if="type == 'date'" :teleport="true" :disabled="disabled"></VueDatePicker>
    </div>
</template>