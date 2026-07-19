<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import ExtraNotesModal from '@/Admin/PlayerInformation/Modals/ExtraNotesModal.vue';
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import Card from '@/Components/NewComponents/Card.vue';
    import TextDesc from '@/Components/NewComponents/TextDesc.vue';

    const emit = defineEmits(['playerRefresh']);
    const {player_obj} = defineProps(['player_obj']);

    onMounted(() => {
        fetchExtraNotes();

        $('#add_extra_notes').on('hidden.bs.modal', () => {
            need_refresh.value = false;
        });
    });

    const loading = ref(false);
    const loading_extra_edit = ref([]);
    const loading_extra_del = ref([]);
    const loading_extra = ref(false);
    const note_obj = ref(0)
    const extra_notes = ref([]);
    const need_refresh = ref(false);
    const filters = ref({
        page_no: 1,
        max_per_page: 3,
        max_page: 1,
        total: 0
    })

    function fetchExtraNotes(){
        loading_extra.value = true;
        DBService.postData('/api/player-information/fetch-extra-notes/' + player_obj.id, filters.value) .then( (data) => {
            if(data.success){
                extra_notes.value = data.extra_notes;
                note_obj.value = -2;
                loading_extra.value = false;
                filters.value.total = data.total;
                filters.value.max_page = Math.ceil(data.total / filters.value.max_per_page);

                if(need_refresh.value == true){
                    need_refresh.value == false;
                    emit('playerRefresh');
                }
            } else{
                bootbox.alert(data.message);
            }
        });
    }

    function openExtraNoteModal(extra_note_obj){
        let loader_id = 0;
        if(extra_note_obj == -1){
            loader_id = -1;
            loading_extra_edit.value[extra_note_obj] = true;
            console.log(extra_note_obj);
            let new_note_obj = {
                note : player_obj.notes,
                id : -1
            };
            need_refresh.value = true;
            note_obj.value = new_note_obj;
        } else if(extra_note_obj == 0){
            loading_extra_edit.value[extra_note_obj] = true;
            loader_id = 0;
            note_obj.value = extra_note_obj;
        } else{
            loader_id = extra_note_obj.id;
            loading_extra_edit.value[extra_note_obj.id] = true;
            note_obj.value = extra_note_obj;
        }
        loading_extra_edit.value[loader_id] = false;
        $("#add_extra_notes").modal('show');
    }

    function deleteExtraNote(extra_note_id, delete_type){
        if(delete_type == 1){
            loading_extra_del.value[extra_note_id] = true;
        } else{
            loading_extra_del.value[-1] = true;
        }
        bootbox.confirm("Are you sure you wants to delete this note?",(check)=> {
            if (check) {
                loading_extra.value = true;
                DBService.getData('/api/player-information/delete-extra-note/' + extra_note_id + '/' + delete_type) .then( (data) => {
                    if(data.success){
                        if(delete_type == 2){
                            emit('playerRefresh');
                        } else{
                            if(extra_notes.value.length == 1 && filters.value.page_no != 1){
                                filters.value.page_no--;
                            }
                            fetchExtraNotes();
                        }
                        loading_extra.value = false;
                    }
                    bootbox.alert(data.message);
                    loading_extra_del.value[extra_note_id] = false;
                    loading_extra_del.value[-1] = false;
                });
            } else{
                loading_extra_del.value[extra_note_id] = false;
                loading_extra_del.value[-1] = false;
            }
        });
    }

    function setPage(page_no){
        if (page_no < 1 || page_no > filters.value.max_page) return;
        if (page_no == filters.value.page_no) return;
        filters.value.page_no = page_no;
        fetchExtraNotes();
    }
</script>

<template>
    <div class="row">
        <div class="col-md-12">
            <Card title="Scout Note">
                <LengthZero v-if="!loading && !player_obj.notes" cls="text-center pt-4 pb-3"></LengthZero>
                <div class="px-4 py-3" v-else>
                    <span>{{ player_obj.notes }}</span>
                </div>

                <template v-slot:header_slot>
                    <div>
                        <Button2 spinner_cls='spinner-border text-primary spinner-border-sm' :processing="loading_extra_edit[-1]" cls="btn-ghost-primary btn-sm me-1" @click="openExtraNoteModal(-1)"><i class="bi bi-pencil" ></i></Button2>
                        <Button2 :processing="loading_extra_del[-1]" cls="btn-danger btn-sm" @click="deleteExtraNote(player_obj.id, 2)" ><i class="bi bi-trash"></i></Button2>
                    </div>
                </template>
            </Card>
        </div>

        <div class="col-md-12 mt-3">
            <Card title="Extra Notes" :show_footer="true" header_class="px-3" footer_class="d-flex align-items-center justify-content-between">
                <template v-slot:header_slot>
                    <!-- <button :disabled="loading_extra_edit[0]" class="btn btn-success btn-sm" type="button" @click.prevent="openExtraNoteModal(0)">+ Add Extra Note</button> -->
                    <Button2 :disabled="loading_extra_edit[0]" cls="btn btn-success btn-sm" @clickFn="openExtraNoteModal(0)">+ Add Extra Note</Button2>
                </template>
                <div v-if="!loading_extra && extra_notes.length > 0">
                    <div v-for="extra_note in extra_notes">
                        <div class="d-flex justify-content-between px-3 pt-3">
                            <div class="fw-bold" style="margin-top: 5px;">
                                {{ extra_note.display_note_date }}
                            </div>
                            <div>
                                <Button2 spinner_cls='spinner-border text-primary spinner-border-sm' :processing="loading_extra_edit[extra_note.id]" :disabled="loading_extra" cls="btn-ghost-primary btn-sm me-1" @click="openExtraNoteModal(extra_note)"><i class="bi bi-pencil" ></i></Button2>
                                <Button2 :disabled="loading_extra" :processing="loading_extra_del[extra_note.id]" cls="btn-danger btn-sm" @click="deleteExtraNote(extra_note.id, 1)" ><i class="bi bi-trash"></i></Button2>
                            </div>
                        </div>
                        <div class="px-4 pb-1 pt-2" style="color: #737373;">
                            <TextDesc :text="extra_note.note" max="200" color="#737373"></TextDesc>
                        </div>
                        <div class="text-end px-3 pb-2" style="color: #999999;">
                            - {{extra_note.name}}
                        </div>
                    </div>
                </div>
                <LengthZero v-if="!loading_extra && extra_notes.length == 0" cls="pt-3 pb-2"></LengthZero>

                <Loading :loading="loading_extra"></Loading>

                <template v-slot:footer_slot>
                    <Pagination
                        :filters="filters"
                        @set-page="(page_no) => setPage(page_no)"
                    />
                </template>
            </Card>
        </div>
    </div>

    <!-- <hr class="mt-4 mb-4">

    <div class="row mt-4">
        <div class="col-md-8">
            <span style="font-weight: 800; font-size: large;">Extra Notes</span>
        </div>
        <div class="col-md-4 text-right">
            <button class="btn btn-success btn-sm" type="button" @click.prevent="openExtraNoteModal(0)">+ Add Extra Note</button>
        </div>
    </div>
    <div class="row" v-if="!loading && extra_notes.length > 0" >
        <div class="col-md-12" v-for="extra_note in extra_notes">
            <div class="row mt-2">
            </div>
                <div class="col-md-8">
                    <span style="font-weight: 400; font-size: 17px;">{{ extra_note.display_note_date }}</span>  
                <div class="col-md-4 text-right">
                    <i class="icon-note bg-warning text-light p-1 mx-2" @click.prevent="openExtraNoteModal(extra_note)" style="cursor: pointer;"></i>
                    <i class="icon-trash bg-danger text-light p-1" @click.prevent="deleteExtraNote(extra_note.id, 1)" style="cursor: pointer;"></i>
                </div>
                <div class="col-md-12 m-2">
                    {{ extra_note.note }}
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <LengthZero v-if="!loading && extra_notes.length == 0" cls="col-md-12 p-3 mt-2"></LengthZero>
    </div> -->
    <Modal id="add_extra_notes" :title="note_obj == 0 ? 'Add Extra Note' : 'Edit Note'">
        <ExtraNotesModal :player_id="player_obj.id" @callback=" () => fetchExtraNotes()" :note_obj="note_obj"></ExtraNotesModal>
    </Modal>
</template>