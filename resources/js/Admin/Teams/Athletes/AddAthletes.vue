<script setup>
    import { ref, watch, onMounted } from 'vue';
    import DBService from '@/Service/Utils/DBService';
    import SectionToggle from '@/Components/NewComponents/SectionToggle.vue';
    import { useForm } from 'vee-validate';
    const { handleSubmit, resetForm } = useForm();

    const { team_id } = defineProps(['team_id']);
    const emit = defineEmits(['close']);
    const loading = ref(false);
    const processing = ref(false);
    const players = ref([]);
    const teams = ref([]);

    onMounted(() => {
        getAthParams();
    });

    const athletes = ref([
        { player_id: null, team_id: null, email: '', password: '' },
        { player_id: null, team_id: null, email: '', password: '' },
        { player_id: null, team_id: null, email: '', password: '' },
        { player_id: null, team_id: null, email: '', password: '' },
        { player_id: null, team_id: null, email: '', password: '' },
    ]);

    function getAthParams() {
        DBService.getData('/api/teams/get-athletes-params/'+team_id).then((data)=>{
            if (data.success) {
                players.value = data.players;
                teams.value = data.teams;
            }
        });
    };

    const onSubmit = handleSubmit((values) => {
        processing.value = true;
        DBService.postData('/api/teams/store-team-athletes/'+team_id,
            { athletes: athletes.value}).then( (data)=>{
            if(data.success){
                resetForm();
                emit('close');
            } else {

            }
            bootbox.alert(data.message);
            processing.value = false;
        });
    });

</script>

<template>
    <div class="offcanvas-body modal-right__body" v-if="!loading">
        <Loading :loading="loading" type="table" />
        <form @submit.prevent="onSubmit()" id="add_athletes_modal" >
            <SectionToggle icon_cls="bi bi-person"  v-for="(athlete, index) in athletes" :key="index" :title="`Athlete ${index + 1}`" >
                <div class="row">
                    <FormSelect :options="players" label="Player" :name="`player_id_${index}`" v-model="athlete.player_id" cls="col-md-6" />

                    <FormSelect :options="teams" label="Team" :name="`team_id_${index}`" v-model="athlete.team_id" cls="col-md-6" /> 

                    <!-- <FormInput label="Email" :name="`email_${index}`" v-model="athlete.email" cls="col-md-6" /> -->

                    <!-- <FormInput label="Password" :name="`password_${index}`" v-model="athlete.password" cls="col-md-6" /> -->
                </div>
            </SectionToggle>
        </form>
    </div>
    <div class="oc-footer">
        <Button2 cls="text-align-left" @click="emit('close')" >Cancel</Button2>

        <FormButton cls="btn-primary" form="add_athletes_modal" :processing="processing" >{{ processing ? 'Saving...' : 'Save'}}</FormButton>
    </div>
</template>
