<template>
  <label ref="root" class="search-select" :class="{ 'field-span-2': span === 2 }">
    <span class="search-select-label">
      {{ label }}
      <span v-if="required" class="required-mark">*</span>
    </span>

    <div class="search-select-control-wrap">
      <input
        ref="inputRef"
        v-model="search"
        :class="['search-select-control', { 'is-invalid': error }]"
        type="text"
        autocomplete="off"
        role="combobox"
        :aria-expanded="open"
        :aria-controls="listboxId"
        :aria-activedescendant="activeOptionId"
        :placeholder="placeholderText"
        :disabled="disabled"
        @focus="openList"
        @keydown="handleKeydown"
        @input="openList"
      />
      <button v-if="modelValue && !disabled" class="search-select-clear" type="button" aria-label="Clear selection" @click.prevent="clearSelection">
        x
      </button>

      <div v-if="open && !disabled" :id="listboxId" ref="listRef" class="search-select-menu" role="listbox">
        <button
          v-for="(option, index) in filteredOptions"
          :id="`${listboxId}-option-${index}`"
          :key="optionValue(option)"
          type="button"
          class="search-select-option"
          :class="{ active: index === activeIndex, selected: isSelected(option) }"
          role="option"
          :aria-selected="isSelected(option)"
          @mousedown.prevent="selectOption(option)"
        >
          <span>{{ optionLabel(option) }}</span>
          <small v-if="optionSubtext(option)">{{ optionSubtext(option) }}</small>
        </button>
        <div v-if="!filteredOptions.length" class="search-select-empty">No results found</div>
      </div>
    </div>

    <small v-if="error" class="search-select-error">{{ error }}</small>
    <small v-if="purposeHint" class="search-select-hint">{{ purposeHint }}</small>
  </label>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: [String, Number, null], default: '' },
  label: { type: String, required: true },
  options: { type: Array, default: () => [] },
  optionValueKey: { type: String, default: 'id' },
  optionLabelKey: { type: String, default: 'name' },
  optionSubtextKey: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  selectPlaceholder: { type: String, default: 'Select' },
  hint: { type: String, default: '' },
  error: { type: String, default: '' },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  span: { type: Number, default: 1 },
});

const emit = defineEmits(['update:modelValue', 'selected']);

const root = ref(null);
const inputRef = ref(null);
const listRef = ref(null);
const open = ref(false);
const search = ref('');
const activeIndex = ref(-1);
const listboxId = `search-select-${Math.random().toString(36).slice(2)}`;

const optionValue = (option = {}) => option?.[props.optionValueKey] ?? option?.value ?? option?.id ?? '';
const optionLabel = (option = {}) => option?.[props.optionLabelKey] ?? option?.label ?? option?.name ?? '';
const optionSubtext = (option = {}) => props.optionSubtextKey ? (option?.[props.optionSubtextKey] ?? '') : '';

const selectedOption = computed(() => props.options.find((option) => String(optionValue(option)) === String(props.modelValue)) || null);
const placeholderText = computed(() => props.disabled ? props.selectPlaceholder : (props.placeholder || props.selectPlaceholder));
const filteredOptions = computed(() => {
  const term = search.value.trim().toLowerCase();
  if (!term || selectedOption.value?.[props.optionLabelKey] === search.value) return props.options;

  return props.options.filter((option) => {
    const label = String(optionLabel(option)).toLowerCase();
    const subtext = String(optionSubtext(option)).toLowerCase();
    const value = String(optionValue(option)).toLowerCase();

    return label.includes(term) || subtext.includes(term) || value.includes(term);
  });
});
const activeOptionId = computed(() => activeIndex.value >= 0 ? `${listboxId}-option-${activeIndex.value}` : undefined);
const purposeHint = computed(() => {
  if (props.hint) return props.hint;

  const text = `${props.label || props.selectPlaceholder}`.toLowerCase();
  if (text.includes('branch')) return 'This links the record to the correct branch for billing, stock and reports.';
  if (text.includes('city') || text.includes('state')) return 'This keeps address, tax and reporting location consistent with master data.';
  if (text.includes('warehouse')) return 'This tracks stock location, transfers and availability.';
  if (text.includes('status')) return 'Status controls record availability in transactions.';

  return 'Search and select from the configured master list.';
});

const syncSearchToSelection = () => {
  search.value = selectedOption.value ? optionLabel(selectedOption.value) : '';
};

const openList = () => {
  if (props.disabled) return;
  open.value = true;
  activeIndex.value = filteredOptions.value.findIndex((option) => isSelected(option));
};

const closeList = () => {
  open.value = false;
  activeIndex.value = -1;
  syncSearchToSelection();
};

const isSelected = (option) => String(optionValue(option)) === String(props.modelValue);

const selectOption = (option) => {
  emit('update:modelValue', optionValue(option));
  emit('selected', option);
  open.value = false;
  activeIndex.value = -1;
  search.value = optionLabel(option);
};

const clearSelection = () => {
  emit('update:modelValue', '');
  emit('selected', null);
  search.value = '';
  open.value = true;
  nextTick(() => inputRef.value?.focus());
};

const scrollActiveIntoView = () => {
  nextTick(() => {
    listRef.value?.querySelector('.search-select-option.active')?.scrollIntoView({ block: 'nearest' });
  });
};

const handleKeydown = (event) => {
  if (event.key === 'Escape') {
    closeList();
    return;
  }

  if (!open.value && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
    event.preventDefault();
    openList();
    return;
  }

  if (event.key === 'ArrowDown') {
    event.preventDefault();
    activeIndex.value = activeIndex.value < filteredOptions.value.length - 1 ? activeIndex.value + 1 : 0;
    scrollActiveIntoView();
  }

  if (event.key === 'ArrowUp') {
    event.preventDefault();
    activeIndex.value = activeIndex.value > 0 ? activeIndex.value - 1 : filteredOptions.value.length - 1;
    scrollActiveIntoView();
  }

  if (event.key === 'Enter' && open.value) {
    event.preventDefault();
    const option = filteredOptions.value[activeIndex.value];
    if (option) selectOption(option);
  }

  if (event.key === 'Tab') {
    closeList();
  }
};

const handleDocumentClick = (event) => {
  if (!root.value?.contains(event.target)) closeList();
};

watch(() => props.modelValue, syncSearchToSelection);
watch(() => props.options, syncSearchToSelection, { deep: true });
watch(search, () => {
  if (open.value) activeIndex.value = filteredOptions.value.length ? 0 : -1;
});

onMounted(() => {
  syncSearchToSelection();
  document.addEventListener('mousedown', handleDocumentClick);
});

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', handleDocumentClick);
});
</script>

<style scoped>
.search-select {
  display: block;
  min-width: 0;
  position: relative;
  width: 100%;
}

.search-select-label {
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

.search-select-control-wrap {
  position: relative;
}

.search-select-control {
  background: #fff;
  border: 1px solid #d8e0eb;
  border-radius: 9px;
  color: #17233b;
  font-family: inherit;
  font-size: 13px;
  font-weight: 650;
  min-height: 44px;
  outline: none;
  padding: 10px 38px 10px 12px;
  transition: border-color .15s ease, box-shadow .15s ease;
  width: 100%;
}

.search-select-control::placeholder {
  color: #a0a9b8;
}

.search-select-control:focus {
  border-color: #2457d6;
  box-shadow: 0 0 0 3px rgba(36, 87, 214, .1);
}

.search-select-control.is-invalid {
  background: #fffafa;
  border-color: #dc2626;
  color: #7f1d1d;
}

.search-select-control:disabled {
  background: #f5f7fb;
  color: #69758a;
  cursor: not-allowed;
}

.search-select-clear {
  align-items: center;
  background: #eef2f7;
  border: 0;
  border-radius: 999px;
  color: #64748b;
  cursor: pointer;
  display: inline-flex;
  font-size: 12px;
  font-weight: 900;
  height: 22px;
  justify-content: center;
  position: absolute;
  right: 10px;
  top: 11px;
  width: 22px;
}

.search-select-menu {
  background: #fff;
  border: 1px solid #d8e0eb;
  border-radius: 9px;
  box-shadow: 0 18px 42px rgba(16, 24, 40, .16);
  display: grid;
  left: 0;
  max-height: 260px;
  overflow: auto;
  position: absolute;
  right: 0;
  top: calc(100% + 6px);
  z-index: 80;
}

.search-select-option {
  background: #fff;
  border: 0;
  border-bottom: 1px solid #edf1f5;
  border-radius: 0;
  color: #27344c;
  cursor: pointer;
  display: grid;
  gap: 3px;
  justify-items: start;
  min-height: 38px;
  padding: 9px 12px;
  text-align: left;
}

.search-select-option span {
  font-size: 13px;
  font-weight: 750;
}

.search-select-option small {
  color: #7b879c;
  font-size: 11px;
  font-weight: 650;
}

.search-select-option.active,
.search-select-option:hover {
  background: #edf4ff;
  color: #1d4ed8;
}

.search-select-option.selected span {
  color: #2457d6;
}

.search-select-empty {
  color: #7b879c;
  font-size: 12px;
  font-weight: 750;
  padding: 13px 12px;
}

.search-select-error {
  color: #dc2626;
  display: block;
  font-size: 11px;
  font-weight: 800;
  line-height: 1.4;
  margin-top: 6px;
}

.search-select-hint {
  color: #7b879c;
  display: block;
  font-size: 11px;
  font-weight: 650;
  line-height: 1.4;
  margin-top: 6px;
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
