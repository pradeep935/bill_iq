<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import { useForm} from 'vee-validate';
    import FormInputBox from '@/Components/NewComponents/FormInputBox.vue';

    const {rec_player_obj, rec_pos_id, dropdown_obj} = defineProps(['rec_player_obj','rec_pos_id','dropdown_obj']);
    const emit = defineEmits(['callback']);
    const { handleSubmit, resetForm} = useForm();

    const loading = ref(false);
    const modal_obj = ref({ });

    watch(
        () => rec_player_obj.id,
        () => {
            if (rec_player_obj.id == 0) {
                resetForm()
            } else{
                modal_obj.value = JSON.parse(JSON.stringify(rec_player_obj));     
            }
        }
    )

    const addNewPlayersInList = handleSubmit((values, {resetForm})=> {
        loading.value = true;
        modal_obj.value.rec_pos_id = rec_pos_id;
        DBService.postData('/api/recruitment-tracker/recruitment-position/players/save-recruited-players-details', modal_obj.value) .then( (data) => {
            if(data.success){
                resetForm();
                emit('callback');
            }
            bootbox.alert(data.message);
            loading.value = false;
        });
    });
</script>

<template>
    <div class="offcanvas-body modal-right__body">
        <form  id="add_new_player_in_list" @submit.prevent="addNewPlayersInList()">
            <div class="row g-3">
                <FormSelect req="true" name="players" v-model="modal_obj.player_id" :options="dropdown_obj.player_list" opt_id="id" opt_name="name" label="Players" cls="col-md-12" />

                <FormSelect req="true" name="status_id" v-model="modal_obj.status_id" :options="dropdown_obj.status_list" opt_id="id" opt_name="status" label="Status" cls="col-md-12" />

                <FormInputBox req="true" type="number" name="annual_salary" v-model="modal_obj.annual_salary" label="Requested Annual Salary" display_position="left" box_entry="&#8377;" cls="col-md-6" />

                <FormInputBox req="true" type="number" name="transfer_fee" v-model="modal_obj.transfer_fee" label="Asked Transfer Fee" display_position="left" box_entry="&#8377;" cls="col-md-6" /> 

                <FormText name="note" v-model="modal_obj.note" label="Note" placeholder="Add Description" cls="col-md-12" />
            </div>
        </form>
    </div>
    <div class="oc-footer">
        <button type="submit" form="add_new_player_in_list" class="btn btn-dark">Submit</button>
    </div>
</template>