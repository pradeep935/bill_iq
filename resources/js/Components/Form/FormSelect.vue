<script setup>
import { useField } from 'vee-validate';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import * as yup from 'yup';

const {name, type='text', modelValue, cls='', req=false, label='', placeholder='', options=[], opt_id='value', opt_name='label', select_name='Select', disabled=false, left_box_text=null, right_box_text=null, box_type='text', hint=''} = defineProps(['name','type','modelValue','cls','req','label','placeholder','options','opt_id','opt_name','select_name', 'disabled', 'left_box_text', 'right_box_text', 'box_type', 'hint']);

const purposeHint = computed(() => {
    if (hint) {
        return hint;
    }

    const text = `${label || name || select_name || placeholder}`.toLowerCase();

    if (!text.trim()) {
        return '';
    }

    if (text.includes('branch')) {
        return 'Branch select karne se data us branch ke stock, billing aur reports mein link hota hai.';
    }

    if (text.includes('warehouse') || text.includes('godown')) {
        return 'Warehouse/godown se stock location, transfer aur availability track hoti hai.';
    }

    if (text.includes('category')) {
        return 'Category grouping, filtering, reports aur accounting mapping ke liye use hoti hai.';
    }

    if (text.includes('account')) {
        return 'Account select karne se ledger posting, balance aur financial reports sahi bante hain.';
    }

    if (text.includes('status')) {
        return 'Status batata hai record active, draft, posted ya closed workflow stage mein hai.';
    }

    if (text.includes('type') || text.includes('mode')) {
        return 'Type/mode workflow, validation aur report grouping decide karta hai.';
    }

    return 'Ye selection record ko sahi master, workflow aur reports se connect karta hai.';
});

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

const root = ref(null);
const inputRef = ref(null);
const open = ref(false);
const search = ref('');
const activeIndex = ref(-1);
const listboxId = `form-select-${Math.random().toString(36).slice(2)}`;

const selectedOption = computed(() => options.find((option) => String(optionValue(option)) === String(value.value)) || null);
const filteredOptions = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term || optionLabel(selectedOption.value || {}) === search.value) {
        return options;
    }

    return options.filter((option) => {
        const labelText = String(optionLabel(option)).toLowerCase();
        const valueText = String(optionValue(option)).toLowerCase();

        return labelText.includes(term) || valueText.includes(term);
    });
});
const activeOptionId = computed(() => activeIndex.value >= 0 ? `${listboxId}-option-${activeIndex.value}` : undefined);

const syncSearchToSelection = () => {
    search.value = selectedOption.value ? optionLabel(selectedOption.value) : '';
};

const openList = () => {
    if (disabled) return;
    open.value = true;
    activeIndex.value = filteredOptions.value.findIndex((option) => String(optionValue(option)) === String(value.value));
};

const closeList = () => {
    open.value = false;
    activeIndex.value = -1;
    syncSearchToSelection();
};

const selectOption = (option) => {
    value.value = optionValue(option);
    search.value = optionLabel(option);
    open.value = false;
    activeIndex.value = -1;
};

const clearSelection = () => {
    value.value = '';
    search.value = '';
    open.value = true;
    nextTick(() => inputRef.value?.focus());
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
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = activeIndex.value > 0 ? activeIndex.value - 1 : filteredOptions.value.length - 1;
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
    if (!root.value?.contains(event.target)) {
        closeList();
    }
};

watch(() => value.value, syncSearchToSelection);
watch(() => options, syncSearchToSelection, { deep: true });
watch(search, () => {
    if (open.value) {
        activeIndex.value = filteredOptions.value.length ? 0 : -1;
    }
});

onMounted(() => {
    syncSearchToSelection();
    document.addEventListener('mousedown', handleDocumentClick);
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', handleDocumentClick);
});
</script>

<template>
    <div ref="root" :class="`form-group ${cls}`">
        <label v-if="label">{{ label }} <span class="required-mark" v-if="req">*</span></label>
        <div v-if="left_box_text || right_box_text" class="input-group">
            <div v-if="left_box_text" class="input-group-text">
                <span v-if="box_type=='text'">{{ left_box_text }}</span>
                <i :class="left_box_text" v-else></i>
            </div>

            <div class="search-select-control-wrap">
                <input
                    ref="inputRef"
                    v-model="search"
                    :class="['form-control', { 'is-invalid': errorMessage }]"
                    type="text"
                    autocomplete="off"
                    role="combobox"
                    :aria-expanded="open"
                    :aria-controls="listboxId"
                    :aria-activedescendant="activeOptionId"
                    :placeholder="select_name"
                    :disabled="disabled"
                    @focus="openList"
                    @input="openList"
                    @keydown="handleKeydown"
                />
                <button v-if="value && !disabled" class="search-select-clear" type="button" aria-label="Clear selection" @click.prevent="clearSelection">x</button>
                <div v-if="open && !disabled" :id="listboxId" class="search-select-menu" role="listbox">
                    <button
                        v-for="(opt, index) in filteredOptions"
                        :id="`${listboxId}-option-${index}`"
                        :key="optionValue(opt)"
                        type="button"
                        class="search-select-option"
                        :class="{ active: index === activeIndex, selected: String(optionValue(opt)) === String(value) }"
                        role="option"
                        :aria-selected="String(optionValue(opt)) === String(value)"
                        @mousedown.prevent="selectOption(opt)"
                    >
                        {{ optionLabel(opt) }}
                    </button>
                    <div v-if="!filteredOptions.length" class="search-select-empty">No results found</div>
                </div>
            </div>

            <div v-if="right_box_text" class="input-group-text">
                <span v-if="box_type=='text'">{{ right_box_text }}</span>
                <i :class="right_box_text" v-else></i>
            </div>
        </div>
        <div v-else>
            <div class="search-select-control-wrap">
                <input
                    ref="inputRef"
                    v-model="search"
                    :class="['form-control', { 'is-invalid': errorMessage }]"
                    type="text"
                    autocomplete="off"
                    role="combobox"
                    :aria-expanded="open"
                    :aria-controls="listboxId"
                    :aria-activedescendant="activeOptionId"
                    :placeholder="select_name"
                    :disabled="disabled"
                    @focus="openList"
                    @input="openList"
                    @keydown="handleKeydown"
                />
                <button v-if="value && !disabled" class="search-select-clear" type="button" aria-label="Clear selection" @click.prevent="clearSelection">x</button>
                <div v-if="open && !disabled" :id="listboxId" class="search-select-menu" role="listbox">
                    <button
                        v-for="(opt, index) in filteredOptions"
                        :id="`${listboxId}-option-${index}`"
                        :key="optionValue(opt)"
                        type="button"
                        class="search-select-option"
                        :class="{ active: index === activeIndex, selected: String(optionValue(opt)) === String(value) }"
                        role="option"
                        :aria-selected="String(optionValue(opt)) === String(value)"
                        @mousedown.prevent="selectOption(opt)"
                    >
                        {{ optionLabel(opt) }}
                    </button>
                    <div v-if="!filteredOptions.length" class="search-select-empty">No results found</div>
                </div>
            </div>
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

.search-select-control-wrap {
    position: relative;
    width: 100%;
}

.search-select-control-wrap .form-control {
    padding-right: 38px;
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
    min-height: 0;
    padding: 0;
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    width: 22px;
}

.search-select-menu {
    background: #fff;
    border: 1px solid #d8e0eb;
    border-radius: 8px;
    box-shadow: 0 18px 42px rgba(16, 24, 40, .16);
    display: grid;
    left: 0;
    max-height: 240px;
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
    font-size: 13px;
    font-weight: 700;
    min-height: 38px;
    padding: 9px 12px;
    text-align: left;
}

.search-select-option.active,
.search-select-option:hover {
    background: #edf4ff;
    color: #1d4ed8;
}

.search-select-option.selected {
    color: #2457d6;
}

.search-select-empty {
    color: #7b879c;
    font-size: 12px;
    font-weight: 750;
    padding: 13px 12px;
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
