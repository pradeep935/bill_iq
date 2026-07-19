<script setup>
import VueDatePicker from '@vuepic/vue-datepicker'
import '@vuepic/vue-datepicker/dist/main.css'

const {label = "",minPlaceholder = "Min",maxPlaceholder = "Max",min = 0,max = 100,suffix = "",cls = "",type = "number",disabled = false,} = defineProps(['label','minPlaceholder','maxPlaceholder','min','max','suffix','cls','type','disabled'])

const minValue = defineModel('minValue')
const maxValue = defineModel('maxValue')

const format = (date) => {
    const day = String(date.getDate()).padStart(2, '0')
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const year = date.getFullYear()

    return `${day}-${month}-${year}`
}
</script>

<template>
    <div :class="`col-sm-6 col-md-4 ${cls}`">
        <div class="filter-group-label">
            {{ label }}
        </div>

        <div class="filter-range-pair">
            <input v-if="type !== 'date'" class="form-control form-control-sm" :type="type" v-model="minValue" :placeholder="minPlaceholder" :disabled="disabled" v-bind="type === 'number' ? { min, max } : {} " />

            <VueDatePicker
                v-else
                v-model="minValue"
                :format="format"
                :enable-time-picker="false"
                auto-apply
                :placeholder="minPlaceholder"
                :hide-input-icon="true"
                :teleport="true"
                :disabled="disabled"
                input-class-name="custom-datepicker-input"
                :max-date="maxValue || null"
            />

            <span class="filter-range-sep">–</span>

            <input v-if="type !== 'date'" class="form-control form-control-sm" :type="type" v-model="maxValue" :placeholder="maxPlaceholder" :disabled="disabled" v-bind="type === 'number' ? { min, max } : {}" />

             <VueDatePicker
                v-else
                v-model="maxValue"
                :format="format"
                :enable-time-picker="false"
                auto-apply
                :placeholder="maxPlaceholder"
                :hide-input-icon="true"
                :teleport="true"
                :disabled="disabled"
                input-class-name="custom-datepicker-input"
                :min-date="minValue || null"
            />

            <span v-if="suffix" class="filter-range-sep ms-1">
                {{ suffix }}
            </span>
        </div>
    </div>
</template>