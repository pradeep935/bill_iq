<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import ShadowField from '@/Pages/ShadowTeam/ShadowField.vue';
    import Card from '@/Components/NewComponents/Card.vue';

    const {player_obj} = defineProps(['player_obj']);
    const emit = defineEmits(['playerRefresh']);
    const base_url = import.meta.env.VITE_APP_URL;
    const filters = ref({
        page_no: 1,
        max_per_page: 10,
        max_page: 1,
        total: 0
    })

    onMounted(
        () => {
            fetchAllShadowTeams();
    })

    const shadow_team_list = ref([]);
    const loading = ref(false);

    function fetchAllShadowTeams(){
        loading.value = true;
        DBService.postData('/api/shadow-team/get-player-shadow-teams/' + player_obj.id, filters.value).then((data) =>{
            if(data.success){
                shadow_team_list.value = data.shadow_team_list;
                filters.value.total = data.total;
                filters.value.max_page = Math.ceil(data.total / filters.value.max_per_page);
            } else{
                bootbox.alert(data.message);
            }
            loading.value = false;
        })
    }

    function setPage(page_no){
        if (page_no < 1 || page_no > filters.value.max_page) return;
        if (page_no == filters.value.page_no) return;
        filters.value.page_no = page_no;
        fetchAllShadowTeams();
    }
</script>

<template>
    <div class="row">
        <div class="col-md-12">
            <Card title="Shadow Teams" :show_footer="true" footer_class="d-flex align-items-center justify-content-between" header_class="px-3">
                <TableCont v-if="!loading && shadow_team_list.length > 0">
                    <thead>
                        <tr>
                            <th>SN</th>
                            <th>Name</th>
                            <th>Creation Date / Time</th>
                            <th>Report Author</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(shadow_team_obj,index) in shadow_team_list">
                            <td>{{ index + 1 + (filters.page_no - 1)*filters.max_per_page }}</td>
                            <td>
                                <a :href="base_url + '/shadow-team/info/' + shadow_team_obj.id" target="_blank">
                                    {{ shadow_team_obj.shadow_name }}
                                </a>
                            </td>
                            <td>
                                {{ shadow_team_obj.creation_date }}
                                /
                                {{ shadow_team_obj.creation_time }}
                            </td>
                            <td>{{ shadow_team_obj.name }}</td>
                        </tr>
                    </tbody>
                </TableCont>

                <LengthZero v-if="shadow_team_list.length == 0 && !loading"></LengthZero>
                <Loading :loading="loading" type="table"></Loading>

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