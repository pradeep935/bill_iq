<script setup>
    import { ref, onMounted } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import Card from '@/Components/NewComponents/Card.vue';
    import ViewTeamReportModal from '@/Admin/Report/TeamReport/ViewTeamReportModal.vue';

    const {staff_id} = defineProps(['staff_id']);
    const base_url = import.meta.env.VITE_APP_URL;

    const loading_indv = ref(false);
    const loading_team = ref(false);
    const report_list = ref([]);
    const team_report_list = ref([]);
    const team_report_data = ref({});

    const filters = ref({
        page_no: 1,
        max_per_page: 5,
        max_page: 1,
        total: 0
    });

    const team_filters = ref({
        page_no: 1,
        max_per_page: 5,
        max_page: 1,
        total: 0
    });

    onMounted(() => {
        fetchStaffReports();
    });

    function fetchStaffReports(){
        loading_indv.value = true;
        loading_team.value = true;

        DBService.postData('/api/player-report/fetch-staff-reports/' + staff_id, {
            player_page_no: filters.value.page_no,
            player_max_per_page: filters.value.max_per_page,
            team_page_no: team_filters.value.page_no,
            team_max_per_page: team_filters.value.max_per_page
        }).then((data) => {
            if(data.success){
                report_list.value = data.report_list;
                team_report_list.value = data.team_report_list;

                filters.value.total = data.player_total;
                filters.value.max_page = data.player_max_page;
                team_filters.value.total = data.team_total;
                team_filters.value.max_page = data.team_max_page;
            } else{
                bootbox.alert(data.message);
            }

            loading_indv.value = false;
            loading_team.value = false;
        });
    }

    function showTeamReport(team_report_temp_id){
        team_report_data.value.team_rep_id = team_report_temp_id;
        $("#team_report_view").modal('show');
    }

    function openIndividualReport(temp_id){
        window.open(base_url + '/player-report/info/' + temp_id, '_blank');
    }

    function openTeamReport(temp_id){
        window.open(base_url + '/team-report/info/' + temp_id, '_blank');
    }

    function setPageIndv(page_no){
        if(page_no < 1 || page_no > filters.value.max_page) return;
        if(page_no == filters.value.page_no) return;
        filters.value.page_no = page_no;
        fetchStaffReports();
    }

    function setPageTeam(page_no){
        if(page_no < 1 || page_no > team_filters.value.max_page) return;
        if(page_no == team_filters.value.page_no) return;
        team_filters.value.page_no = page_no;
        fetchStaffReports();
    }
</script>

<template>
    <div class="row mb-4">
        <div class="col-md-12">
            <Card title="Individual Reports Added" header_class="px-3" :show_footer="true" footer_class="d-flex align-items-center justify-content-between">
                <TableCont v-if="report_list.length > 0 && !loading_indv">
                    <thead>
                        <tr>
                            <th>SN</th>
                            <th>Player</th>
                            <th>Potential</th>
                            <th>Performance</th>
                            <th>Score</th>
                            <th>Author</th>
                            <th>Creation Date</th>
                            <th class="text-end">#</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(report_obj,index) in report_list" :key="report_obj.id">
                            <td>{{ index + 1 + (filters.page_no - 1)*filters.max_per_page }}</td>
                            <td>
                                <a :href="base_url + '/player-report/info/' + report_obj.id" style="color: #0052cc;" target="_blank">
                                    {{ report_obj.player_name }}
                                </a>
                            </td>
                            <td>{{ report_obj.display_potential }}</td>
                            <td>{{ report_obj.display_grade }}</td>
                            <td>{{ report_obj.total_score }}</td>
                            <td>{{ report_obj.name }}</td>
                            <td>{{ report_obj.display_created_at }}</td>
                            <td class="text-end">
                                <Button2 :disabled="loading_indv" cls="btn-ghost-primary btn-sm" @click="openIndividualReport(report_obj.id)"><i class="bi bi-eye"></i></Button2>
                            </td>
                        </tr>
                    </tbody>
                </TableCont>

                <Loading :loading="loading_indv" type="table" :max="5"></Loading>
                <LengthZero v-if="!loading_indv && report_list.length == 0" cls="ms-4 py-3 mt-2"></LengthZero>

                <template v-slot:footer_slot>
                    <Pagination :filters="filters" @set-page="(page_no) => setPageIndv(page_no)" />
                </template>
            </Card>
        </div>

        <div class="col-md-12 mt-4">
            <Card title="Team Reports Added" header_class="px-3" :show_footer="true" footer_class="d-flex align-items-center justify-content-between">
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
                        <tr v-for="(team_report_obj,index) in team_report_list" :key="team_report_obj.id">
                            <td>{{ index + 1 + (team_filters.page_no - 1)*team_filters.max_per_page }}</td>
                            <td>
                                <span @click.prevent="showTeamReport(team_report_obj.id)" style="color: #0052cc; cursor: pointer;">Home Team</span>
                                <br>
                                <span @click.prevent="showTeamReport(team_report_obj.id)" style="color: #ff5050; cursor: pointer;">Away Team</span>
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
                                <Button2 :disabled="loading_team" cls="btn-ghost-primary btn-sm" @click="openTeamReport(team_report_obj.id)"><i class="bi bi-eye"></i></Button2>
                            </td>
                        </tr>
                    </tbody>
                </TableCont>

                <Loading :loading="loading_team" type="table" :max="5"></Loading>
                <LengthZero v-if="!loading_team && team_report_list.length == 0" cls="ms-4 py-3 mt-2"></LengthZero>

                <template v-slot:footer_slot>
                    <Pagination :filters="team_filters" @set-page="(page_no) => setPageTeam(page_no)" />
                </template>
            </Card>
        </div>
    </div>

    <Modal id="team_report_view" title="Team Report" size="modal-xl">
        <ViewTeamReportModal :team_report_obj="team_report_data"></ViewTeamReportModal>
    </Modal>
</template>
