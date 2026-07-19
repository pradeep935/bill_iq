<script setup>
    const {filters, intermidiaries = []} = defineProps(['filters','intermidiaries']);
    import FilterInput from '@/Components/Filter/FilterInput.vue';
    import FilterInputRange from '@/Components/Filter/FilterInputRange.vue';
    import FilterSelect from '@/Components/Filter/FilterSelect.vue';

</script>

<template>
    <div class="row g-2">
        <FilterInput label="Name" v-model="filters.name" />

        <FilterInputRange type="date" label="Start Date" minPlaceholder="From" maxPlaceholder="To"  v-model:minValue="filters.start_date_from" v-model:maxValue="filters.start_date_to" />

        <FilterInputRange type="date" label="End Date" minPlaceholder="From" maxPlaceholder="To"  v-model:minValue="filters.end_date_from" v-model:maxValue="filters.end_date_to" /> 

        <FilterSelect label="Intermidiary" v-model="filters.intermidiary_id" :options="intermidiaries" opt_id="intermidiary_id" opt_name="intermidiary_name" /> 
    </div>
</template>