<script setup>
    import {ref, watch } from 'vue';
    const {filters, params = [], users = [], CreatedLabel="Created On"} = defineProps(['filters','params', 'users','CreatedLabel']);
    import FilterInput from '@/Components/Filter/FilterInput.vue';
    import FilterSelect from '@/Components/Filter/FilterSelect.vue';
    import FilterInputRange from '@/Components/Filter/FilterInputRange.vue';

    const gender_list = ref([
        { label: 'Male', value: 1 },
        { label: 'Female', value: 2 },
    ]);

    const potential_list = ref([
        { label: 'Low', value: 1 },
        { label: 'Medium', value: 2 },
        { label: 'High', value: 3 },
    ]);

    const formatDate = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    };

    watch(() => [filters.age_min, filters.age_max],
        ([ageMin, ageMax]) => {
            const today = new Date();
            if (ageMax) {
                const minDob = new Date(
                    today.getFullYear() - Number(ageMax) - 1,
                    today.getMonth(),
                    today.getDate() + 1
                );

                filters.dob_min = formatDate(minDob);
            } else {
                filters.dob_min = null;
            }

            if (ageMin) {
                const maxDob = new Date(
                    today.getFullYear() - ageMin,
                    today.getMonth(),
                    today.getDate()
                );

                filters.dob_max = formatDate(maxDob);
            } else {
                filters.dob_max = null;
            }
        },
        { immediate: true }
    );

    watch(
        () => [
            filters.start_created_date,
            filters.end_created_date
        ],
        ([start, end]) => {

            if (start && end) {

                const startDate = new Date(start);
                const endDate = new Date(end);

                if (endDate < startDate) {
                    filters.end_created_date = null;
                }

            }

        }
    );

</script>

<template>
    <div class="row">
        <FilterInput label="Name" v-model="filters.name" placeholder="Player name..."/>
        <FilterInput label="AIFF ID" v-model="filters.aiff_id" placeholder="AIFF-XXXX"/>
        <FilterInput label="District" v-model="filters.district" placeholder="Type District..."/>
        <FilterInput label="State" v-model="filters.state" placeholder="Type State..."/>
        <FilterInput cls="mt-2" label="Tournament" v-model="filters.tournament" placeholder="Type Tournament..."/>
        <FilterSelect cls="mt-2" label="Position" v-model="filters.position_first_id" :options="params.position_list" opt_name="position_short" opt_id="id" placeholder="Select Position..."/>
        <FilterSelect cls="mt-2" label="Created By" v-model="filters.added_by" :options="users"/>
        <FilterSelect cls="mt-2" label="Gender" v-model="filters.gender" :options="gender_list"/>
        <FilterSelect label="Preferred Foot" cls="mt-2" v-model="filters.foot_id" :options="params.foot_list" opt_name="foot" opt_id="id"/>
        <FilterSelect label="Potential" cls="mt-2" v-model="filters.potential_id" :options="params.potential_array"/>
        <FilterSelect label="Performance" cls="mt-2" v-model="filters.grade_id" :options="params.performance_array"/>
        <FilterInputRange type="number" cls="mt-2" label="Overall score" minPlaceholder="Min" maxPlaceholder="Max" v-model:minValue="filters.total_score_min" v-model:maxValue="filters.total_score_max"/>
        <FilterInputRange type="number" cls="mt-2" label="Age Range" minPlaceholder="Min" maxPlaceholder="Max" v-model:minValue="filters.age_min" v-model:maxValue="filters.age_max" suffix="yrs"/>
        <FilterInputRange type="number" cls="mt-2" label="Height Range" minPlaceholder="Min" maxPlaceholder="Max" v-model:minValue="filters.height_min" v-model:maxValue="filters.height_max" suffix="cm"/>
        <FilterInputRange type="date" cls="mt-2" :label="CreatedLabel" minPlaceholder="From" maxPlaceholder="To" v-model:minValue="filters.start_created_date" v-model:maxValue="filters.end_created_date"/>
    </div>
</template>