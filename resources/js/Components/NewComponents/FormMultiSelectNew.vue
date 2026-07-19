<script setup>
    import { useField } from 'vee-validate';
    import { ref, computed, onMounted, onBeforeUnmount} from "vue";
    import * as yup from 'yup';

    const wrapper = ref(null);
    const s3_url_link = import.meta.env.VITE_S3_URL

    function handleClickOutside(e) {
        if (wrapper.value && !wrapper.value.contains(e.target)) {
            open.value = false
        }
    }

    onMounted(() => {
        document.addEventListener('click', handleClickOutside)
    })

    onBeforeUnmount(() => {
        document.removeEventListener('click', handleClickOutside)
    })

    const colors = ['#6366f1','#10b981','#f59e0b','#ef4444'];
    const model = defineModel() 
    const open = ref(false);
    const search = ref('')
    const temp_select = ref('');

    const {name, type='text', modelValue, cls='', cls2='', cls3='px-2 me-2', color='', req=false, label='', placeholder='', options=[], opt_id='value', opt_name='label', select_name='Select', disabled=false, field_name=""} = defineProps(['name','type','modelValue','cls','cls2','cls3','color','req','label','placeholder','options','opt_id','opt_name','select_name', 'disabled','field_name']); 

    const processReq = computed(() => {
        if (!req) {
            return yup.array();
        } else {
            return yup
                .array()
                .required('This field is required')
        }
    });

    const { value, errorMessage } = useField(() => name, processReq, {
        syncVModel: true,
    });

    function newMultiSelectDisplay(){
        if (temp_select.value) {
            model.value.push(temp_select.value);
            temp_select.value = '';
        }
    }

    function removeSelectedOption(temp_obj){
        let idx = model.value.indexOf(temp_obj[opt_id]);
        model.value.splice(idx, 1);
    };

    function getInitials(name){
        if(!name) return '';
        return name
            .split(' ')
            .map(n => n[0])
            .join('')
            .substring(0,2)
            .toUpperCase();
    };

    function getColor(name){
        let index = name.length % colors.length;
        return colors[index];
    };


    const selectedOption = computed(() => {
        return options.find(o => o[opt_id] == temp_select.value);
    });

    function toggleDropdown(){
        open.value = !open.value;
        search.value = '';
    }

    function selectOption(opt){
        temp_select.value = opt[opt_id];
        newMultiSelectDisplay();
        // open.value = false;
    }

    const filteredOptions = computed(() => {
        return options.filter(opt => {
            const name = opt[opt_name].toLowerCase()
            return (!model.value.includes(opt[opt_id]) && name.includes(search.value.toLowerCase()))
        })
    })
</script>

<template>
    <div :class="`form-group ${cls}`">
        <div class="custom-select" ref="wrapper"  >
            <!-- <label>{{label}}</label> -->
            <div class="selected-summary d-flex align-items-center justify-content-between mt-2" >
                <h6 class="fw-semibold mb-2 selected-summary-title" >
                    {{ label }}
                </h6>

                <span class="selected-count-badge" v-if="field_name && model.length">
                    {{ model.length }}
                </span>
            </div>

            <div :class="cls2" class="selected-chip-wrap d-flex flex-wrap gap-2 mb-2" v-show="model.length > 0" >
                <div class="multi-chip" :class="cls3" :style="color ? 'background-color: ' + color : ''" v-for="opt in options"  v-show="model.includes(opt[opt_id])" >
                    <div class="avatar-box">
                        <img v-if="opt.avatar" :src="s3_url_link + opt.avatar" class="avatar-img" />
                        <span class="avatar-fallback" :style="{ background: getColor(opt[opt_name]) }">
                            {{ getInitials(opt[opt_name]) }}
                        </span>
                    </div>
                    <span class="chip-label">{{ opt[opt_name] }}</span>
                    <button type="button" class="chip-close" @click.stop="removeSelectedOption(opt)">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>

            <div class="selected-value"  @click.stop="toggleDropdown">
                <span v-if="!selectedOption">{{ select_name }}</span>
                <div v-else class="selected-item">
                    <img :src="s3_url_link + selectedOption.avatar" />
                    <span>{{ selectedOption[opt_name] }}</span>
                </div>
                <i class="bi bi-chevron-down"></i>
            </div>

            <div class="dropdown" v-if="open" >
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" v-model="search" placeholder="Search..." @click.stop />
                </div>
                <div class="dropdown-item" v-for="opt in filteredOptions"  :key="opt[opt_id]" v-show="!model.includes(opt[opt_id])" @click.stop="selectOption(opt)" >
                    <div class="avatar-box">
                        <img v-if="opt.avatar" :src="s3_url_link + opt.avatar" class="avatar-img" />
                        <span v-else class="avatar-fallback" :style="{ background: getColor(opt[opt_name]) }">
                            {{ getInitials(opt[opt_name]) }}
                        </span>
                    </div>
                    <span class="name">{{ opt[opt_name] }}</span>
                </div>
            </div>
        </div>
        
   </div>
</template>

<style scoped>
    .selected-summary {
        padding: 0.2rem 0;
    }

    .selected-summary-title {
        color: #0f172a;
        font-weight: 600;
    }
    /* Badge */
    .selected-count-badge {
        min-width: 30px;
        height: 30px;
        padding: 0 10px;
        border-radius: 999px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #6366f1, #4f46e5);
        color: #fff;
        font-size: 12px;
        font-weight: 600;
        box-shadow: 0 6px 14px rgba(79, 70, 229, 0.25);
    }

    .selected-chip-wrap {
        margin-bottom: 0.75rem;
    }

        /* ===== CHIPS ===== */
    .multi-chip {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        color: #0f172a;
        font-size: 13px;
        padding: 6px 12px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;

    }

     .multi-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .multi-chip {
        backdrop-filter: blur(6px);
    }

    .multi-chip:hover {
        background: #f1f5f9;
        transform: translateY(-1px);
    }

   .chip-close {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: #f1f5f9;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .chip-close:hover {
        background: #e94737;
        color: white;
    }

    /* ===== LABEL ===== */
    .input-label {
        font-size: 0.9rem;
        font-weight: 600;
        color: #0f172a;
    }

    /* ===== SELECT BOX ===== */
    .select-shell {
        position: relative;
        border-radius: 14px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        transition: all 0.2s ease;
    }

    /* Hover */
    .select-shell:hover {
        border-color: #cbd5f5;
    }

    /* Focus (🔥 main improvement) */
    .select-shell:focus-within {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
    }

    /* Disabled */
    .select-shell.disabled {
        background: #f8fafc;
        opacity: 0.7;
    }

    /* ===== SELECT ===== */
    .modern-select {
        width: 100%;
        min-height: 48px;
        border: none;
        outline: none;
        background: transparent;
        appearance: none;
        padding: 0 40px 0 14px;
        font-size: 14px;
        color: #0f172a;
    }

    /* Arrow */
    .select-icon {
        position: absolute;
        top: 50%;
        right: 14px;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 13px;
    }

    /* ===== TEXT ===== */
    .helper-text {
        color: #64748b;
        font-size: 11px;
    }

    .error-text {
        font-size: 11px;
    }

    /* ===== MICRO INTERACTION ===== */
    .multi-chip,
    .select-shell,
    .chip-close {
        transition: all 0.2s ease;
    }

    /* Improve chip layout */
    .multi-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .custom-select {
        position: relative;
        cursor: pointer;
    }

    .selected-value {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 14px;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        background: white;
    }

    .selected-item {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .selected-item img {
        width: 24px;
        height: 24px;
        border-radius: 50%;
    }

    .dropdown-item img {
        width: 26px;
        height: 26px;
        border-radius: 50%;
    }
    .dropdown {
        position: absolute;
        width: 100%;
        background: #ffffff;
        border-radius: 14px;
        margin-top: 6px;
        box-shadow: 0 12px 30px rgba(0,0,0,0.08);
        z-index: 50;
        max-height: 220px;
        overflow-y: auto;
        padding: 6px;
    }

    /* Item */
    .dropdown-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px;
        border-radius: 10px;
        cursor: pointer;
        transition: 0.2s;
    }

    .dropdown-item:hover {
        background: #f1f5f9;
    }

    /* Avatar */
    .avatar-box {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        overflow: hidden;
        flex-shrink: 0;
    }

    /* Image */
    .avatar-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    /* Fallback */
    .avatar-fallback {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 600;
        color: white;
        background: linear-gradient(135deg, #6366f1, #4f46e5);
    }

    /* Name */
    .name {
        font-size: 14px;
        color: #0f172a;
        font-weight: 500;
    }

    .search-box {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px;
        border-bottom: 1px solid #e2e8f0;
    }

    .search-box input {
        border: none;
        outline: none;
        width: 100%;
        font-size: 14px;
    }

    .search-box i {
        color: #94a3b8;
    }

    .empty {
        text-align: center;
        padding: 12px;
        font-size: 13px;
        color: #94a3b8;
    }
</style>