<script setup>
	const {filters} = defineProps(['filters']);
    import FilterInput from '@/Components/Filter/FilterInput.vue';

</script>
<template>
	<div class="row" >
        <FilterInput label="Name" placeholder="Search by name" name="template_name" v-model="filters.template_name" />
    </div>
</template>