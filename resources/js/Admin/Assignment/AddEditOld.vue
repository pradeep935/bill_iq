<script setup>
    import { ref, watch, onMounted } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'

    const props = defineProps({
        id: {
            type: Number,
            default: 0,
        },
        display_user: {
            type: Boolean,
            default: false,
        },
        callback_func: {
            type: Function,
            default: null,
        }
    });

    onMounted(
        () => {
            fetchDropDowns();
        }
    )

    const s3_url_link = import.meta.env.VITE_S3_URL;
    const loading = ref(false);
    const submitting = ref(false);
    const approving = ref(false);
    const users = ref([]);
    const obj = ref({});
    const status_list = [{label: 'Pending', value: 1}, {label: 'Completed', value: 2}, {label: 'Need Revision', value: -1}];

    watch(
        () => props.id,
        (new_id) => {
            if(new_id){
                approving.value = false;
                fetchAssignmentDetails(new_id);
            } else{
                approving.value = false;
                obj.value = { status: 0 };
            }
        },
        { immediate: true }
    );

    watch(
        () => obj.value.status,
        () => {
            if(approving.value){
                if(!obj.value.status){
                    obj.value.status = 1;
                }
            }
        }
    )

    function fetchAssignmentDetails(id){
        loading.value = true;
        DBService.getData('/api/assignments/fetch-assignment-details/' + id).then((data) => {
            if(data.success){
                obj.value = data.assignment || {};
                if(data.assignment.status == 1){
                    approving.value = true;
                }
            }
            loading.value = false;
        });
    }

    function fetchDropDowns(){
        loading.value = true;
        DBService.getData('/api/assignments/fetch-dropdowns').then((data) => {
            if(data.success){
                users.value = data.users;
            }
            loading.value = false;
        });
    }

    function submitAssignment(){
        // submitting.value = true;
        // DBService.postData('/api/assignments/assign-task', {...obj.value, status: 1}).then((data) => {
        //     if(data.success){
        //         $('#add-edit-assignment').modal('hide');
        //         if(props.callback_func){
        //         }
        //     } else{
        //         bootbox.alert(data.message);
        //     }
        //     submitting.value = false;
        // });

        if(approving.value){
            if(obj.value.status == 2 || obj.value.status == -1){
                props.callback_func(obj.value);
            } else{
                bootbox.alert('Update assignment status');
            }
        } else{
            props.callback_func(obj.value);
        }

    }
</script>

<template>
    <Loading :loading="loading" v-if="loading"></Loading>
    <div class="row g-2" v-else>
        <SelectField v-if="display_user" :disabled="submitting" v-model="obj.user_id" :options="users" label="User" opt_name="name" opt_id="id" cls="col-md-12"></SelectField>
        <InputText :disabled="submitting" v-model="obj.ques_text" label="Assignment Text" placeholder="Details about the assignments" cls="col-md-12"></InputText>
        <InputField :disabled="submitting" type="date" v-model="obj.last_date" label="Last Date" cls="col-md-6"></InputField>
        <FileUpload v-model="obj.ques_file" label="File" cls="col-md-6"></FileUpload>

        <InputText v-if="obj.status != 0" :disabled="true" v-model="obj.ans_text" label="Response" cls="col-md-12"></InputText>
        <InputField v-if="obj.status != 0" :disabled="true" type="date" v-model="obj.submit_date" label="Submission Date" cls="col-md-6"></InputField>
        <div class="col-md-6" v-if="obj.status != 0">
            <label>Response File</label>
            <br>
            <a v-if="obj.ans_file" :href="s3_url_link + obj.ans_file" target="_blank">View File</a>
            <span v-else>-</span>
        </div>
        <SelectField v-if="obj.status != 0" :req="true" :options="status_list" v-model="obj.status" label="Status" cls="col-md-12 mt-2"></SelectField>

        <div class="col-md-12 text-end mt-3">
            <Button2 :processing="submitting" cls="btn btn-success" @click="submitAssignment()">Submit</Button2>
        </div>
    </div>
</template>