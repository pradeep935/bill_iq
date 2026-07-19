<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import HoverMessage from '@/Components/NewComponents/HoverMessage.vue';
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import ClubHistoryDetailModal from '@/Admin/PlayerInformation/Modals/ClubHistoryDetailModal.vue';
    import Card from '@/Components/NewComponents/Card.vue';
    import CardDisplay from '@/Components/NewComponents/CardDisplay.vue';

    const {player_obj} = defineProps(['player_obj']);

    onMounted(() => {
        fetchClubHistory();
        assignPosition()
    });

    const loading = ref(false);
    const loading_club = ref(false);
    const loading_edit = ref([]);
    const loading_del = ref([]);
    const club_sum = ref({});
    const club_history_id = ref(0);
    const deleting_id = ref(0);
    const club_history_list = ref([]);
    const position_arr = ref([]);
    const filters = ref({
        page_no: 1,
        max_per_page: 5,
        max_page: 1,
        total: 0
    })

    const personal = [
        {label: 'Full Name', value: 'name', type: 'text'},
        {label: 'Father`s Name', value: 'father_name', type: 'text'},
        {label: 'Date Of Birth', value: 'dob', type: 'date', format_type: 1},
        {label: 'Nationality', value: 'iso', type: 'text'},
        {label: 'Birth State', value: 'state', type: 'text'},
        {label: 'Contact Number', value: 'mobile', type: 'text'},
        {label: 'EMail', value: 'email', type: 'text'},
    ]

    const professional = [
        {label: 'Current Club', value: 'club_name', type: 'text'},
        {label: 'Shirt Name', value: 'shirt_name', type: 'text'},
        {label: 'Contract Expiry Date', value: 'contract_expiry_date', type: 'date', format_type: 1},
        {label: 'Annual Salary', value: 'annual_salary', type: 'money'},
        {label: 'Tournament', value: 'tournament', type: 'text'},
        {label: 'International Player', value: 'international_player', opts: ['No','Yes'], type: 'options'},
        {label: 'Locally Developed', value: 'locally_developed', opts: ['No','Yes'], type: 'options'},
    ]

    const other = [
        {label: 'Biotype', value: 'biotype_name', type: 'text'},
        {label: 'Comparitive Height', value: 'height_comparative', type: 'text'},
        {label: 'Maturity Rate', value: 'maturity_rate', type: 'text'},
        {label: 'Studies', value: 'studies', type: 'text'},
        {label: 'Position Profile', value: 'position_profile', type: 'text'},
        {label: 'Birth Quartile', value: 'player_quartile', type: 'text'},
    ]

    const representative = [
        {label: 'Name', value: 'rep_name', type: 'text'},
        {label: 'Mobile', value: 'rep_mobile', type: 'text'},
        // {label: 'Contract Expiry Date', value: 'rep_contract_expiry_date', type: 'date', format_type: 1},
        {label: 'Link', value: 'rep_link', type: 'link', link_text: 'Click Here'},
    ]

    const social = [
        {label: 'Facebook', value: 'facebook', link_text: 'Facebook', show_icon: true, type: 'link', icon: 'me-1 bi bi-facebook', icon_color: '#1877F2'},
        {label: 'Instagram', value: 'instagram', link_text: 'Instagram', show_icon: true, type: 'link', icon: 'me-1 bi bi-instagram insta-icon', icon_color: '#833AB4'},
        {label: 'X (Twitter)', value: 'twitter', link_text: 'X', show_icon: true, type: 'link', icon: 'me-1 bi bi-twitter-x', icon_color: '#000000'},
        {label: 'LinkedIn', value: 'linkedin', link_text: 'LinkedIn', show_icon: true, type: 'link', icon: 'me-1 bi bi-linkedin', icon_color: '#0077B5'},
    ]

    function fetchClubHistory(){
        loading_club.value = true;
        console.log(player_obj.id);
        DBService.postData('/api/player-information/fetch-club-history-list/' + player_obj.id, filters.value) .then( (data) => {
            if(data.success){
                club_history_list.value = data.club_history_list;
                club_sum.value = data.club_sum;
                filters.value.total = data.total;
                filters.value.max_page = Math.ceil(data.total / filters.value.max_per_page);
            } else{
                bootbox.alert(data.message);
            }
            loading_club.value = false;
        });
    }

    function openClubHistoryModal(club_list_id){
        loading_edit.value[club_list_id] = true;
        club_history_id.value = club_list_id;
        $("#add_club_history").modal('show');
        loading_edit.value[club_list_id] = false;
    }

    function deleteClubHistory(club_list_id){
        loading_del.value[club_list_id] = true;
        deleting_id.value = club_list_id;
        bootbox.confirm("Are you sure you wants to delete this club history?",(check)=> {
            if (check) {
                loading.value = true;
                DBService.getData('/api/player-information/delete-club-history/' + club_list_id) .then( (data) => {
                    if(data.success){
                        if(club_history_list.value.length == 1 && filters.value.page_no != 1){
                            filters.value.page_no--;
                        }
                        fetchClubHistory();
                        loading.value = false;
                        loading_del.value[club_list_id] = false;
                    }
                    bootbox.alert(data.message);
                });
            } else{
                loading_del.value[club_list_id] = false;
            }
            deleting_id.value = 0;
        });
    }

    function assignPosition(){
        if(player_obj.position_first_id){
            position_arr.value.push(player_obj.position_first_id);
        }
        if(player_obj.position_second_id){
            position_arr.value.push(player_obj.position_second_id);
        }
    }

    function setPage(page_no){
        if (page_no < 1 || page_no > filters.value.max_page) return;
        if (page_no == filters.value.page_no) return;
        filters.value.page_no = page_no;
        fetchClubHistory();
    }
</script>

<template>
    <div class="row mb-4">
        <CardDisplay title="Personal Information" body_class="mb-3" cls="col-md-4" :arr="personal" :obj="player_obj"></CardDisplay>
        <CardDisplay title="Professional Details" body_class="mb-3" cls="col-md-4" :arr="professional" :obj="player_obj"></CardDisplay>
        <CardDisplay title="Other Details" body_class="mb-3" cls="col-md-4" :arr="other" :obj="player_obj"></CardDisplay>
        <CardDisplay title="Representative Details" body_class="mb-3" cls="col-md-4 mt-3" :arr="representative" :obj="player_obj"></CardDisplay>
        <CardDisplay title="Social Media" body_class="mb-3" cls="col-md-4 mt-3" :arr="social" :obj="player_obj"></CardDisplay>
    </div>

    <div class="row mt-3">
        <div class="col-md-12">
            <Card title="Club History" type="table" :show_footer="true" footer_class="d-flex align-items-center justify-content-between" header_class="px-3">
                <TableCont table_class="table-hover" v-if="club_history_list.length > 0 && !loading_club">
                    <thead>
                        <tr>
                            <th>SN</th>
                            <th>Club Name</th>
                            <th>Season</th>
                            <th>Matches</th>
                            <th>
                                <HoverMessage message="Minutes">
                                    Min
                                </HoverMessage>
                            </th>
                            <th>Goals</th>
                            <th>
                                <HoverMessage message="Assists">
                                    Ast
                                </HoverMessage>
                            </th>
                            <th>
                                <HoverMessage message="Yellow Cards">
                                    YC
                                </HoverMessage>
                            </th>
                            <th>
                                <HoverMessage message="Red Cards">
                                    RC
                                </HoverMessage>
                            </th>
                            <th class="text-right">

                            </th>
                        </tr>
                    </thead>
            
                    <tbody>
                        <tr v-for="(club_history,index) in club_history_list" :style="deleting_id == club_history.id ? 'background-color : #cccccc;' : ''">
                            <td>{{ index + 1 + (filters.page_no-1)*filters.max_per_page }}</td>
                            <td>{{ club_history.club_name }}</td>
                            <td>{{ club_history.season }}</td>
                            <td>{{ club_history.total_match }}</td>
                            <td>{{ club_history.minutes_played }}</td>
                            <td>{{ club_history.total_goal }}</td>
                            <td>{{ club_history.total_assist }}</td>
                            <td>{{ club_history.red_cards }}</td>
                            <td>{{ club_history.yellow_cards }}</td>
                            <td class="text-end">
                                <Button2 :processing="loading_edit[club_history.id]" cls="btn-ghost-primary btn-sm me-1" @click="openClubHistoryModal(club_history.id)"><i class="bi bi-pencil" ></i></Button2>
                                <Button2 :processing="loading_del[club_history.id]" cls="btn-danger btn-sm" @click="deleteClubHistory(club_history.id)" ><i class="bi bi-trash"></i></Button2>
                                <!-- <i class="icon-note mx-2 bg-warning text-light p-1" @click.prevent="openClubHistoryModal(club_history.id)" style="cursor: pointer;"></i>
                                <i class="icon-trash bg-danger text-light p-1" @click.prevent="deleteClubHistory(club_history.id)" style="cursor: pointer;"></i> -->
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="filters.total > 0">
                        <tr style="border-top: 1px solid #cccccc;">
                            <th></th>
                            <th class="ps-3">Overall Score</th>
                            <th></th>
                            <th class="ps-3">{{ club_sum['total_match'] }}</th>
                            <th class="ps-3">{{ club_sum['minutes_played'] }}</th>
                            <th class="ps-3">{{ club_sum['total_goal'] }}</th>
                            <th class="ps-3">{{ club_sum['total_assist'] }}</th>
                            <th class="ps-3">{{ club_sum['red_cards'] }}</th>
                            <th class="ps-3">{{ club_sum['yellow_cards'] }}</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </TableCont>

                <template v-slot:header_slot>
                    <!-- <button class="btn btn-success btn-sm" type="button" @click.prevent="openClubHistoryModal(0)">+ Add Club History</button> -->
                    <Button2 :disabled="loading_edit[0]" cls="btn btn-success btn-sm" @clickFn="openClubHistoryModal(0)">+ Add Club History</Button2>
                </template>

                <template v-slot:footer_slot>
                    <Pagination
                        :filters="filters"
                        @set-page="(page_no) => setPage(page_no)"
                    />
                </template>

                <Loading :loading="loading_club" type="table" :max="5"></Loading>
                <LengthZero v-if="!loading_club && club_history_list.length == 0" cls="col-md-12 ms-4 py-3 mt-2"></LengthZero>
            </Card>
        </div>

    </div>


    <Modal id="add_club_history" title="Club History">
        <ClubHistoryDetailModal :club_history_id="club_history_id" :player_id='player_obj.id' @callback=" () => fetchClubHistory()"></ClubHistoryDetailModal>
    </Modal>
</template>
