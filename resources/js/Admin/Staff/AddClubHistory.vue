<script setup>
    import { ref, watch, onMounted } from 'vue';
    import DBService from '@/Service/Utils/DBService';
    import { useForm } from 'vee-validate';
    const { handleSubmit, resetForm } = useForm();
    import FormInputBox from '@/Components/NewComponents/FormInputBox.vue';
    import SectionToggle from '@/Components/NewComponents/SectionToggle.vue';

    const props = defineProps(['staff_id','club_history_assign']);

    const emit = defineEmits(['close']);
    const loading = ref(false);
    const processing = ref(false);
    const formData = ref({});

    watch(
        () => props.club_history_assign,
        (newVal) => {
            if (newVal == null) {
                formData.value = { }
                resetForm();
            } else{
                Object.assign(formData.value, props.club_history_assign);
            }
        },
    );

    const onSubmit = handleSubmit((values) => {
        processing.value = true;
        formData.value.staff_id = props.staff_id;
        DBService.postData('/api/staff/club-history/store-data',formData.value).then( (data)=>{
            if(data.success){
                formData.value = {}
                resetForm();
                emit('close');
            }
            bootbox.alert(data.message);
            processing.value = false;
        });
    });

</script>

<template>
    <Loading :loading="loading" />
    <div v-if="!loading">
        <form @submit.prevent="onSubmit()" >
            <div class="row">
                <FormInput label="Season" placeholder="Season" name="Season" v-model="formData.season" cls="col-md-6" req="true" />

                <FormInput label="Club" placeholder="Name of the Club" name="club_name" v-model="formData.club_name" cls="col-md-6" req="true" />

                <FormInput label="Competition" placeholder="Competition" name="competition" v-model="formData.competition" cls="col-md-6" req="true" />

                <FormInput type="text" label="Image URL" placeholder="URL of a logo of the club, if it exists" name="image_url" v-model="formData.image_url" cls="col-md-6" />

                <FormInput label="Matches" type="number" placeholder="Number of matches during the season" name="number_of_matches" v-model="formData.number_of_matches" cls="col-md-6" req="true" />
                
                <FormInput type="number" label="Points Per Game" placeholder="Points per game during the season" name="points_per_game" v-model="formData.points_per_game" cls="col-md-6" req="true" />

                <FormText label="Notes" placeholder="Other Information" name="other_information" v-model="formData.other_information" cls="col-md-12"  /> 
            </div>
            <div class="card-footer text-end">
                <Button2 cls="btn-outline-secondary" @click="emit('close')" >CANCEL</Button2>
                <FormButton cls="btn-primary mx-1" :disabled="processing" :processing="processing" >Save</FormButton>
            </div>  
        </form>
    </div>
</template>