<template>
  <label class="drawer-field" :class="{ 'field-span-2': span === 2 }">
    <span class="drawer-field-label">
      {{ label }}
      <span v-if="required" class="required-mark">*</span>
    </span>

    <textarea
      v-if="as === 'textarea'"
      class="drawer-control"
      :value="modelValue"
      :rows="rows"
      :placeholder="placeholder"
      :disabled="disabled"
      @input="$emit('update:modelValue', $event.target.value)"
    ></textarea>

    <select
      v-else-if="as === 'select'"
      class="drawer-control"
      :value="modelValue"
      :disabled="disabled"
      @change="$emit('update:modelValue', $event.target.value)"
    >
      <slot></slot>
    </select>

    <input
      v-else
      class="drawer-control"
      :value="modelValue"
      :type="type"
      :min="min"
      :max="max"
      :step="step"
      :placeholder="placeholder"
      :disabled="disabled"
      @input="$emit('update:modelValue', castValue($event.target.value))"
    />

    <small v-if="hint" class="drawer-field-hint">{{ hint }}</small>
  </label>
</template>

<script setup>
const props = defineProps({
  modelValue: { type: [String, Number, Boolean, null], default: '' },
  label: { type: String, required: true },
  as: { type: String, default: 'input' },
  type: { type: String, default: 'text' },
  placeholder: { type: String, default: '' },
  hint: { type: String, default: '' },
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
