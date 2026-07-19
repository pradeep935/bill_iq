<script setup>
    import { ref, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import { useForm } from 'vee-validate';

    const { guardian_id, player_id } = defineProps(['guardian_id', 'player_id']);
    const { handleSubmit, resetForm } = useForm();
    const emit = defineEmits(['callback']);

    const relation_list = ref([
        { label: 'Father', value: 1 },
        { label: 'Mother', value: 2 },
        { label: 'Guardian', value: 3 },
    ]);

    const guardian_obj = ref({
        id: 0,
        relation_id: 3,
        name: '',
        mobile: '',
        email: '',
    });
    const loading = ref(false);
    const processing = ref(false);

    watch(
        () => guardian_id,
        () => {
            fetchGuardian();
        }
    );

    function fetchGuardian(){
        if(guardian_id == 0){
            resetForm();
            guardian_obj.value = {
                id: 0,
                relation_id: 3,
                name: '',
                mobile: '',
                email: '',
            };
            return;
        }

        loading.value = true;
        DBService.getData('/api/player-information/fetch-guardian/' + guardian_id).then((data) => {
            if(data.success){
                guardian_obj.value = data.guardian;
            } else{
                bootbox.alert(data.message);
            }
            loading.value = false;
        });
    }

    const submitGuardian = handleSubmit((values, { resetForm }) => {
        processing.value = true;
        guardian_obj.value.player_id = player_id;

        DBService.postData('/api/player-information/save-guardian', guardian_obj.value).then((data) => {
            if(data.success){
                bootbox.alert(data.message);
                resetForm();
                $("#player_guardian_modal").modal('hide');
                emit('callback');
            } else{
                bootbox.alert(data.message);
            }
            processing.value = false;
        });
    });
</script>

<template>
    <form @submit.prevent="submitGuardian()">
        <Loading :loading="loading"></Loading>
        <div class="row">
            <FormSelect
                label="Relation"
                name="relation_id"
                :options="relation_list"
                v-model="guardian_obj.relation_id"
                req="true"
                cls="mb-3 col-md-12"
            />
            <FormInput label="Name" name="name" v-model="guardian_obj.name" req="true" cls="mb-3 col-md-12" />
            <FormInput label="Mobile" name="mobile" v-model="guardian_obj.mobile" validate="mobile" cls="mb-3 col-md-6" />
            <FormInput label="Email" name="email" type="email" v-model="guardian_obj.email" validate="email" cls="mb-3 col-md-6" />
        </div>
        <div class="modal-footer">
            <FormButton cls="btn-success" :processing="processing">Submit</FormButton>
        </div>
    </form>
</template>
