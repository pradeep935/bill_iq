<script setup>
import { useField } from 'vee-validate';
import { ref, computed  } from "vue";
import * as yup from 'yup';

const {
    name,
    modelValue,
    cls = '',
    req = false,
    label = '',
    validate = '',
    placeholder = '',
    disabled = false,
    rows = 3,
    hint = ''
} = defineProps([
    'name',
    'modelValue',
    'cls',
    'req',
    'label',
    'validate',
    'placeholder',
    'disabled',
    'rows',
    'hint'
]);

const purposeHint = computed(() => {
    if (hint) {
        return hint;
    }

    const text = `${label || name || placeholder}`.toLowerCase();

    if (!text.trim()) {
        return '';
    }

    if (text.includes('remark') || text.includes('note') || text.includes('description') || text.includes('narration')) {
        return 'Ye detail internal explanation, audit trail, print note ya future follow-up ke liye use hoti hai.';
    }

    return 'Ye long text record ka context clear karta hai aur reports/audit mein reference ke kaam aata hai.';
});

const processedReq = computed(() => {
    if (!req && !validate) {
        return null;
    }

    let obj = yup.string();

    if (req) {
        obj = obj.required('This field is required.');
    }

    if (validate === 'email') {
        obj = obj.email();
    } else if (validate === 'url') {
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
        <textarea
            v-model="value"
            :class="['form-control form-control-solid', { 'is-invalid': errorMessage }]"
            :placeholder="placeholder"
            :rows="rows"
            :disabled="disabled"
        >
                
        </textarea>
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
