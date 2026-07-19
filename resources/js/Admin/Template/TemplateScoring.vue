<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import ModalRight from '@/Components/NewComponents/ModalRight.vue';
    import SubCategoryWeightage from '@/Admin/Template/SubCategoryWeightage.vue';
    import SubCategoryScoring from '@/Admin/Template/SubCategoryScoring.vue';

    const {template_obj} = defineProps(['template_obj']);
   
    onMounted(() => {
        sub_category_id.value = 0;
        category_id.value = 0;
        score_method_updating.value = false;
    });

    const loading = ref(false);
    const score_method_updating = ref(false);
    const rest_color = '';
    const hover_color = '#e6e6e6';
    const category_id = ref({});
    const sub_category_id = ref(0);
    const scoring_color = '#cccccc';

    function addEditScore(sub_cat_id){
        sub_category_id.value = sub_cat_id;
    }

    function testFunc(){
        console.log(98);
    }

    function addWeightageToSubCategory(cat_id){
        category_id.value = cat_id;
    };

    const offcanvasRef = ref(null)
    const offcanvasRef2 = ref(null)

    function refreshCanvasWeightModal() {
        category_id.value = 0;
        offcanvasRef.value.close() // 👈 reusable close
    };

    function refreshCanvasScoringModal(){
        sub_category_id.value = 0;
        offcanvasRef2.value.close() // 👈 reusable close
    }
</script>

<template>
    <Loading :loading="loading" type="table"></Loading>
    <div v-if="!loading">
        <div class="row py-2 score-card score-template" :style="'background-color: ' + template_obj.color" @mouseover="template_obj.color = hover_color" @mouseleave="template_obj.color = rest_color">
            <div class="col-md-8">
                <div class="d-flex align-items-center gap-2">
                    <span class="score-toggle" @click.prevent="template_obj.show = !template_obj.show">
                        <i :class="template_obj.show ? 'bi bi-chevron-down' : 'bi bi-chevron-right'"></i>
                    </span>
                    <div>
                        <div class="score-title">{{ template_obj.template_name }}</div>
                        <div class="score-desc">{{ template_obj.description }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 d-flex justify-content-end align-items-center">
                <span class="badge score-pill">Scoring Setup</span>
            </div>
        </div>
        <transition name="fade" v-for="cat_obj in template_obj.cats" :key="cat_obj.id" v-show="template_obj.show">
            <div>
                <div class="score-highlight" :class="{ 'is-active': category_id == cat_obj.id }">
                    <div class="row mt-2 py-2 score-card score-category" :style="'background-color: ' + cat_obj.color" @mouseover="cat_obj.color = hover_color" @mouseleave="cat_obj.color = rest_color">
                        <div class="col-md-8 ps-4">
                            <div class="d-flex align-items-center gap-2">
                                <span class="score-toggle" @click.prevent="cat_obj.show = !cat_obj.show">
                                    <i :class="cat_obj.show ? 'bi bi-chevron-down' : 'bi bi-chevron-right'"></i>
                                </span>
                                <div>
                                    <div class="score-title">{{ cat_obj.cat_name }}</div>
                                    <div class="score-desc">{{ cat_obj.description }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 d-flex justify-content-end align-items-center">
                            <button class="btn btn-sm btn-light score-btn" title="Assign Weightage"  data-bs-toggle="offcanvas" data-bs-target="#add_edit_sub_category_weightage"  @click.prevent="addWeightageToSubCategory(cat_obj.id)">
                                <i class="bi bi-bar-chart-line"></i> Weightage
                            </button>
                        </div>
                    </div>
                </div>
                <transition name="fade" v-for="sub_cat_obj in cat_obj.sub_cats" :key="sub_cat_obj.id" v-show="template_obj.show && cat_obj.show">
                    <div class="score-highlight" :class="{ 'is-active': sub_category_id == sub_cat_obj.id }">
                        <div class="row mt-2 py-2 score-card score-sub" :style="'background-color: ' + sub_cat_obj.color" @mouseover="sub_cat_obj.color = hover_color" @mouseleave="sub_cat_obj.color = rest_color">
                            <div class="col-md-8 ps-5">
                                <div class="score-title">{{ sub_cat_obj.sub_cat_name }}</div>
                                <div class="score-desc">{{ sub_cat_obj.description }}</div>
                            </div>
                            <div class="col-md-4 d-flex justify-content-end align-items-center">
                                <button class="btn btn-sm btn-outline-secondary score-btn" title="Assign Score" @click="addEditScore(sub_cat_obj.id);"  data-bs-toggle="offcanvas" data-bs-target="#add_edit_sub_category_score"><i class="bi bi-calculator"></i> Score
                                </button>
                            </div>
                        </div>
                    </div>
                </transition>
            </div>
        </transition>
    </div>

    <ModalRight title="Assign Score" id="add_edit_sub_category_score" ref="offcanvasRef2" >
        <SubCategoryScoring :sub_category_id="sub_category_id" @close="refreshCanvasScoringModal()" />
    </ModalRight>

    <ModalRight title="Assign Weightage" id="add_edit_sub_category_weightage" ref="offcanvasRef" >
        <SubCategoryWeightage :category_id="category_id" @close="refreshCanvasWeightModal()" />
    </ModalRight>
</template>

<style scoped>
    .fade-enter-active, .fade-leave-active {
        transition: opacity 0.2s ease-in-out 0.1s;
    }
    .fade-enter-from, .fade-leave-to {
        opacity: 0;
    }
    .fade-enter-to, .fade-leave-from {
        opacity: 1;
    }

    .score-card{
        border-radius: 10px;
        border: 1px solid rgba(0,0,0,0.04);
        box-shadow: 0 2px 10px rgba(0,0,0,0.04);
    }
    .score-template{
        margin-top: 8px;
    }
    .score-category{
        margin-left: 6px;
    }
    .score-sub{
        margin-left: 18px;
    }
    .score-title{
        font-weight: 600;
        color: #1f2a3a;
    }
    .score-desc{
        font-size: 12px;
        color: #6b7785;
        margin-top: 2px;
    }
    .score-toggle{
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 6px;
        background: rgba(255,255,255,0.75);
        border: 1px solid rgba(0,0,0,0.06);
    }
    .score-btn{
        text-transform: none;
        font-weight: 600;
        letter-spacing: 0.2px;
    }
    .score-pill{
        background: #e9f2ff;
        color: #1a6bd6;
        border: 1px solid #cfe1ff;
        font-weight: 600;
        padding: 6px 10px;
        border-radius: 999px;
    }
    .score-highlight{
        border-radius: 12px;
        padding: 2px;
        transition: background 0.15s ease;
    }
    .score-highlight.is-active{
        background: rgba(26, 140, 255, 0.1);
    }
</style>
