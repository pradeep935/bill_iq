<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import { useForm} from 'vee-validate';
    
    const {club_history_id, player_id} = defineProps(['club_history_id','player_id']);
    const { handleSubmit, resetForm} = useForm();
    const emit = defineEmits(['callback']);

    watch(
        () => club_history_id,
        () => {
            fetchClubHistory();
        }
    )

    const club_history_obj = ref({});
    const loading = ref(false);
    const processing = ref(false);

    function fetchClubHistory(){
        if(club_history_id == 0){
            club_history_obj.value= {};
            resetForm();
            return;
        }
        loading.value = true;
        DBService.getData('/api/player-information/fetch-club-history/' + club_history_id) .then( (data) => {
            if(data.success){
                club_history_obj.value = data.club_history_obj;
            } else{
                bootbox.alert('Data Not Found');
            }
            loading.value = false;
        });
    }

    const submitClubHistory = handleSubmit((values, {resetForm})=> {
        processing.value = true;
        club_history_obj.value.player_id = player_id;
        DBService.postData('/api/player-information/save-club-history',club_history_obj.value) .then( (data) => {
            if(data.success){
                bootbox.alert(data.message);
                resetForm();
                $("#add_club_history").modal('hide');
                emit('callback');
            } else{
                bootbox.alert(data.message);
            }
            processing.value = false;
        });
    });

</script>

<template>
    <form @submit.prevent="submitClubHistory()">
        <div class="row">
            <FormInput label="Club Name" name="club_name" req="true" v-model="club_history_obj.club_name" cls="mb-2 col-md-12"/>
            <FormInput label="Season" placeholder=" Season Played (2025-26)" name="season" req="true" v-model="club_history_obj.season" cls="mb-2 col-md-6"/>
            <FormInput label="Competition" name="competition" req="true" v-model="club_history_obj.competition" cls="mb-2 col-md-6"/>
            <FormInput type="number" label="Total Match" name="total_match" req="true" v-model="club_history_obj.total_match" cls="mb-2 col-md-6"/>
            <FormInput type="number" placeholder="Duration Of Match Played" label="Minutes Played" name="minutes_played" req="true" v-model="club_history_obj.minutes_played" cls="mb-2 col-md-6"/>
            <FormInput type="number" label="Total Goal" name="total_goal" req="true" v-model="club_history_obj.total_goal" cls="mb-2 col-md-6"/>
            <FormInput type="number" label="Total Assist" name="total_assist" req="true" v-model="club_history_obj.total_assist" cls="mb-2 col-md-6"/>
            <FormInput type="number" placeholder="Total Yellow Cards Issued" label="Yellow Cards" name="yellow_cards" req="true" v-model="club_history_obj.yellow_cards" cls="mb-2 col-md-6"/>
            <FormInput type="number" placeholder="Total Red Cards Issued" label="Red Cards" name="red_cards" req="true" v-model="club_history_obj.red_cards" cls="mb-2 col-md-6"/>
            <div class="col-md-12">
                <label>Notes</label>
                <textarea class="form-control" placeholder="Other Relevent Information About Player" name="note" v-model="club_history_obj.note"></textarea>
            </div>
        </div>
        <div class="modal-footer mt-2">
            <FormButton cls="btn-success" :processing="processing">Submit</FormButton>
        </div>
    </form>
</template>