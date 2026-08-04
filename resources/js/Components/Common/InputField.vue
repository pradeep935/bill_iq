<script setup>
import { computed } from 'vue';
const { label="", error={}, req = false, type="text", editable = true, cls='', placeholder='', disabled=false, hint=''} = defineProps(['label','error','req','type', 'editable','cls','placeholder','disabled','hint'])
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css'
const model = defineModel()

const purposeHint = computed(() => {
  if (hint) return hint;

  const text = `${label || placeholder}`.toLowerCase();
  if (!text.trim()) return '';
  if (type === 'date' || text.includes('date')) return 'Is date se record timeline, ledger aur reports mein sahi period mein aata hai.';
  if (type === 'number' || ['amount', 'price', 'rate', 'cost', 'qty', 'quantity', 'stock'].some((word) => text.includes(word))) return 'Ye number calculation, totals aur validation ke liye use hota hai.';
  if (text.includes('name')) return 'Ye naam list, search aur reports mein record identify karne ke liye use hota hai.';
  if (text.includes('reference')) return 'Ye external document/payment/bank reference trace karne ke liye use hota hai.';
  if (text.includes('remark') || text.includes('note') || text.includes('description')) return 'Ye internal context aur audit note ke liye use hota hai.';

  return 'Ye field record ko save, search aur report karne mein kaam aata hai.';
});

const format = (date) => {
  const day = date.getDate();
  const month = date.getMonth() + 1;
  const year = date.getFullYear();

  return `${day}-${month}-${year}`;
}

</script>

<template>
    <div :class="`form-group ${cls}`" v-if="editable && type != 'date'">
        <label v-if="label">{{label}} <span class="error" v-if="req">*</span></label>
        <input :type="type" v-model="model" :placeholder="placeholder" class="form-control" :disabled="disabled"/>
        <small v-if="purposeHint" class="field-hint">{{ purposeHint }}</small>
        <span class="error" v-if="error.$error">{{ error.$errors[0].$message }}</span>
    </div>
    <div :class="`form-group ${cls}`" v-if="editable && type == 'date'">
        <label v-if="label">{{label}} <span class="error" v-if="req">*</span></label>
        <VueDatePicker v-model="model" :disabled="disabled" :format="format" :enable-time-picker="false" auto-apply :placeholder="placeholder" :hide-input-icon="true" ></VueDatePicker>
        <small v-if="purposeHint" class="field-hint">{{ purposeHint }}</small>
        <span class="error" v-if="error.$error">{{ error.$errors[0].$message }}</span>
    </div>
    <div v-if="!editable">
        <span v-if="type != 'date'">{{model}}</span>
        <DateShow v-else :date="model" />
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
