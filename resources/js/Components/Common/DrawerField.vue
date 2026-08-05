<template>
  <label class="drawer-field" :class="{ 'field-span-2': span === 2 }">
    <span class="drawer-field-label">
      {{ label }}
      <span v-if="required" class="required-mark">*</span>
    </span>

    <textarea
      v-if="as === 'textarea'"
      :class="['drawer-control', { 'is-invalid': error }]"
      :value="modelValue"
      :rows="rows"
      :placeholder="placeholder"
      :disabled="disabled"
      @input="$emit('update:modelValue', $event.target.value)"
    ></textarea>

    <select
      v-else-if="as === 'select'"
      :class="['drawer-control', { 'is-invalid': error }]"
      :value="modelValue"
      :disabled="disabled"
      @change="$emit('update:modelValue', $event.target.value)"
    >
      <slot></slot>
    </select>

    <input
      v-else
      :class="['drawer-control', { 'is-invalid': error }]"
      :value="modelValue"
      :type="type"
      :min="min"
      :max="max"
      :step="step"
      :placeholder="placeholder"
      :disabled="disabled"
      @input="$emit('update:modelValue', castValue($event.target.value))"
    />

    <small v-if="error" class="drawer-field-error">{{ error }}</small>
    <small v-if="purposeHint" class="drawer-field-hint">{{ purposeHint }}</small>
  </label>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  modelValue: { type: [String, Number, Boolean, null], default: '' },
  label: { type: String, required: true },
  as: { type: String, default: 'input' },
  type: { type: String, default: 'text' },
  placeholder: { type: String, default: '' },
  hint: { type: String, default: '' },
  error: { type: String, default: '' },
  required: { type: Boolean, default: false },
  number: { type: Boolean, default: false },
  span: { type: Number, default: 1 },
  rows: { type: Number, default: 3 },
  min: { type: [String, Number], default: null },
  max: { type: [String, Number], default: null },
  step: { type: [String, Number], default: null },
  disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

const castValue = (value) => (props.number ? Number(value) : value);

const purposeHint = computed(() => {
  if (props.hint) return props.hint;

  const text = `${props.label || props.placeholder}`.toLowerCase();
  if (!text.trim()) return '';
  if (props.type === 'date' || text.includes('date')) return 'This date places the record in the correct ledger, report and workflow period.';
  if (props.number || props.type === 'number' || ['amount', 'price', 'rate', 'cost', 'qty', 'quantity', 'stock'].some((word) => text.includes(word))) return 'This number is used for calculations, totals and validation.';
  if (text.includes('branch')) return 'This links the record to the correct branch for billing, stock and reports.';
  if (text.includes('warehouse')) return 'This tracks stock location, transfers and availability.';
  if (text.includes('status')) return 'Status controls the record workflow stage and availability.';
  if (text.includes('remark') || text.includes('note') || text.includes('description')) return 'Use this for internal context, audit notes or future reference.';

  return 'This field helps save, search and report the record correctly.';
});
</script>

<style scoped>
.drawer-field {
  display: block;
  min-width: 0;
  width: 100%;
}

.drawer-field-label {
  color: #344159;
  display: block;
  font-size: 12px;
  font-weight: 700;
  margin-bottom: 7px;
}

.required-mark {
  color: #dc2626;
  display: inline;
  font-weight: 900;
  margin-left: 3px;
}

.drawer-control {
  background: #fff;
  border: 1px solid #d8e0eb;
  border-radius: 9px;
  color: #17233b;
  font-family: inherit;
  font-size: 13px;
  font-weight: 650;
  min-height: 44px;
  min-width: 0;
  outline: none;
  padding: 10px 12px;
  transition: border-color .15s ease, box-shadow .15s ease;
  width: 100%;
}

.drawer-control::placeholder {
  color: #a0a9b8;
}

.drawer-control:focus {
  border-color: #2457d6;
  box-shadow: 0 0 0 3px rgba(36, 87, 214, .1);
}

.drawer-control.is-invalid {
  background: #fffafa;
  border-color: #dc2626;
  color: #7f1d1d;
}

.drawer-field-error {
  color: #dc2626;
  display: block;
  font-size: 11px;
  font-weight: 800;
  line-height: 1.4;
  margin-top: 6px;
}

.drawer-control:disabled {
  background: #f5f7fb;
  color: #69758a;
  cursor: not-allowed;
}

.drawer-field-hint {
  color: #7b879c;
  display: block;
  font-size: 11px;
  font-weight: 650;
  line-height: 1.4;
  margin-top: 6px;
}

select.drawer-control {
  appearance: auto;
  cursor: pointer;
}

textarea.drawer-control {
  min-height: 82px;
  resize: vertical;
}

.field-span-2 {
  grid-column: span 2;
}

@media (max-width: 720px) {
  .field-span-2 {
    grid-column: span 1;
  }
}
</style>
