<script setup>
    import { ref, watch, onMounted, computed } from 'vue';
    import DBService from '@/Service/Utils/DBService';
    import FormMultiSelectNew from '@/Components/NewComponents/FormMultiSelectNew.vue';
    import { useForm } from 'vee-validate';
    const { handleSubmit, resetForm } = useForm();

    const { team_id } = defineProps(['team_id']);
    const emit = defineEmits(['close']);
    const loading = ref(false);
    const processing = ref(false);
    const user_list = ref([]);
    const added_by = ref(null);
    const formData = ref({
        team_name: null,
        team_logo: null,
        user_ids: [],
    });

    onMounted(()=>{
        fetchParams();
    });

    watch(()=>team_id,
        ()=>{
           teamEdit(); 
        })

    function fetchParams() {
        loading.value = true;
        DBService.getData("/api/teams/get-params").then((data)=>{
            if (data.success) {
                user_list.value = data.user_list;
            }
            loading.value = false;
        });
    };

    function teamEdit(){
        if (team_id > 0) {
            loading.value = true;
            DBService.getData("/api/teams/edit-team/"+ team_id).then((data)=>{
                if (data.success) {
                    added_by.value = data.team.added_by;
                    formData.value.team_name = data.team.team_name;
                    formData.value.id = data.team.id;
                    formData.value.team_logo = data.team.team_logo;
                    formData.value.user_ids = data.team.user_ids;
                }
                loading.value = false;
            });
        } else{
            formData.value = {
                team_name: null,
                team_logo: null,
                user_ids: [],
            };
            resetForm();
        }
    };

    const filterd_list = computed(() => {
        return user_list.value.filter((userList) => userList.value !== added_by.value);
    });

    const onSubmit = handleSubmit((values) => {
        processing.value = true;
        if (team_id !== 0) {
            formData.value.id = team_id;
        }
        DBService.postData('/api/teams/store-data',formData.value).then( (data)=>{
            if(data.success){
                formData.value = {
                    team_name: null,
                    team_logo: null,
                    user_ids: [],
                };
                resetForm();
                emit('close');
            } 
            bootbox.alert(data.message);
            processing.value = false;
        });
    });

</script>

<template>
    <div class="offcanvas-body modal-right__body">
        <Loading :loading="loading" />
        <form id="teamAddUpdate" @submit.prevent="onSubmit()" >
            <div class="row g-3">
                <FormInput label="Team Name" req="true"  placeholder="Name of the Team" name="team_name" v-model="formData.team_name" cls="col-md-12" />

                <FormMultiSelectNew :options="filterd_list" name="user_ids" v-model="formData.user_ids" label="Users With Access " cls="col-md-12" cls2="col-md-12" field_name="users" />

                <FileUpload label="Team Logo" temp="0" v-model="formData.team_logo" cls="col-md-12" />
            </div>
        </form>
    </div>
    <div class="oc-footer">
        <FormButton cls="btn-primary" form="teamAddUpdate" :processing="processing" >{{ processing ? 'Saving...' : 'Save'}}</FormButton>
    </div>
</template>