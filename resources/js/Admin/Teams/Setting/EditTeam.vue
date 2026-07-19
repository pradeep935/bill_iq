<script setup>
    import { ref, onMounted, computed } from 'vue';
    import DBService from '@/Service/Utils/DBService';
    import { useForm } from 'vee-validate';
    import FormMultiSelectNew from '@/Components/NewComponents/FormMultiSelectNew.vue';

    const { handleSubmit, resetForm } = useForm();

    const { team_id } = defineProps(['team_id']);

    const loading = ref(false);
    const processing = ref(false);
    const formData = ref({
        team_name: null,
        team_logo: null,
        user_ids: [],
    });
    const added_by = ref(null);
    const s3_url_link = import.meta.env.VITE_S3_URL;
    const user_list = ref([]);

    onMounted(() => {
        teamEdit();
        fetchParams();
    });


    function fetchParams() {
        loading.value = true;
        DBService.getData("/api/teams/get-params").then((data)=>{
            if (data.success) {
                user_list.value = data.user_list;
            }
            loading.value = false;
        });
    };

    const filterd_list = computed(() => {
        return user_list.value.filter((userList) => userList.value !== added_by.value);
    });

    function teamEdit(){
        if (team_id > 0) {
            loading.value = true;
            DBService.getData("/api/teams/edit-team/"+ team_id).then((data)=>{
                loading.value = false;
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

    const onSubmit = handleSubmit(() => {
        processing.value = true;
        DBService.postData('/api/teams/store-data', formData.value).then((data)=>{
            if(data.success){
                formData.value = {
                    team_name: null,
                    team_logo: null,
                    user_ids: [],
                };
                resetForm();
                window.history.back();
            }
            bootbox.alert(data.message);
            processing.value = false;
        });
    });

</script>
<template>
    <Loading :loading="loading" />
    <div v-if="!loading" class="modern-card" >
        <div class="form-header">
            <h5 class="text-succes">Edit Team</h5>
            <p class="subtitle">Update team information and branding</p>
        </div>
        <div class="form-body">
            <div class="logo-section">
                <div class="logo-box">
                    <img v-if="formData.team_logo" :src="s3_url_link + formData.team_logo" />
                    <span v-else>No Logo</span>
                </div>
            </div>
            <form @submit.prevent="onSubmit">
                <div class="form-grid">
                    <FormInput label="Team Name" req="true" placeholder="Enter team name" name="team_name" v-model="formData.team_name" />

                    <FormMultiSelectNew :options="filterd_list" name="user_ids" v-model="formData.user_ids" label="Users with access " cls="col-md-12" cls2="col-md-12" field_name="users" />


                    <FileUpload label="Upload Team Logo" v-model="formData.team_logo" cls="mt-3 mb-2 col-md-12" />
                </div>
                <div class="form-footer">
                    <FormButton  cls="btn btn-primary " :processing="processing"> {{ processing ? 'Saving Changes...' : 'Save Changes' }}</FormButton>
                </div>
            </form>
        </div>
    </div>
</template>
<style scoped>
    .modern-card {
        /*background: #fff;*/
        /*border-radius: 16px;*/
        padding: 15px;
        /*box-shadow: 0 8px 24px rgba(0,0,0,0.06);*/
    }

    /* HEADER */
    .form-header {
        margin-bottom: 20px;
    }

    .subtitle {
        font-size: 12px;
        color: #888;
    }

    /* BODY */
    .form-body {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    /* LOGO */
    .logo-section {
        display: flex;
        justify-content: center;
    }

    .logo-box {
        width: 140px;
        height: 140px;
        border-radius: 16px;
        background: #f5f7f9;
        border: 1px dashed #ddd;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        font-size: 13px;
        color: #999;
    }

    .logo-box img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    /* FORM GRID */
    .form-grid {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    /* FOOTER */
    .form-footer {
        display: flex;
        justify-content: flex-end;
        margin-top: 18px;
    }

</style>
