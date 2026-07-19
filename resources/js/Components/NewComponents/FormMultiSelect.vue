<script setup>
    import { useField } from 'vee-validate';
    import { ref, computed  } from "vue";
    import * as yup from 'yup';

    const {name, type='text', modelValue, cls='', cls2='', cls3='px-2 me-2', color='', req=false, label='', placeholder='', options=[], opt_id='value', opt_name='label', select_name='Select', disabled=false, field_name=""} = defineProps(['name','type','modelValue','cls','cls2','cls3','color','req','label','placeholder','options','opt_id','opt_name','select_name', 'disabled','field_name']); 

    const processReq = computed(() => {
        if (!req) {
            return yup.array();
        } else {
            return yup
                .array()
                .required('This field is required')
        }
    });

    const model = defineModel() 

    const temp_select = ref('');

    const { value, errorMessage } = useField(() => name, processReq, {
        syncVModel: true,
    });

    function newMultiSelectDisplay(){
        if (temp_select.value) {
            model.value.push(temp_select.value);
            temp_select.value = '';
        }
    }

    function removeSelectedOption(temp_obj){
        let idx = model.value.indexOf(temp_obj[opt_id]);
        model.value.splice(idx, 1);
    }
</script>

<template>
    <div class="d-flex align-items-center justify-content-between mb-0" v-if="field_name && model.length">
        <h6 class="fw-semibold mb-0">
            Selected {{ field_name }}
        </h6>

        <span class="badge bg-primary">
            {{ model.length }}
        </span>
    </div>
    <div :class="cls2" class="d-flex flex-wrap gap-2 mb-0" v-show="model.length > 0">
        <div class="multi-chip" :class="cls3" :style="'background-color: ' + color" v-for="opt in options" v-show="model.includes(opt[opt_id])">
            <span>{{ opt[opt_name] }}</span>&nbsp;&nbsp;
            <i class="bi bi-x-lg chip-close"  @click="removeSelectedOption(opt)" ></i>
        </div>
    </div>

    <div :class="`form-group ${cls}`">
        <label v-if="label">{{label}} <span class="error" v-if="req">*</span></label>

        <select class="form-control" v-model="temp_select" :disabled="disabled" @change="newMultiSelectDisplay()">
            <option value="">{{ select_name }}</option>
            <option v-for="opt in options" :value="opt[opt_id]" v-show="!model.includes(opt[opt_id])">{{ opt[opt_name] }}</option>
        </select>
        <span class="text-danger" style="font-size: 11px">{{ errorMessage }}</span>
    </div>

</template>

<style scoped>
    /* Chip Style */
    .multi-chip {
        background: #eef2ff;
        color: #000000;
        font-size: 13px;
        transition: 0.2s;
        padding: 7px;
        border-radius: 9px;
    }

    .multi-chip:hover {
        background-color: rgb(43, 100, 225);
        color: white;
    }

    /* Close Icon */
    .chip-close {
        font-size: 12px;
        cursor: pointer;
        opacity: 0.7;
    }

    .chip-close:hover {
        opacity: 1;
    }

    /* Select focus */
    .form-select:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 0.1rem rgba(99,102,241,0.25);
    }
    h6 {
        letter-spacing: 0.3px;
    }
</style>