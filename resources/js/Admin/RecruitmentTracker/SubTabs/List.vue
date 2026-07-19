<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import Card from '@/Components/NewComponents/Card.vue';
    
    const {def_filters, reload} = defineProps(['def_filters','reload']);
    const filters = ref(JSON.parse(JSON.stringify(def_filters)));

    const emit = defineEmits(['listEdit']);
    const base_url = import.meta.env.VITE_APP_URL;

    onMounted(
        () => {
            fetchRecruitmentPositions();
        }
    )

    watch(
        () => reload,
        () => { fetchRecruitmentPositions(); }
    )

    const loading = ref(false);
    const rec_pos_list = ref([]);
    const edit_obj = ref({})

    function fetchRecruitmentPositions(){
        loading.value = true;
        DBService.postData('/api/recruitment-tracker/recruitment-position/fetch-positions', filters.value ).then((data) => {
            if(data.success){
                rec_pos_list.value = data.rec_pos_list;
                filters.value.total = data.total;
                filters.value.max_page = data.max_page;
            }
            loading.value = false;
        });
    }

    function editListData(temp_obj){
        Object.assign(edit_obj.value, temp_obj);
        emit('listEdit', edit_obj.value);
    }

    function deleteRecruitmentPosition(temp_id){
        bootbox.confirm("Are you sure?",(check)=> {
            if (check) {
                loading.value = true;
                DBService.getData('/api/recruitment-tracker/recruitment-position/delete-position/' + temp_id).then((data) => {
                    if(data.success){
                        fetchRecruitmentPositions();
                    }
                    bootbox.alert(data.message);
                    loading.value = false;
                })
            }
        });
    }

    function setPage(page_no){
	    if(page_no < 1 || page_no > filters.value.max_page) return;
        if(page_no == filters.value.page_no) return;
	    filters.value.page_no = page_no;
	    fetchRecruitmentPositions();
	}
</script>

<template>
    <Card type="table" :show_footer="true" header_class="d-none" footer_class="d-flex align-items-center justify-content-between px-3 py-2" class="rp-card" >

        <Loading :loading="loading" type="table" />
        <LengthZero v-if="rec_pos_list.length == 0" />
        <TableCont v-if="!loading && rec_pos_list.length > 0">
            <thead>
                <tr>
                    <th>SN</th>
                    <th>Name</th>
                    <th>Positions</th>
                    <th>Transfer Value</th>
                    <th>Annual Salary</th>
                    <th>Creation Date / Time</th>
                    <th class="text-end">#</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(rec_pos, index) in rec_pos_list">
                    <td>{{ index + 1 + (filters.page_no - 1)*filters.max_per_page }}</td>
                    <td><a :href="base_url + '/recruitment-tracker/recruitment-position/players/' + rec_pos.id" style="color: #0066ff;">{{ rec_pos.name }}</a></td>
                    <td>{{ rec_pos.display_position_list }}</td>
                    <td><Money :amount="rec_pos.transfer_value" /></td>
                    <td><Money :amount="rec_pos.annual_salary" /></td>
                    <td>{{ rec_pos.display_created_at }}</td>
                    <td class="text-end">
                        <Button2 cls="btn-ghost-primary btn-sm me-1" data-bs-toggle="offcanvas" data-bs-target="#add_new_rec_position" @click.prevent="editListData(rec_pos)" >
                            <i class="bi bi-pencil" ></i>
                        </Button2>
                        <Button2 cls="btn-danger btn-sm" @click.prevent="deleteRecruitmentPosition(rec_pos.id)" >
                            <i class="bi bi-trash"></i>
                        </Button2>
                    </td>
                </tr>
            </tbody>
        </TableCont>
        <template v-slot:footer_slot>
            <Pagination
                :filters="filters"
                @set-page="(page_no) => setPage(page_no)" itemName="players"
            />
        </template>
    </Card>
</template>