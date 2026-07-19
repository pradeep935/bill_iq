<script setup>
    import { ref, watch, onMounted, computed } from 'vue';
    import DBService from '@/Service/Utils/DBService';

    const props = defineProps(['team_id']);
    const loading = ref(false);
    const report_list = ref([]);
    const report_for_obj = ref({});
    const base_url = import.meta.env.VITE_APP_URL

    onMounted(()=>{
        fetchPlayersReports();
    });
    
    function fetchPlayersReports(){
        loading.value = true;
        DBService.postData("/api/teams/get-palyers-reports/" + props.team_id).then((data)=>{
            if (data.success) {
                report_list.value = data.report_list;
            }
            loading.value = false;
        });
    };

    function viewPlayerReport(index){
        report_for_obj.value.template_id = report_list.value[index].template_id;
        report_for_obj.value.player_id = report_list.value[index].player_id;
        report_for_obj.value.id = report_list.value[index].id;
        report_for_obj.value.is_viewing = true;

        // window.location.href = base_url + '/player-report/info/' + report_list.value[index].id;
        window.open(base_url + '/player-report/info/' + report_list.value[index].id, '_blank');
    };

</script>

<template>
    <Loading :loading="loading" type="table" />
    <div v-if="!loading">
        <div class="card shadow">

            <!-- <div class="card-body"> -->
                <div v-if="report_list.length > 0">
                    <TableCont>
                        <thead>
                            <tr>
                                <th>SN</th>
                                <th>Player</th>
                                <th>Potential</th>
                                <th>Performance</th>
                                <th>Score</th>
                                <th>Creation Date</th>
                                <th class="text-end">#</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(report_obj,index) in report_list">
                                <td>{{ index + 1 }}</td>
                                <td class="text-capitalize" >{{ report_obj.name }}</td>
                                <td>{{ report_obj.display_potential }}</td>
                                <td>{{ report_obj.display_grade }}</td>
                                <td>{{ report_obj.total_score }}</td>
                                <td>{{ report_obj.display_created_at }}</td>
                                <td class="text-end">
                                    <Button2 cls="btn-secondary btn-sm" @click="viewPlayerReport(index)" >
                                        <i class="bi bi-eye" ></i>
                                    </Button2>
                                </td>
                            </tr>
                        </tbody>
                    </TableCont>
                </div>
                <div class="ms-3 mt-5 mb-5 text-center" v-if="report_list.length == 0">
                    <div class="scout-font-color" >
                        <h4>No Reported Players</h4>
                        <p>No Players have been reported.</p>
                    </div>
                </div>
            </div>
        <!-- </div>  -->

    </div>
</template>
<style>
    .scout-font-color{
        color: #9e9e9f;
    }
</style>