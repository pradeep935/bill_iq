<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import Card from '@/Components/NewComponents/Card.vue';

    const {staff_id} = defineProps(['staff_id']);
    const loading = ref(false);
    const staffMatches = ref([]);


    const filters = ref({
        page_no: 1,
        max_per_page: 20,
        max_page: 1,
        total: 0
    });

    onMounted(
        () => {
            onMountedfunc();
        }
    )

    function onMountedfunc(){
        loading.value = true;
        DBService.postData('/api/staff/staff-details/fetch-staff-assigned-match/' + staff_id,
            filters.value
        ).then((data) => {
            if(data.success){
                filters.value.total = data.total;
                filters.value.max_page = Math.ceil(filters.value.total/filters.value.max_per_page)
                staffMatches.value = data.staffMatches;
            }
            loading.value = false;
        });
    }


    function setPage(page_no){
        if (page_no < 1 || page_no > filters.value.max_page) return;
        if (page_no == filters.value.page_no) return;
            filters.value.page_no = page_no;
            onMountedfunc();
    }
</script>

<template> 
    <div class="col-md-12">
        <Card title="Assigned / Attended Matches" header_class="px-3" footer_class="d-flex align-items-center justify-content-between" :show_footer="true">
            <div class="events-scroll-container" v-if="staffMatches.length > 0 && !loading" >
                <TableCont>
                    <thead>
                        <tr>
                            <th width="60">SN</th>
                            <th>Title</th>
                            <th>Competition</th>
                            <th>Venue</th>
                            <th>Start Date</th>
                            <th>Start Time</th>
                            <th>End Date</th>
                            <th class="text-center">Match Status</th>
                            <th class="text-center">Report Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="(match,index) in staffMatches" :key="match.id">

                            <td>
                                {{ index + 1 + (filters.page_no - 1) * filters.max_per_page }}
                            </td>

                            <td>
                                <div class="fw-semibold">
                                    {{ match.title }}
                                </div>
                            </td>
                            <td>{{ match.competition || '-' }}</td>
                            <td>{{ match.venue || '-' }}</td>
                            <td>{{ match.start_date }}</td>
                            <td>{{ match.start_time }}</td>
                            <td>{{ match.end_date }}</td>

                            <!-- Match Status -->
                            <td class="text-center">
                                <span
                                    class="badge px-3 py-2"
                                    :class="{
                                        'bg-warning text-dark': Number(match.status) === 1,
                                        'bg-info': Number(match.status) === 2,
                                        'bg-success': Number(match.status) === 3
                                    }"
                                >
                                    {{
                                        Number(match.status) === 1
                                            ? 'Upcoming'
                                            : Number(match.status) === 2
                                            ? 'Ongoing'
                                            : 'Completed'
                                    }}
                                </span>
                            </td>

                            <!-- Report Status -->
                            <td class="text-center">
                                <span
                                    class="badge px-3 py-2"
                                    :class="{
                                        'bg-secondary': Number(match.response_status) === 1,
                                        'bg-primary': Number(match.response_status) === 2,
                                        'bg-success': Number(match.response_status) === 3,
                                        'bg-warning text-dark': Number(match.response_status) === 4
                                    }"
                                >
                                    {{
                                        Number(match.response_status) === 1
                                            ? 'Draft'
                                            : Number(match.response_status) === 2
                                            ? 'Submitted'
                                            : Number(match.response_status) === 3
                                            ? 'Approved'
                                            : 'Need Revision'
                                    }}
                                </span>
                            </td>

                        </tr>
                    </tbody>
                </TableCont>
            </div>

            <Loading :loading="loading" type="table" :max="5" />
            <LengthZero v-if="!loading && staffMatches.length == 0" cls="ms-4 py-3 mt-2" />

            <template v-slot:footer_slot>
                <Pagination :filters="filters" @set-page="(page_no) => setPage(page_no)" />
            </template>
        </Card>
    </div>

</template>
<style scoped>
    .events-scroll-container{
        max-height:650px;
        overflow-y:auto;
        padding-right:6px;
    }
</style>