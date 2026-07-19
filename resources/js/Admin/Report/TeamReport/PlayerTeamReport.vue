<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import { useForm} from 'vee-validate';

    const {team_obj, dropdown_obj} = defineProps(['team_obj','dropdown_obj']);
    const { handleSubmit, resetForm} = useForm();
    const emit = defineEmits(['callback']);

    const team_data = ref({
        match_type_id: '',
        weather_id: '',
        field_condition_id: '',
        away_players: [],
        home_players: [],
        league_id: '',
        season_id: '',
        performance_id: '',
        best_player_id: '',
        worst_player_id: '',
        away_team_id: '',
        home_team_id: '',
        away_team_formation_id: '',
        home_team_formation_id: ''
    });

    watch(
        () => team_data.value.home_team_id,
        () => { getPlayerList() }
    )

    onMounted(() => {
        fetchReportDetails();
    });

    const match_type_list = ref([
        {label : 'Full Time', value : 1},
        {label : 'After Penalties', value : 2}
    ]);
    const player_list = ref([]);
    const loading = ref(false);
    const best_player_id = ref(0);
    const worst_player_id = ref(0);
    const got_report = ref(false);

    function fetchReportDetails(){
        if(team_obj.team_report_id == 0){
            return;
        }

        loading.value = true;
        DBService.getData('/api/team-report/fetch-report-details/' + team_obj.team_report_id + '/' + 0 ) .then( (data) => {
            if(data.success){
                team_data.value = data.report_obj;
                best_player_id.value = data.report_obj.best_player_id;
                worst_player_id.value = data.report_obj.worst_player_id;
                got_report.value = true;
            } else{
                bootbox.alert(data.message);
            }
            loading.value = false;
        });
    }

    function getPlayerList(){
        team_data.value.best_player_id = '';
        team_data.value.worst_player_id = '';
        if(!team_data.value.home_team_id || team_data.value.home_team_id == '' || team_data.value.home_team_id == 0){
            return;
        }

        loading.value = true;
        DBService.getData('/api/team-report/get-player-list/' + team_data.value.home_team_id ) .then( (data) => {
            if(data.success){
                player_list.value = data.player_list;
                if(got_report.value == true){
                    team_data.value.best_player_id = best_player_id.value ? best_player_id.value : '';
                    team_data.value.worst_player_id = worst_player_id.value ? worst_player_id.value : '';
                    got_report.value = false;
                }
            } else{
                bootbox.alert(data.message);
            }
            loading.value = false;
        });
    }

    const submitTeamReport = handleSubmit((values, {resetForm})=> {
        if(team_data.value.home_team_id == team_data.value.away_team_id){
            bootbox.alert('Home Team And Away Team Cannot Be Same');
        }
        loading.value = true;
        DBService.postData('/api/team-report/save-team-report',team_data.value) .then( (data) => {
            if(data.success){
                resetForm();
                emit('callback');
            }
            loading.value = false;
            bootbox.alert(data.message);
        });
    });

</script>

<template>
    <form @submit.prevent="submitTeamReport()">
        <div class="report-shell">
            <div class="row g-4">
                <div class="col-12">
                    <div class="section-card section-card--accent">
                        <div class="section-header">
                            <div>
                                <p class="section-kicker mb-1">Overview</p>
                                <h3 class="section-title mb-0">Match Information</h3>
                            </div>
                        </div>

                        <div class="row g-4">
                            <div class="col-lg-4">
                                <div class="sub-card h-100">
                                    <h4 class="sub-card-title">Stadium Information</h4>
                                    <div class="row g-3">
                                        <FormInput label="Stadium" name="stadium" cls='col-md-12' placeholder="Stadium Name" v-model="team_data.stadium"></FormInput>
                                        <FormSelect label="Weather" name="weather" cls="col-md-12" :options="dropdown_obj.weather_list" opt_id="id" opt_name="weather" v-model="team_data.weather_id"></FormSelect>
                                        <FormSelect label="Field Condition" name="field_condition" cls="col-md-12" :options="dropdown_obj.field_condition_list" opt_id="id" opt_name="field_condition" v-model="team_data.field_condition_id"></FormSelect>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-4">
                                <div class="sub-card sub-card--highlight h-100">
                                    <h4 class="sub-card-title text-center">Match Information</h4>
                                    <div class="row justify-content-center g-3">
                                        <FormSelect label="Report Type" name="match_type_id" cls="col-md-8 input-center-select" :options="match_type_list" v-model="team_data.match_type_id"></FormSelect>
                                        <FormInput label="Match Date" req="true" placeholder="dd-mm-yyyy" name="date" cls='col-md-8 input-center' v-model="team_data.date" type="date"></FormInput>
                                        <FormInput label="Match Location" name="location" cls='col-md-10 input-center' v-model="team_data.location" placeholder="Match Played In"></FormInput>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-4">
                                <div class="sub-card h-100 sub-card--right">
                                    <h4 class="sub-card-title text-lg-end">Game Information</h4>
                                    <div class="row justify-content-lg-end g-3">
                                        <FormSelect label="League" req="true" name="league_id" cls="col-md-12 input-end-select" :options="dropdown_obj.league_list" opt_id="id" opt_name="league_name" v-model="team_data.league_id"></FormSelect>
                                        <FormSelect label="Season" req="true" name="season_id" cls="col-md-12 input-end-select" :options="dropdown_obj.season_list" v-model="team_data.season_id"></FormSelect>
                                        <FormInput label="Round" name="round" cls='col-md-12 input-end' placeholder="Round" v-model="team_data.round"></FormInput>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="sub-card">
                                    <h4 class="sub-card-title">Note</h4>
                                    <div class="row g-3">
                                        <FormText name="match_note" label="General Note" cls="col-md-12" v-model="team_data.match_note" placeholder="General Information About The Match..."></FormText>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="section-card">
                        <div class="section-header">
                            <div>
                                <p class="section-kicker mb-1">Teams</p>
                                <h3 class="section-title mb-0">Team Details</h3>
                            </div>
                        </div>

                        <div class="row g-4 align-items-stretch team-section">
                            <div class="col-lg-5">
                                <div class="team-panel team-panel--home h-100">
                                    <div class="team-panel-header">
                                        <span class="team-badge">Home Team</span>
                                    </div>
                                    <div class="row g-3">
                                        <FormSelect label="Home Team Name" req="true" name="home_team_name" cls='col-md-12' :options="dropdown_obj.team_list" opt_id="id" opt_name="team_name" v-model="team_data.home_team_id"></FormSelect>
                                        <FormInput label="Home Team Score" placeholder="Goals Scored" type="number" name="home_team_score" cls='col-md-12 num-remove' v-model="team_data.home_team_score"></FormInput>
                                        <FormSelect label="Home Team Formation" name="home_team_formation" cls="col-md-12" :options="dropdown_obj.formation_list" opt_id="id" opt_name="formation" v-model="team_data.home_team_formation_id"></FormSelect>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-2 d-flex align-items-center justify-content-center">
                                <div class="versus-pill">vs</div>
                            </div>

                            <div class="col-lg-5">
                                <div class="team-panel team-panel--away h-100 text-end">
                                    <div class="team-panel-header justify-content-lg-end">
                                        <span class="team-badge team-badge--away">Away Team</span>
                                    </div>
                                    <div class="row justify-content-lg-end g-3">
                                        <FormSelect label="Away Team Name" req="true" name="away_team_name" cls='col-md-12 input-end-select' :options="dropdown_obj.team_list" opt_id="id" opt_name="team_name" v-model="team_data.away_team_id"></FormSelect>
                                        <FormInput label="Away Team Score" placeholder="Goals Scored" type="number" name="away_team_score" cls='col-md-12 input-end num-remove' v-model="team_data.away_team_score"></FormInput>
                                        <FormSelect label="Away Team Formation" name="away_team_formation" cls="col-md-12 input-end-select" :options="dropdown_obj.formation_list" opt_id="id" opt_name="formation" v-model="team_data.away_team_formation_id"></FormSelect>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="section-card">
                        <div class="section-header">
                            <div>
                                <p class="section-kicker mb-1">Assessment</p>
                                <h3 class="section-title mb-0">Home Team Review</h3>
                            </div>
                        </div>

                        <div class="row g-4">
                            <div class="col-lg-4">
                                <div class="sub-card h-100">
                                    <h4 class="sub-card-title">Ratings</h4>
                                    <div class="row g-3">
                                        <FormSelect req="true" label="Team Performance Rating" name="performance_id" cls='col-md-12' :options="dropdown_obj.built_in_array[3]" v-model="team_data.performance_id"></FormSelect>
                                        <FormSelect label="Best Player" name="best_player" cls='col-md-12' :options="player_list" opt_id="id" opt_name="name" v-model="team_data.best_player_id" :disabled="!team_data.home_team_id"></FormSelect>
                                        <FormSelect label="Worst Player" name="worst_player" cls='col-md-12' :options="player_list" opt_id="id" opt_name="name" v-model="team_data.worst_player_id" :disabled="!team_data.home_team_id"></FormSelect>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-8">
                                <div class="sub-card h-100">
                                    <h4 class="sub-card-title">Team Note</h4>
                                    <div class="row g-3">
                                        <FormText label="Team Note" name="team_note" cls="col-md-12" v-model="team_data.team_note" placeholder="Note About The Team Performance..." rows="4"></FormText>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="submit-bar">
                        <button class="btn btn-dark px-4" type="submit" :disabled="loading">Submit</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</template>


<style scoped>
    .report-shell{
        --tm-bg: linear-gradient(180deg, #f8fafc 0%, #eef4f7 100%);
        --tm-card: #ffffff;
        --tm-border: #d9e2ec;
        --tm-border-strong: #bfd0de;
        --tm-text: #183b56;
        --tm-muted: #5f7488;
        --tm-home: #eff6ff;
        --tm-home-border: #bfdbfe;
        --tm-away: #fff7ed;
        --tm-away-border: #fed7aa;
        --tm-accent: #0f766e;
        background: var(--tm-bg);
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        padding: 24px;
    }

    .section-card{
        background: rgba(255,255,255,0.82);
        border: 1px solid var(--tm-border);
        border-radius: 18px;
        padding: 24px;
        box-shadow: 0 14px 34px rgba(15, 23, 42, 0.06);
        backdrop-filter: blur(4px);
    }

    .section-card--accent{
        background: linear-gradient(180deg, rgba(255,255,255,0.95) 0%, rgba(244,250,252,0.95) 100%);
    }

    .section-header{
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 18px;
        padding-bottom: 14px;
        border-bottom: 1px solid #e7eef5;
    }

    .section-kicker{
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        color: var(--tm-accent);
    }

    .section-title{
        font-size: 1.3rem;
        font-weight: 800;
        color: var(--tm-text);
    }

    .sub-card{
        height: 100%;
        background: var(--tm-card);
        border: 1px solid var(--tm-border);
        border-radius: 16px;
        padding: 18px;
    }

    .sub-card--highlight{
        background: linear-gradient(180deg, #ffffff 0%, #f4f8fb 100%);
        border-color: var(--tm-border-strong);
    }

    .sub-card-title{
        margin-bottom: 16px;
        font-size: 1rem;
        font-weight: 700;
        color: var(--tm-text);
    }

    .team-panel{
        border-radius: 18px;
        padding: 20px;
        border: 1px solid var(--tm-border);
        background: var(--tm-card);
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.7);
    }

    .team-panel--home{
        background: linear-gradient(180deg, var(--tm-home) 0%, #ffffff 100%);
        border-color: var(--tm-home-border);
    }

    .team-panel--away{
        background: linear-gradient(180deg, var(--tm-away) 0%, #ffffff 100%);
        border-color: var(--tm-away-border);
    }

    .team-panel-header{
        display: flex;
        margin-bottom: 16px;
    }

    .team-badge{
        display: inline-flex;
        align-items: center;
        padding: 7px 12px;
        border-radius: 999px;
        background: #dbeafe;
        color: #1d4ed8;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .team-badge--away{
        background: #ffedd5;
        color: #c2410c;
    }

    .versus-pill{
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 62px;
        height: 62px;
        border-radius: 50%;
        background: #183b56;
        color: #fff;
        font-size: 18px;
        font-weight: 800;
        text-transform: uppercase;
        box-shadow: 0 10px 24px rgba(24, 59, 86, 0.18);
    }

    .submit-bar{
        display: flex;
        justify-content: flex-end;
        padding-top: 4px;
    }

    @media (max-width: 991px) {
        .report-shell{
            padding: 16px;
        }

        .section-card{
            padding: 18px;
        }

        .versus-pill{
            width: 52px;
            height: 52px;
            margin: 4px 0;
        }
    }
</style>

<style>
    .input-end input{
        text-align: right;
    }

    .input-center input{
        text-align: center;
    }

    .input-center-select select{
        text-align: center;
        text-align-last: center;
    }

    .input-end-select select{
        text-align: right;
        text-align-last: right;
    }

    .num-remove input::-webkit-outer-spin-button,
    .num-remove input::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
</style>
