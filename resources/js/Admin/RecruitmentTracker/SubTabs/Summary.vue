<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import { Pie } from 'vue-chartjs'
    import { Chart as ChartJS, Title, Tooltip, Legend, ArcElement } from 'chart.js'

    ChartJS.register(Title, Tooltip, Legend, ArcElement)

    const {reload, def_filters} = defineProps(['reload','def_filters']);
    const base_url = import.meta.env.VITE_APP_URL;
    const emit = defineEmits(['listEdit']);

    onMounted(
        () => {
            fetchDropdowns();
        }
    )

    const loading = ref(false);
    const foot_list = ref([]);
    const foot_arr = ref([]);
    const age_arr = ref([]);
    const position_arr = ref([]);
    const position_list = ref([]);
    const color_list = ref([]);
    const status_list = ref([]);
    const rec_pos_list = ref([]);
    const player_status_arr = ref([]);
    const players_arr = ref([]);
    const display_chart = ref(false);
    const rec_pos_id = ref(0);
    const total_players = ref(0);
    const player_index = ref(0)
    const options = {
        responsive: true,
        plugins: {
            legend: {
                position: 'top',
                display: false
            },
            title: {
                display: false,
                text: 'Pie Chart'
            }
        }
    }

    const pie_chart_data = ref({});
    
    function fetchDropdowns(){
        loading.value = true;
        DBService.getData('/api/recruitment-tracker/recruitment-position/fetch-dropdown/status/' + def_filters.rec_id).then((data) => {
            if(data.success){
                foot_list.value = data.foot_list;
                color_list.value = data.color_list;
                status_list.value = data.status_list;
                rec_pos_list.value = data.rec_pos_list;
                position_list.value = data.position_list;
                fetchPlayers();
            }
            loading.value = false;
        });
    }

    function fetchPlayers(){
        loading.value = true;
        DBService.getData('/api/recruitment-tracker/recruitment-position/fetch-players-details/' + def_filters.rec_id + '/' + rec_pos_id.value).then((data) => {
            if(data.success){
                player_status_arr.value = data.player_status_arr;
                players_arr.value = data.players_arr;
                age_arr.value = data.age_arr;
                foot_arr.value = data.foot_arr;
                position_arr.value = data.position_arr;
                total_players.value = data.total_players;
                pie_chart_data.value = {
                    labels: status_list.value,
                    datasets: [
                        {
                            label: '',
                            data: data.player_status_arr,
                            backgroundColor: color_list.value,
                            borderWidth: 1
                        }
                    ]
                }
                
                display_chart.value = true;
            }
            loading.value = false;
        });
    }

    function changedPositionId(temp_id){
        if(temp_id == rec_pos_id.value){
            return;
        }
        rec_pos_id.value = temp_id;
        display_chart.value = false;
        fetchPlayers();
    }

    function displayPlayerModal(temp_index){
        if(players_arr.value[temp_index].length == 0){
            return;
        }
        player_index.value = temp_index;
        $('#display-players-details-modal').modal('show');
    }
</script>

<template>
    <div class="row" v-if="!loading">
        <div class="col-md-2 text-center" v-if="display_chart && !loading">
            <h2>Status</h2>
            <Pie :data="pie_chart_data" :options="options"/>
        </div>
        <div class="col-md-7 mt-5">
            <div class="row">
                <div class="col-md-12 mb-3">
                    <h3>Status Info</h3>
                </div>
                <div class="d-flex flex-wrap col-md-12">
                    <div style="width: 23%; cursor: pointer; font-weight: 800;" class="text-light ms-3 mb-3 px-3 py-4 text-center" v-for="(status_obj, index) in status_list" :style="'background-color:' + color_list[index]" @click="displayPlayerModal(index)">
                        {{ status_obj }}
                        <br>
                        {{ players_arr[index] ? players_arr[index].length : 0 }}
                    </div>
                </div>
                
                <div class="col-md-12 mt-3">
                    <h3>Average Age</h3>
                </div>
                <div class="col-md-12 d-flex flex-wrap mt-3">
                    <div v-for="(color_obj, index) in color_list" class="ms-1 text-center py-3 text-light" :style="'font-weight: 800; background-color:' + color_obj" style="width: 12%;">
                        {{ age_arr[index] }}
                    </div>
                </div>

                <div class="col-md-12 mt-3">
                    <h3>Foot</h3>
                </div>
                <div class="col-md-12 d-flex flex-wrap mt-3">
                    <div v-for="foot_obj in foot_list" class="ms-1 me-2 text-center py-2" style="width: 12%; border: 1px black solid; font-weight: 800;">
                        {{ foot_obj.foot }}
                        <br>
                        {{ foot_arr[foot_obj.id] ? foot_arr[foot_obj.id] : '0' }}%
                    </div>
                </div>

                <div class="col-md-12 mt-3">
                    <h3>Positions</h3>
                </div>
                <div class="col-md-12 d-flex flex-wrap mt-3">
                    <div v-for="position_obj in position_list" class="ms-1 me-2 mb-2 text-center py-2" style="width: 12%; border: 1px black solid; font-weight: 800;">
                        {{ position_obj.position_short }}
                        <br>
                        {{ position_arr[position_obj.id] ? position_arr[position_obj.id] : '0' }}%
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 ms-5 mt-5 text-center" style="border-left: 1px black solid; max-height: 100%; overflow-y: auto;">
            <ul style="list-style-type: none; font-size: 15px;" class="mt-5">
                <li class="py-2 mb-2" :style="rec_pos_id == 0 ? 'background-color:#1a8cff' : ''" @click.prevent="changedPositionId(0)">All</li>
                <li class="py-2 mb-2" :style="rec_pos_id == rec_pos_obj.id ? 'background-color: #1a8cff' : ''" v-for="rec_pos_obj in rec_pos_list" @click.prevent="changedPositionId(rec_pos_obj.id)">
                    {{ rec_pos_obj.name }}
                </li>
            </ul>
        </div>
    </div>
    <div v-else>
        <Loading :loading="true"></Loading>
    </div>

    <Modal id="display-players-details-modal" title="Players">
        <TableCont>
            <thead>
                <tr>
                    <th>SN</th>
                    <th>Name</th>
                    <th>Recruitment Group</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(player_obj, idx) in players_arr[player_index]">
                    <td>{{ 1 + idx }}</td>
                    <td>{{ player_obj.name }}</td>
                    <td>{{ player_obj.rec_name }}</td>
                </tr>
            </tbody>
        </TableCont>
    </Modal>
</template>

<style scoped>
    li{
        background-color: #99ccff;
        cursor: pointer;
        border: 1px #66b3ff solid;
    }
    li:hover{
        background-color: #66b3ff;
        cursor: pointer;
        border: 1px #3399ff solid;
    }
</style>