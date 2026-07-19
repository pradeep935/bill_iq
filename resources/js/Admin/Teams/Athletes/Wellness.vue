<script setup>
    import { ref, watch, onMounted, computed } from 'vue';
    import DBService from '@/Service/Utils/DBService';
    import { useForm } from 'vee-validate';
    const { handleSubmit, resetForm } = useForm();
    import Card from '@/Components/NewComponents/Card.vue';

    const { team_id, athlete_id } = defineProps(['team_id', 'athlete_id']);

    onMounted(()=>{
        getWellness();
    })

    watch(() => athlete_id, () => {
        getWellness();
    });

    const loading = ref(false);
    const processing = ref(false);
    const searchName = ref('');
    const wellness = ref([]);

    const formData = ref({
        id: 0,
        soreness: 3,
        tiredness: 3,
        stress_level: 3,
        weight_before_training : 3,
        comments: '',
    });

    function getWellness(){
        loading.value = true;
        DBService.postData('/api/teams/get-Wellness/'+ team_id + '/' + athlete_id).then((data)=>{
            if (data.success) {
                wellness.value = data.wellness
            }
            loading.value = false;
        });
    }

    function addQuestionnaire(){
        formData.value = {
            id: 0,
            soreness: 3,
            tiredness: 3,
            stress_level: 3,
            weight_before_training : 3,
            comments: '',
        };
        resetForm();
        $('#add_questionnaire').modal('show');
    }

    const formItems = ref([
        { key: 'soreness', label: 'Soreness' },
        { key: 'tiredness', label: 'Tired' },
        { key: 'stress_level', label: 'Stress' },
        { key: 'weight_before_training', label: 'Weight before training' }
    ])

    const postWeight = ref([
        {value: 10, label: 10},
        {value: 9, label: 9},
        {value: 8, label: 8},
        {value: 7, label: 7},
        {value: 6, label: 6},
        {value: 5, label: 5},
        {value: 4, label: 4},
        {value: 3, label: 3},
        {value: 2, label: 2},
        {value: 1, label: 1},
    ]);


    const onSubmit = handleSubmit((values) => {
        processing.value = true;
        formData.value.player_id = athlete_id;
        DBService.postData('/api/teams/store-team-athletes-wellness/'+team_id,formData.value).then( (data)=>{
            if(data.success){
                formData.value = {
                    id: 0,
                    soreness: 3,
                    tiredness: 3,
                    stress_level: 3,
                    weight_before_training : 3,
                    comments: '',
                };
                resetForm();
                $('#add_questionnaire').modal('hide');
                getWellness();
            }
            bootbox.alert(data.message);
            processing.value = false;
        });
    });


    function getStatusClass(val){
        if (val <= 2) return 'badge dark-green';
        if (val <= 4) return 'badge green';
        if (val <= 8) return 'badge yellow';
        return 'badge red';
    };

    function editWelness(itm){
        Object.assign(formData.value, itm);
        $('#add_questionnaire').modal('show');
    }


</script>

<template>
    <Loading :loading="loading" type="table" />
    <div v-if="!loading">
        <div class="col-md-12">
            <Card title="Wellness Surveys"  header_class="px-3" >
                <template v-slot:header_slot>
                    <button class="btn btn-ghost-primary btn-sm" @click="addQuestionnaire()" type="button" >
                        <i class="bi bi-plus"></i> Add Questionnaire Submission
                    </button> 
                </template>
                <div v-if="wellness.length > 0" class="scroll-container" >
                    <TableCont>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Soreness</th>
                                <th>Fatigue</th>
                                <th>Stress</th>
                                <th>Weight</th>
                                <th>Comments</th>
                                <th class="text-end">#</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr v-for="(item,index) in wellness" :key="index">
                                <td>
                                    {{ item.display_date }}
                                </td>

                                <td>
                                    <span :class="getStatusClass(item.soreness)">
                                        {{ item.soreness }}/10
                                    </span>
                                </td>

                                <td>
                                    <span :class="getStatusClass(item.tiredness)">
                                        {{ item.tiredness }}/10
                                    </span>
                                </td>

                                <td>
                                    <span :class="getStatusClass(item.stress_level)">
                                        {{ item.stress_level }}/10
                                    </span>
                                </td>

                                <td>
                                    {{ item.weight_before_training || '-' }} kg
                                </td>

                                <td>
                                    {{ item.comments || '-' }}
                                </td>
                                <td class="text-end">
                                    <Button2 cls="btn-secondary" @click="editWelness(item)">
                                        <i class="bi bi-pencil"></i>
                                    </Button2>
                                </td>
                            </tr>
                        </tbody>
                    </TableCont>
                </div>
                <LengthZero v-else/>
            </Card>

            <Modal id="add_questionnaire" title="Add Questionnaire Submission" >
                <form @submit.prevent="onSubmit()">
                    <div class="row g-3">
                        <div class="col-md-12" v-for="(item, index) in formItems" :key="index">
                            <label class="form-label">{{ item.label }} ({{formData[item.key]}})</label>
                            <input type="range" min="1" max="10" v-model="formData[item.key]" class="form-range" />
                        </div>

                        <FormText label="Comments" v-model="formData.comments" name="formData.comments" cls="col-md-12" placeholder="Comments regarding answering this Questionnaire." />
                    </div>

                    <div class="modal-footer mt-3">
                        <FormButton cls="btn btn-primary" :processing="processing" >{{ processing ? 'Saving Changes...' : 'Save Changes' }}</FormButton>
                    </div>
                </form>
            </Modal>
        </div>
    </div>
</template>
<style scoped>
    .badge {
        padding: 6px 10px;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 600;
    }

    .green { background: #e8f5e9; color: #2e7d32; }
    .red { background: #fdecea; color: #c62828; }
    .yellow { background: #fff8e1; color: #f9a825; }
    .dark-green { background: #e0f2f1; color: #00695c; }

    .comment {
        font-size: 13px;
        color: #555;
    }

    .scroll-container{
        max-height:650px;
        overflow-y:auto;
        padding-right:6px;
    }

</style>