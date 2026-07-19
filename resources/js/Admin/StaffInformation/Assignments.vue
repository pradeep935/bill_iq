<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import Card from '@/Components/NewComponents/Card.vue';
    import AddEditAssignment from '@/Admin/Assignment/AddEdit.vue';

    const {staff_id} = defineProps(['staff_id']);
    const base_url = import.meta.env.VITE_APP_URL;
    const s3_url_link = import.meta.env.VITE_S3_URL

    onMounted(
        () => {
            fetchAssignments();
        }
    )

    const loading = ref(false);
    const deleting = ref({});
    const submitting = ref(false);
    const assignments = ref([]);
    const obj = ref({});
    const modal_title = ref('');

    const status_list = [{label: 'Pending', value: 1}, {label: 'Completed', value: 2}, {label: 'Need Revision', value: -1}];

    function fetchAssignments(){
        loading.value = true;
        DBService.postData('/api/assignments/fetch-assigned-assignments/' + staff_id, {max_per_page: 5, page_no: 1}).then((data) => {
            if(data.success){
                assignments.value = data.assignments;
            }
            loading.value = false;
        });
    }

    function addEditAssignment(temp_obj){
        obj.value = {};
        modal_title.value = temp_obj ? 'Edit Assignment' : 'Add Assignment';

        if(temp_obj){
            obj.value = temp_obj;
        }
        $('#add-edit-assignment').modal('show');
    }

    function submitAssignment(temp_obj){
        submitting.value = true;
        loading.value = true;
        obj.value = temp_obj;
        obj.value.user_id = staff_id;
        DBService.postData('/api/assignments/assign-task', obj.value).then((data) => {
            if(data.success){
                $('#add-edit-assignment').modal('hide');
                fetchAssignments();
            }
            submitting.value = false;
            loading.value = false;
        });
    }

    function deleteAssignment(assignment_id){
        deleting.value[assignment_id] = true;
        bootbox.confirm('Are you sure you wants to delete this assignment?', (check) => {
            if(check){
                loading.value = true;
                DBService.getData('/api/assignments/delete/' + assignment_id).then((data) => {
                    if(data.success){
                        fetchAssignments();
                    }
                    bootbox.alert(data.message);
                    loading.value = false;
                });
            } else{
                deleting.value[assignment_id] = false;
            }
        });
    }
</script>

<template>
    <Card title="Assignments" header_class="px-3">
        <template v-slot:header_slot>
            <Button2 :disabled="loading" cls="btn btn-primary btn-sm" @clickFn="addEditAssignment(0)">
                <i class="bi bi-plus"></i> Add Assignment</Button2>
        </template>
        <Loading :loading="loading" v-if="loading"></Loading>
        <div v-else>
            <TableCont v-if="assignments.length > 0">
                <thead>
                    <tr>
                        <th>SN</th>
                        <th>Requirement</th>
                        <th>Last Date</th>
                        <th>Requirement File</th>
                        <th>Status</th>
                        <th class="text-end">#</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(asg, index) in assignments">
                        <td>{{ index + 1 }}</td>
                        <td>{{ asg.ques_text }}</td>
                        <td>{{ asg.display_last_date }}</td>
                        <td>
                            <a v-if="asg.ques_file" :href="s3_url_link + asg.ques_file" target="_blank">View File</a>
                            <span v-else>-</span>
                        </td>
                        <td>{{ asg.status == 0 ? 'Pending' : (asg.status == 1 ? 'Waiting for approval' : (asg.status == 2 ? 'Completed' : 'Need Revision')) }}</td>
                        <td class="text-end">
                            <Button2 :processing="submitting" cls="btn btn-primary btn-sm" @click="addEditAssignment(asg)"><i class="bi bi-pencil"></i></Button2>
                            <Button2 :processing="deleting && deleting[asg.id]" cls="btn btn-danger btn-sm ms-1" @click="deleteAssignment(asg.id)"><i class="bi bi-trash"></i></Button2>
                        </td>
                    </tr>
                </tbody>
            </TableCont>
            <LengthZero v-else cls="pt-3 pb-2"></LengthZero>
        </div>
    </Card>

    <Modal id="add-edit-assignment" :title="modal_title" size="modal-lg">
        <!-- <div class="row g-2">
            <InputText :disabled="submitting" v-model="obj.ques_text" label="Assignment Text" placeholder="Details about the assignments" cls="col-md-12"></InputText>
            <InputField :disabled="submitting" type="date" v-model="obj.last_date" label="Last Date" cls="col-md-6"></InputField>
            <FileUpload v-model="obj.ques_file" label="File" cls="col-md-6"></FileUpload>

            <InputText v-if="obj.status != 0" :disabled="true" v-model="obj.ans_text" label="Response" cls="col-md-12"></InputText>
            <InputField v-if="obj.status != 0" :disabled="true" type="date" v-model="obj.submit_date" label="Submission Date" cls="col-md-6"></InputField>
            <div class="col-md-6" v-if="obj.status != 0">
                <label>Response File</label>
                <br>
                <a v-if="obj.ans_file" :href="s3_url_link + obj.ans_file">View File</a>
                <span v-else>-</span>
            </div>
            <SelectField v-if="obj.status != 0" :options="status_list" v-model="obj.status" label="Status" cls="col-md-12 mt-2"></SelectField>

            <div class="col-md-12 text-end mt-3">
                <Button2 :processing="submitting" cls="btn btn-success" @click="submitAssignment()">Submit</Button2>
            </div>
        </div> -->
        <AddEditAssignment :id="obj.id" :callback_func="(temp_obj) => submitAssignment(temp_obj)"/>
    </Modal>
</template>