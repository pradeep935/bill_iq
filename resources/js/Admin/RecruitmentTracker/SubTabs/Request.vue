<script setup>
    import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import Field from '@/Components/NewComponents/Field.vue';
    import Card from '@/Components/NewComponents/Card.vue';
    import TextDesc from '@/Components/NewComponents/TextDesc.vue';

    const {reload, def_filters} = defineProps(['reload','def_filters']);
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
    const field_width = ref(220);
    const field_wrapper_refs = ref([]);

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

    function editListData(temp_obj){
        Object.assign(edit_obj.value, temp_obj);
        emit('listEdit', edit_obj.value);
    }

    function setFieldWrapperRef(el, index){
        field_wrapper_refs.value[index] = el;
    }

    function updateFieldWidth(){
        nextTick(() => {
            const wrapper = field_wrapper_refs.value.find(Boolean);
            if(!wrapper) return;

            const availableWidth = Math.floor(wrapper.clientWidth - 24);
            field_width.value = Math.max(220, Math.min(availableWidth, 340));
        });
    }

    function setPage(page_no){
	    if(page_no < 1 || page_no > filters.value.max_page) return;
        if(page_no == filters.value.page_no) return;
	    filters.value.page_no = page_no;
	    fetchRecruitmentPositions();
	}

    function PlayerListLink(temp_id){
        window.open(base_url + '/recruitment-tracker/recruitment-position/players/' + temp_id, '_blank');
    }

    watch(
        rec_pos_list,
        () => {
            field_wrapper_refs.value = [];
            updateFieldWidth();
        },
        { flush: 'post' }
    );

    onMounted(() => {
        window.addEventListener('resize', updateFieldWidth);
        updateFieldWidth();
    });

    onBeforeUnmount(() => {
        window.removeEventListener('resize', updateFieldWidth);
    });
</script>

<template>
    <Card type="table" :show_footer="true" header_class="d-none" footer_class="d-flex align-items-center justify-content-between px-3 py-2" class="rp-card" >
        <LengthZero v-if="rec_pos_list.length == 0" />

        <div class="row mt-3 p-2" v-else>
            <div class="col-sm-6 col-lg-4 col-xl-3 mb-3" v-for="(rec_pos_obj, index) in rec_pos_list" style="cursor: pointer;">
                <Card :title="rec_pos_obj.name" body_center="true" show_footer="true" show_filters="true" @click.prevent="PlayerListLink(rec_pos_obj.id)" class="elevated-card">
                    <div class="field-preview-wrapper" :ref="(el) => setFieldWrapperRef(el, index)">
                        <Field :width="field_width" :position_arr="rec_pos_obj.position_arr"></Field>
                    </div>

                    <template v-slot:footer_slot>
                        <span style="font-weight: 800; font-size: small;">Positions :</span>
                        <span class="ms-2">{{ rec_pos_obj.display_position_list }}</span>
                        <br>
                        <span style="font-weight: 800; font-size: small;">Note :</span>
                        <span class="ms-2"><TextDesc :text="rec_pos_obj.note" :max="20"></TextDesc></span>
                        <br>
                        <span style="font-weight: 800; font-size: small;">Requirement :</span>
                        <span class="ms-2"><TextDesc :text="rec_pos_obj.requirement" :max="20"></TextDesc></span>
                    </template>

                    <template v-slot:header_slot>
                        <div>
                            <Button2 cls="btn-ghost-primary btn-sm " data-bs-toggle="offcanvas" data-bs-target="#add_new_rec_position" @click.prevent.stop="editListData(rec_pos_obj)" >
                               <i class="bi bi-pencil" ></i>
                           </Button2>
   
                           <Button2 cls="btn-danger btn-sm ms-1" @click.prevent.stop="deleteRecruitmentPosition(rec_pos_obj.id)" >
                               <i class="bi bi-trash"></i>
                           </Button2>
                        </div>
                    </template>
                </Card>
            </div>
        </div>
        <template v-slot:footer_slot>
            <Pagination :filters="filters" @set-page="(page_no) => setPage(page_no)" />
        </template>
    </Card>
</template>

<style scoped>
    .field-preview-wrapper {
        display: flex;
        justify-content: center;
        align-items: center;
        width: 100%;
        padding: 12px;
        overflow-x: auto;
    }

    .elevated-card {
        transition: box-shadow 0.3s ease, transform 0.3s ease;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        height: 100%;
    }

    .elevated-card:hover {
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        transform: translateY(-4px);
    }
</style>
