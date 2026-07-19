<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import Card from '@/Components/NewComponents/Card.vue';
    import ViewTeamReportModal from '@/Admin/Report/TeamReport/ViewTeamReportModal.vue';

    const emit = defineEmits(['playerRefresh']);
    const {player_obj} = defineProps(['player_obj']);
    const base_url = import.meta.env.VITE_APP_URL;

    onMounted(() => {
        fetchIndividualReport();
        fetchTeamReport();

        // $('#add_extra_notes').on('hidden.bs.modal', () => {
        //     need_refresh.value = false;
        // });
    });

    const loading_indv = ref(false);
    const loading_team = ref(false);
    const del_indv = ref({});
    const del_team = ref({});
    const report_list = ref([]);
    const team_report_list = ref([]);
    const team_report_data = ref({});
    const filters = ref({
        page_no: 1,
        max_per_page: 5,
        max_page: 1,
        total: 0
    })
    const def_filters = ref({
        page_no: 1,
        max_per_page: 5,
        max_page: 1,
        total: 0
    })

    function fetchIndividualReport(){
        loading_indv.value = true;
        DBService.postData('/api/player-report/fetch-report/' + player_obj.id, filters.value) .then( (data) => {
            if(data.success){
                report_list.value = data.report_list;
                loading_indv.value = false;
                // emit('playerRefresh');
                filters.value.total = data.total;
                filters.value.max_page = Math.ceil(data.total / filters.value.max_per_page);
            } else{
                bootbox.alert(data.message);
            }
        });
    }

    function fetchTeamReport(){
        loading_team.value = true;
        DBService.postData('/api/team-report/fetch-report/' + player_obj.id, def_filters.value) .then( (data) => {
            if(data.success){
                team_report_list.value = data.team_report_list;
                loading_team.value = false;
                def_filters.value.total = data.total;
                def_filters.value.max_page = Math.ceil(data.total / def_filters.value.max_per_page);
                // emit('playerRefresh');
            } else{
                bootbox.alert(data.message);
            }
        });
    }

    function showTeamReport(team_report_temp_id){
        team_report_data.value.team_rep_id = team_report_temp_id;
        $("#team_report_view").modal('show');
    }

    function deleteTeamReport(report_id){
        del_team.value[report_id] = true;
        bootbox.confirm("Are you sure?",(check)=> {
            if (check) {
                DBService.getData('/api/team-report/delete-team-report/' + report_id) .then( (data) => {
                    if(data.success){
                        if(team_report_list.value.length == 1 && def_filters.value.page_no != 1){
                            def_filters.value.page_no--;
                        }
                        fetchTeamReport();
                    }
                    del_team.value[report_id] = false;
                    bootbox.alert(data.message);
                });
            } else{
                del_team.value[report_id] = false;
            }
        });
    }

    function deletePlayerReport(report_obj_id){
        del_indv.value[report_obj_id] = true;
        bootbox.confirm("Are you sure?",(check)=> {
            if (check) {
                DBService.getData('/api/player-report/delete-player-report/' + report_obj_id) .then( (data) => {
                    if(data.success){
                        if(report_list.value.length == 1 && filters.value.page_no != 1){
                            filters.value.page_no--;
                        }
                        fetchIndividualReport();
                    }
                    del_indv.value[report_obj_id] = false;
                    bootbox.alert(data.message);
                });
            } else{
                del_indv.value[report_obj_id] = false;
            }
        });
    }

    function openIndividualReport(temp_id){
        loading_indv.value = true;
        const url = base_url + '/player-report/info/-' + temp_id;
        window.open(url, '_blank');
        loading_indv.value = false;
    }

    function openTeamReport(temp_id){
        loading_team.value = true;
        const url = base_url + '/team-report/info/' + temp_id;
        window.open(url, '_blank');
        loading_team.value = false;
    }

    function setPageIndv(page_no){
        loading_indv.value = true;
        if (page_no < 1 || page_no > filters.value.max_page) return;
        if (page_no == filters.value.page_no) return;
        filters.value.page_no = page_no;
        fetchIndividualReport();
    }

    function setPageTeam(page_no){
        loading_team.value = true;
        if (page_no < 1 || page_no > def_filters.value.max_page) return;
        if (page_no == def_filters.value.page_no) return;
        def_filters.value.page_no = page_no;
        fetchTeamReport();
    }
</script>

<template>
    <div class="row mb-4">
        <div class="col-md-12">
            <Card title="Individual Report" header_class="px-3" :show_footer="true" footer_class="d-flex align-items-center justify-content-between">
                <TableCont v-if="report_list.length > 0 && !loading_indv">
                    <thead>
                        <tr>
                            <th>SN</th>
                            <th>Potential</th>
                            <th>Performance</th>
                            <th>Score</th>
                            <th>Author</th>
                            <th>Creation Date</th>
                            <th class="text-end">#</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(report_obj,index) in report_list">
                            <td>{{ index + 1 + (filters.page_no - 1)*filters.max_per_page }}</td>
                            <td>
                                <a :href="base_url + '/player-report/info/' + report_obj.id" style="color: #0052cc;" target="_blank">
                                    {{ report_obj.display_potential }}
                                </a>
                            </td>
                            <td>{{ report_obj.display_grade }}</td>
                            <td>{{ report_obj.total_score }}</td>
                            <td>{{ report_obj.name }}</td>
                            <td>{{ report_obj.display_created_at }}</td>
                            <td class="text-end">
                                <Button2 :disabled="loading_indv" cls="btn-ghost-primary btn-sm me-1" @click="openIndividualReport(report_obj.id)"><i class="bi bi-pencil" ></i></Button2>
                                <Button2 :disabled="loading_indv" :processing="del_indv[report_obj.id]" cls="btn-danger btn-sm" @click="deletePlayerReport(report_obj.id)" ><i class="bi bi-trash"></i></Button2>
                                <!-- <a target="_blank" :href="base_url + '/player-report/info/-' + report_obj.id" class="icon-note me-2 bg-warning text-light p-2"></a>
                                <i class="icon-trash bg-danger text-light p-2 mx-1" @click.prevent="deletePlayerReport(report_obj.id)" style="cursor: pointer;"></i> -->
                            </td>
                        </tr>
                    </tbody>
                </TableCont>

                <Loading :loading="loading_indv" type="table" :max="5"></Loading>
                <LengthZero v-if="!loading_indv && report_list.length == 0" cls="ms-4 py-3 mt-2"></LengthZero>

                <template v-slot:header_slot>
                    <a target="_blank" :href="base_url + '/player-report/info/add'" class="btn btn-success btn-sm">+ Add Individual Report</a>
                </template>

                <template v-slot:footer_slot>
                    <Pagination
                        :filters="filters"
                        @set-page="(page_no) => setPageIndv(page_no)"
                    />
                </template>
            </Card>
        </div>

        <div class="col-md-12 mt-4">
            <Card title="Team Report" header_class="px-3" :show_footer="true" footer_class="d-flex align-items-center justify-content-between">
                <TableCont v-if="!loading_team && team_report_list.length > 0">
                    <thead>
                        <tr>
                            <th>SN</th>
                            <th>Team</th>
                            <th>Team Name</th>
                            <th>Score</th>
                            <th>Match Date</th>
                            <th>Creation Date</th>
                            <th class="text-end">#</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(team_report_obj,index) in team_report_list">
                            <td>{{ index + 1 + (def_filters.page_no - 1)*def_filters.max_per_page }}</td>
                            <td>
                                <span  @click.prevent="showTeamReport(team_report_obj.id)" style="color: #0052cc; cursor: pointer;">Home Team</span>
                                <br>
                                <span  @click.prevent="showTeamReport(team_report_obj.id)" style="color: #ff5050; cursor: pointer;">Away Team</span>
                            </td>
                            <td>
                                {{ team_report_obj.display_home_team_name }}
                                <br>
                                {{ team_report_obj.display_away_team_name }}
                            </td>
                            <td>
                                {{ team_report_obj.home_team_score }}
                                <br>
                                {{ team_report_obj.away_team_score }}
                            </td>
                            <td>{{ team_report_obj.display_date }}</td>
                            <td>{{ team_report_obj.display_created_at }}</td>
                            <td class="text-end">
                                <Button2 :disabled="loading_team" cls="btn-ghost-primary btn-sm me-1" @click="openTeamReport(team_report_obj.id)"><i class="bi bi-pencil" ></i></Button2>
                                <Button2 :disabled="loading_team" :processing="del_team[team_report_obj.id]" cls="btn-danger btn-sm" @click="deleteTeamReport(team_report_obj.id)" ><i class="bi bi-trash"></i></Button2>
                                <!-- <a target="_blank" :href="base_url + '/team-report/info/' + team_report_obj.id" class="icon-note me-2 bg-warning text-light p-2"></a>
                                <i class="icon-trash bg-danger text-light mx-1 p-2" @click.prevent="deleteTeamReport(team_report_obj.id)" style="cursor: pointer;"></i> -->
                            </td>
                        </tr>
                    </tbody>
                </TableCont>

                <Loading :loading="loading_team" type="table" :max="5"></Loading>
                <LengthZero v-if="!loading_team && team_report_list.length == 0" cls="ms-4 py-3 mt-2"></LengthZero>

                <template v-slot:header_slot>
                    <a target="_blank" :href="base_url + '/team-report/info/add'" class="btn btn-success btn-sm">+ Add Team Report</a>
                </template>

                <template v-slot:footer_slot>
                    <Pagination
                        :filters="def_filters"
                        @set-page="(page_no) => setPageTeam(page_no)"
                    />
                </template>
            </Card>
        </div>
    </div>

    <Modal id="team_report_view" title="Team Report" size="modal-xl">
        <ViewTeamReportModal :team_report_obj="team_report_data"></ViewTeamReportModal>
    </Modal>
</template>