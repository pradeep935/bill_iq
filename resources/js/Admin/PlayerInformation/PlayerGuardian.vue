<script setup>
    import { ref, onMounted } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import Card from '@/Components/NewComponents/Card.vue';
    import PlayerGuardianModal from '@/Admin/PlayerInformation/Modals/PlayerGuardianModal.vue';

    const { player_id } = defineProps(['player_id']);

    const loading = ref(false);
    const guardian_list = ref([]);
    const guardian_id = ref(0);
    const loading_edit = ref([]);
    const loading_del = ref([]);
    const filters = ref({
        page_no: 1,
        max_per_page: 5,
        max_page: 1,
        total: 0
    });

    onMounted(() => {
        fetchGuardians();
    });

    function fetchGuardians(){
        loading.value = true;
        DBService.postData('/api/player-information/fetch-guardians/' + player_id, filters.value).then((data) => {
            if(data.success){
                guardian_list.value = data.guardian_list;
                filters.value.total = data.total;
                filters.value.max_page = Math.ceil(data.total / filters.value.max_per_page);
            } else{
                bootbox.alert(data.message);
            }
            loading.value = false;
        });
    }

    function openGuardianModal(id){
        loading_edit.value[id] = true;
        guardian_id.value = id;
        loading_edit.value[id] = false;
        $("#player_guardian_modal").modal('show');
    }

    function deleteGuardian(id){
        loading_del.value[id] = true;
        bootbox.confirm('Are you sure you wants to delete this guardian?', (check) => {
            if(check){
                DBService.getData('/api/player-information/delete-guardian/' + id).then((data) => {
                    if(data.success){
                        if(guardian_list.value.length == 1 && filters.value.page_no != 1){
                            filters.value.page_no--;
                        }
                        fetchGuardians();
                    }
                    bootbox.alert(data.message);
                    loading_del.value[id] = false;
                });
            } else{
                loading_del.value[id] = false;
            }
        });
    }

    function setPage(page_no){
        if (page_no < 1 || page_no > filters.value.max_page) return;
        if (page_no == filters.value.page_no) return;
        filters.value.page_no = page_no;
        fetchGuardians();
    }
</script>

<template>
    <div class="row mb-4">
        <div class="col-md-12">
            <Card title="Player's Guardians" header_class="px-3" :show_footer="true" footer_class="d-flex align-items-center justify-content-between">
                <template v-slot:header_slot>
                    <Button2 :disabled="loading_edit[0]" cls="btn btn-success btn-sm" @clickFn="openGuardianModal(0)">+ Add Guardian</Button2>
                </template>

                <Loading :loading="loading" type="table" :max="5"></Loading>
                <LengthZero v-if="!loading && guardian_list.length == 0"></LengthZero>

                <TableCont v-if="!loading && guardian_list.length > 0">
                    <thead>
                        <tr>
                            <th>SN</th>
                            <th>Relation</th>
                            <th>Name</th>
                            <th>Mobile</th>
                            <th>Email</th>
                            <th>Added By</th>
                            <th width="120">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(guardian, index) in guardian_list" :key="guardian.id">
                            <td>{{ index + 1 + (filters.page_no - 1) * filters.max_per_page }}</td>
                            <td>{{ guardian.relation_name }}</td>
                            <td>{{ guardian.name }}</td>
                            <td>{{ guardian.mobile || '-' }}</td>
                            <td>{{ guardian.email || '-' }}</td>
                            <td>{{ guardian.added_by_name || '-' }}</td>
                            <td>
                                <Button2 spinner_cls="spinner-border text-primary spinner-border-sm" :processing="loading_edit[guardian.id]" :disabled="loading" cls="btn-ghost-primary btn-sm me-1" @click="openGuardianModal(guardian.id)"><i class="bi bi-pencil"></i></Button2>
                                <Button2 :processing="loading_del[guardian.id]" :disabled="loading" cls="btn-danger btn-sm" @click="deleteGuardian(guardian.id)"><i class="bi bi-trash"></i></Button2>
                            </td>
                        </tr>
                    </tbody>
                </TableCont>

                <template v-slot:footer_slot>
                    <Pagination
                        :filters="filters"
                        @set-page="(page_no) => setPage(page_no)"
                    />
                </template>
            </Card>
        </div>
    </div>

    <Modal id="player_guardian_modal" :title="guardian_id == 0 ? 'Add Guardian' : 'Edit Guardian'">
        <PlayerGuardianModal :guardian_id="guardian_id" :player_id="player_id" @callback="() => fetchGuardians()"></PlayerGuardianModal>
    </Modal>
</template>
