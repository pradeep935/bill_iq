<script setup>
	const {filters, params = []} = defineProps(['filters','params']);
    import FilterInput from '@/Components/Filter/FilterInput.vue';

</script>
<template>
	<div class="row">
		<FilterInput label="Name" placeholder="Staff name..." v-model="filters.name" />
	</div>
</template>