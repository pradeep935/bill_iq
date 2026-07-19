<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import LengthZero from '@/Components/NewComponents/LengthZero.vue';
    import Card from '@/Components/NewComponents/Card.vue';
    import TextDesc from '@/Components/NewComponents/TextDesc.vue';

    const {player_id} = defineProps(['player_id']);
    const base_url = import.meta.env.VITE_APP_URL;
    const filters = ref({
        page_no: 1,
        max_per_page: 5,
        max_page: 1,
        total: 0
    })

    onMounted(
        () => {
            fetchRecruimentDetails();
        }
    )

    const loading = ref(false);
    const shortlist_list = ref([]);

    function fetchRecruimentDetails(){
        loading.value = true;
        DBService.postData('/api/player-information/shortlist-details/' + player_id, filters.value).then((data) => {
            if(data.success){
                shortlist_list.value = data.shortlist_list
                filters.value.total = data.total;
                filters.value.max_page = Math.ceil(data.total / filters.value.max_per_page);
            } else{
                bootbox.alert(data.message);
            }
            loading.value = false;
        });
    }

    function setPage(page_no){
        if (page_no < 1 || page_no > filters.value.max_page) return;
        if (page_no == filters.value.page_no) return;
        filters.value.page_no = page_no;
        fetchRecruimentDetails();
    }
</script>

<template>
    <div class="row mb-4">
        <div class="col-md-12">
            <Card title="Shortlist Details" header_class="px-3" :show_footer="true" footer_class="d-flex align-items-center justify-content-between">
                <Loading :loading="loading" type="table" :max="5"></Loading>
                <LengthZero v-if="!loading && shortlist_list.length == 0"></LengthZero>
                <TableCont v-if="!loading && shortlist_list.length > 0">
                    <thead>
                        <tr>
                            <th>SN</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Author</th>
                            <th>Updated At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(shortlist_obj, index) in shortlist_list">
                            <td>{{ index + 1 + (filters.page_no - 1)*filters.max_per_page }}</td>
                            <td>
                                <a :href="base_url + '/shortlist'" target="_blank" style="color: #0066ff;">{{ shortlist_obj.shortlist_name }}</a>
                            </td>
                            <td>
                                <TextDesc :text="shortlist_obj.description" :max="85"/>
                            </td>
                            <td>{{ shortlist_obj.name }}</td>
                            <td>{{ shortlist_obj.display_updated_at }}</td>
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

</template>