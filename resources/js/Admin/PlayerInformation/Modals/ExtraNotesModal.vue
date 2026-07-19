<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import { useForm} from 'vee-validate';

    const {player_id, note_obj} = defineProps(['player_id','note_obj']);
    const { handleSubmit, resetForm} = useForm();
    const emit = defineEmits(['callback']);

    watch(
        () => note_obj,
        () => noteObjChange()
    )

    onMounted(() => {
        noteObjChange();
    });

    const extra_note_obj = ref({});
    const loading = ref(false);
    const processing = ref(false);

    const submitExtraNote = handleSubmit((values, {resetForm})=> {
        processing.value = true;
        extra_note_obj.value.player_id = player_id;

        DBService.postData('/api/player-information/save-extra-note',extra_note_obj.value) .then( (data) => {
            if(data.success){
                bootbox.alert(data.message);
                resetForm();
                $("#add_extra_notes").modal('hide');
                emit('callback');
            } else{
                bootbox.alert(data.message);
            }
            processing.value = false;
        });
    });

    function noteObjChange(){
        if(note_obj == 0){
            resetForm();
            extra_note_obj.value.note = '';
            extra_note_obj.value.id = 0;
            extra_note_obj.value.note_date = new Date();
            return;
        }

        extra_note_obj.value.note = note_obj.note;
        extra_note_obj.value.id = note_obj.id;
        extra_note_obj.value.note_date = note_obj.note_date;
    }
</script>

<template>
    <form @submit.prevent="submitExtraNote()">
        <div class="row">
            <FormInput type="date" label="Note Date" name="note_date" req="true" v-model="extra_note_obj.note_date" cls="col-md-12" v-if="extra_note_obj.id != -1"/>
            <FormText cls="col-md-12" v-model="extra_note_obj.note" name="note" req="true" label="Note"></FormText>
        </div>
        <div class="modal-footer">
            <FormButton cls="btn-success" type="submit" :processing="processing">Submit</FormButton>
        </div>
    </form>
</template>