<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import TextDesc from '@/Components/NewComponents/TextDesc.vue';

    const {team_report_obj} = defineProps(['team_report_obj']);
    const base_url = import.meta.env.VITE_APP_URL

    watch(
        () => team_report_obj.team_rep_id,
        () => { fetchTeamReportDetails(); }
    )

    const team_data = ref({});
    const loading = ref(false)

    function fetchTeamReportDetails(){
        if(team_report_obj.team_rep_id == 0){
            return;
        }
        loading.value = true;
        DBService.getData('/api/team-report/fetch-report-details/' + team_report_obj.team_rep_id + '/' + 1 ) .then( (data) => {
            if(data.success){
                team_data.value = data.report_obj;
            } else{
                bootbox.alert(data.message);
            }
            loading.value = false;
        });
    }
</script>

<template>
    <div class="row" v-if="!loading">
        <div class="col-md-12">
            <h4 style="font-weight: 800;">General Information</h4>
            <hr>
        </div>
        <div class="col-md-4 px-4">
            <table class="table table-hover">
                <tbody>
                    <tr>
                        <th>Stadium:</th>
                        <td>{{ team_data.stadium ? team_data.stadium : "N/A" }}</td>
                    </tr>
                    <tr>
                        <th>Weather:</th>
                        <td>{{ team_data.weather ? team_data.weather : "N/A" }}</td>
                    </tr>
                    <tr>
                        <th>Field Condition:</th>
                        <td>{{ team_data.field_condition ? team_data.field_condition : "N/A" }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="col-md-4 px-4">
            <table class="table table-hover">
                <tbody>
                    <tr>
                        <th>Report Type:</th>
                        <td>{{ team_data.match_type_id ? (team_data.match_type_id == 1 ? "Full Time" : "Penalties") : 'N/A' }}</td>
                    </tr>
                    <tr>
                        <th>Match Date:</th>
                        <td>{{ team_data.display_date ? team_data.display_date : "N/A" }}</td>
                    </tr>
                    <tr>
                        <th>Match Locaation:</th>
                        <td>{{ team_data.location ? team_data.location : "N/A" }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="col-md-4 px-4">
            <table class="table table-hover">
                <tbody>
                    <tr>
                        <th>League:</th>
                        <td>{{ team_data.league_name ? team_data.league_name : "N/A" }}</td>
                    </tr>
                    <tr>
                        <th>Season:</th>
                        <td>{{ team_data.display_season ? team_data.display_season : "N/A" }}</td>
                    </tr>
                    <tr>
                        <th>Round:</th>
                        <td>{{ team_data.round ? team_data.round : "N/A" }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="col-md-12 px-4 text-center" style="color: #737373;">
            <TextDesc color="#737373" :text='team_data.match_note ? team_data.match_note : "No Note Saved"'></TextDesc>
            <!-- {{ team_data.match_note ? team_data.match_note : "No Note Saved" }} -->
        </div>

        <div class="col-md-12 mt-4">
            <h4 style="font-weight: 800;">Team Information</h4>
            <hr>
        </div>

        <div class="col-md-12 px-4">
            <table class="table table-hover ">
                <thead>
                    <tr>
                        <td></td>
                        <th>Team Name</th>
                        <th>Goals Scored</th>
                        <th>Match Formation</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th>Home Team</th>
                        <td>{{ team_data.display_home_team_name ? team_data.display_home_team_name : "N/A" }}</td>
                        <td>{{ team_data.home_team_score ? team_data.home_team_score : "N/A" }}</td>
                        <td>{{ team_data.display_home_team_formation ? team_data.display_home_team_formation : "N/A" }}</td>
                    </tr>
                    <tr>
                        <th>Away Team</th>
                        <td>{{ team_data.display_away_team_name ? team_data.display_away_team_name : "N/A" }}</td>
                        <td>{{ team_data.away_team_score ? team_data.away_team_score : "N/A" }}</td>
                        <td>{{ team_data.display_away_team_formation ? team_data.display_away_team_formation : "N/A" }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="col-md-12 mt-4">
            <h4 style="font-weight: 800;">Home Team Review</h4>
            <hr>
        </div>
        <div class="col-md-3 px-4">
            <table class="table table-hover">
                <tbody>
                    <tr>
                        <th>Performance Rating</th>
                        <td>{{ team_data.display_performance ? team_data.display_performance : "N/A" }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="col-md-3 px-4">
            <table class="table table-hover">
                <tbody>
                    <tr>
                        <th>Team Total Wins</th>
                        <td>{{ team_data.wins ? team_data.wins : "0" }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="col-md-3 px-4">
            <table class="table table-hover">
                <tbody>
                    <tr>
                        <th>Team Total Draws</th>
                        <td>{{ team_data.draws ? team_data.draws : "0" }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="col-md-3 px-4">
            <table class="table table-hover">
                <tbody>
                    <tr>
                        <th>Team Total Losses</th>
                        <td>{{ team_data.losses ? team_data.losses : "0" }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="col-md-4 px-4 mt-3">
            <div class="row">
                <div class="col-md-10">
                    <h5 class="ps-1" style="font-weight: 800;">Best Player</h5>
                </div>
                <div class="col-md-2" v-if="team_data.best_player && team_data.best_player.id">
                    <a target="_blank" :href="base_url + '/player-information/' + team_data.best_player.id" class="btn btn-sm btn-outline-primary bi bi-box-arrow-up-right"></a>
                </div>
            </div>
            <hr class="mt-0 mb-1">
            <table class="table table-hover" v-if="team_data.best_player && team_data.best_player.id">
                <tbody>
                    <tr>
                        <th>Name :</th>
                        <td>{{ team_data.best_player ? team_data.best_player.name : "N/A" }}</td>
                    </tr>
                    <tr>
                        <th>Age :</th>
                        <td>{{ team_data.best_player ? team_data.best_player.age : "N/A" }}</td>
                    </tr>
                    <tr>
                        <th>Foot :</th>
                        <td>{{ team_data.best_player ? team_data.best_player.foot : "N/A" }}</td>
                    </tr>
                    <tr>
                        <th>Club :</th>
                        <td>{{ team_data.best_player ? team_data.best_player.club_name : "N/A" }}</td>
                    </tr>
                    <tr>
                        <th>Contract Expiry Date :</th>
                        <td>{{ team_data.best_player ? team_data.best_player.display_contract_expiry_date : "N/A" }}</td>
                    </tr>
                </tbody>
            </table>
            <div style="background-color: #ffff88; font-weight: 500;" class="px-2 py-3 mt-2" v-else>
                Player Not Assigned
            </div>
        </div>
        <div class="col-md-4">

        </div>
        <div class="col-md-4 px-4 mt-3">
            <div class="row">
                <div class="col-md-10">
                    <h5 class="ps-1" style="font-weight: 800;">Worst Player</h5>
                </div>
                <div class="col-md-2" v-if="team_data.worst_player && team_data.worst_player.id">
                    <a target="_blank" :href="base_url + '/player-information/' + team_data.worst_player.id" class="btn btn-sm btn-outline-primary bi bi-box-arrow-up-right"></a>
                </div>
            </div>
            <hr class="mt-0 mb-1">
            <table class="table table-hover" v-if="team_data.worst_player && team_data.worst_player.id">
                <tbody>
                    <tr>
                        <th>Name :</th>
                        <td>{{ team_data.worst_player ? team_data.worst_player.name : "N/A" }}</td>
                    </tr>
                    <tr>
                        <th>Age :</th>
                        <td>{{ team_data.worst_player ? team_data.worst_player.age : "N/A" }}</td>
                    </tr>
                    <tr>
                        <th>Foot :</th>
                        <td>{{ team_data.worst_player ? team_data.worst_player.foot : "N/A" }}</td>
                    </tr>
                    <tr>
                        <th>Club :</th>
                        <td>{{ team_data.worst_player ? team_data.worst_player.club_name : "N/A" }}</td>
                    </tr>
                    <tr>
                        <th>Contract Expiry Date :</th>
                        <td>{{ team_data.worst_player ? team_data.worst_player.display_contract_expiry_date : "N/A" }}</td>
                    </tr>
                </tbody>
            </table>
            <div style="background-color: #ffff88; font-weight: 500;" class="px-2 py-3 mt-2" v-else>
                Player Not Assigned
            </div>
        </div>

        <div class="col-md-12 px-4 text-center mt-4" style="color: #737373;">
            <TextDesc color="#737373" :text="team_data.team_note ? team_data.team_note : 'No Note Saved'"></TextDesc>
            <!-- {{ team_data.team_note ? team_data.team_note : "No Note Saved" }} -->
        </div>
    </div>
    <Loading :loading="loading" type="table"></Loading>
</template>

<style scoped>
    .table th{
        border-bottom: 0px black solid !important;
    }
    .table td{
        border-bottom: 0px black solid !important;
    }
</style>