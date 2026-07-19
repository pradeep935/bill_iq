<script setup>
    import { ref, watch, onMounted } from 'vue';
    import DBService from '@/Service/Utils/DBService';
    import AddClubHistory from '@/Admin/Staff/AddClubHistory.vue';
    const { staff_id, staffUser , edit_access=false} = defineProps(['staff_id', 'staffUser', 'edit_access']);

    const historyLoading = ref(false);
    const title = ref("");
    const club_history_assign = ref({});
    const club_history = ref([]);
    const base_url = import.meta.env.VITE_APP_URL;

    onMounted(() => {
        getClubHistory();
    });

    function getClubHistory(){
        historyLoading.value = true;
        DBService.postData("/api/staff/club-history/"+staff_id).then((data)=>{
            if (data.success) {
                club_history.value = data.club_history;
            }
            historyLoading.value = false;
        });
    };

    function addClubHistory(){
        club_history_assign.value = null;
        title.value = "Add Club History";
        $("#add-club-details").modal("show");
    }

    function closePopUp(){
        $("#add-club-details").modal("hide");
        club_history_assign.value = null;
        getClubHistory();
    }

    function editClubHistory(history){
        club_history_assign.value = { ...history };
        title.value = "Edit Club History";
        $("#add-club-details").modal("show");
    }

    function deleteClubHistory(his_id){
        bootbox.confirm("Are you sure?", function(result){
            if (result) {
                historyLoading.value = true;
                DBService.getData("/api/staff/club-history/delete/"+his_id + '/' + staffUser.user_id).then((data)=>{
                    if (data.success) {
                        getClubHistory();
                    }
                    bootbox.alert(data.message);
                    historyLoading.value = false;
                });
            }
        });
    };

</script>
<template>
    <div class="row">
        <div class="col-md-12 d-flex mb-3 justify-content-between">
            <b class="fs-5">Club History</b>
            <Button2 v-if="edit_access" cls="btn-primary btn-sm" @click.prevent="addClubHistory()" ><i class="bi bi-plus"></i> Add Club History</Button2>
        </div>
    </div>
    <div class="pb-3" v-if="club_history.length > 0" >
        <TableCont>
            <thead class="text-uppercase">
                <tr>
                    <th>SN</th>
                    <th class="player-prof-details">Club name</th>
                    <th>Competition</th>
                    <th>season</th>
                    <th>matches</th>
                    <th>points per game</th>
                    <th v-if="edit_access" ></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(history, index) in club_history" :key="index" >
                    <td>{{index+1}}</td>
                    <td>
                        <div>
                            {{history.club_name ? history.club_name : '-'}}
                        </div>
                    </td>
                    <td>
                        {{history.competition ? history.competition : '-'}}
                    </td>
                    <td>
                        {{history.season ? history.season : '-' }}
                    </td>
                    <td>
                        {{history.number_of_matches ? history.number_of_matches : '-'}}
                    </td>
                    <td>
                        {{history.points_per_game ? history.points_per_game : '-'}}
                    </td>
                    <td class="text-end" v-if="edit_access">
                        <Button2 cls="btn-outline-secondary btn-sm" @click.prevent="editClubHistory(history)" ><i class="bi bi-pencil" ></i></Button2>

                        <Button2 cls="btn-outline-danger btn-sm  mx-1" @click.prevent="deleteClubHistory(history.id)" ><i class="bi bi-trash"></i></Button2>
                    </td>
                </tr>
            </tbody>
        </TableCont>
    </div>
    <div class="alert text-center  mt-4" v-if="club_history.length == 0" >
        <div class="text-secondary mt-3">
            <h4>No Club Histories</h4>
            <p class="mt-1" >No Club Histories have been added.</p>
        </div>
        <button v-if="edit_access" class="btn-ghost-primary btn mt-2" type="button" @click.prevent="addClubHistory()" > 
            <i class="bi bi-plus"></i>
            Add Club History
        </button>
    </div>
     <Modal id="add-club-details" :title="title">
        <AddClubHistory :staff_id="staffUser.user_id" :club_history_assign="club_history_assign" @close="closePopUp" />
    </Modal>
</template>