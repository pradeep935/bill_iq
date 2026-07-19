<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import Card from '@/Components/NewComponents/Card.vue';

    const {player_id} = defineProps(['player_id']);
    const base_url = import.meta.env.VITE_APP_URL;

    onMounted(
        () => {
            fetchRecruimentDetails();
        }
    )

    const loading = ref(false);
    const rec_pos_list = ref([]);
    const filters = ref({
        page_no: 1,
        max_per_page: 5,
        max_page: 1,
        total: 0
    })

    function fetchRecruimentDetails(){
        loading.value = true;
        DBService.postData('/api/player-information/recruiment-details/' + player_id, filters.value).then((data) => {
            if(data.success){
                rec_pos_list.value = data.rec_pos_list;
                filters.value.total = data.total;
                filters.value.max_page = Math.ceil(data.total / filters.value.max_per_page);
            }
            loading.value = false;
        });
    }

    function setPage(page_no){
        if (page_no < 1 || page_no > filters.value.max_page) return;
        if (page_no == filters.value.page_no) return;
        filters.value.page_no = page_no;
        fetchRecruimentDetails();
    }
</script>

<template>
    <div class="row mb-4">
        <div class="col-md-12">
            <Card title="Recruitment Details" header_class="px-3" :show_footer="true" footer_class="d-flex align-items-center justify-content-between">
                <TableCont v-if="!loading && rec_pos_list.length > 0">
                    <thead>
                        <tr>
                            <th>SN</th>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Transfer Value</th>
                            <th>Annual Salary</th>
                            <th>Requirement</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(rec_pos_obj, index) in rec_pos_list">
                            <td>{{ index + 1 + (filters.page_no-1)*filters.max_per_page }}</td>
                            <td>
                                <!-- <a :href="base_url + '/recruitment-tracker/recruitment-position/players/' + rec_pos_obj.id" target="_blank" class="theme-color">{{ rec_pos_obj.name }}</a> -->
                                <a href="#" class="theme-color">{{ rec_pos_obj.name }}</a>
                            </td>
                            <td>{{ rec_pos_obj.status }}</td>
                            <td>{{ rec_pos_obj.transfer_value }}</td>
                            <td>{{ rec_pos_obj.annual_salary }}</td>
                            <td>{{ rec_pos_obj.requirement }}</td>
                        </tr>
                    </tbody>
                </TableCont>

                <LengthZero v-if="!loading && rec_pos_list.length == 0"></LengthZero>
                <Loading :loading="loading" type="table" max="5"></Loading>

                <template v-slot:footer_slot>
                    <Pagination
                        :filters="filters"
                        @set-page="(page_no) => setPage(page_no)"
                    />
                </template>
            </Card>
        </div>
    </div>

</template>