<script setup>
import { ref, onMounted, watch } from 'vue';
import { computed } from 'vue';
import ChangePictureModal from '@/Components/Common/ChangePictureModal.vue';
import DBService from '@/Service/Utils/DBService.js'

const props = defineProps({
    title: {
        type: String,
        default: '',
    }, 
    edit_access: {
        type: Boolean,
        default: false,
    },
    subtitle: {
        type: String,
        default: '',
    },
    player_id: {
        type: Number,
        default: 0,
    },
    national_team_count: {
        type: Number,
        default: 0,
    },
    location: {
        type: String,
        default: '',
    },
    position_first: {
        type: String,
        default: '',
    },
    position_second: {
        type: String,
        default: '',
    },
    avatarText: {
        type: String,
        default: '',
    },
    avatarSrc: {
        type: String,
        default: '',
    },
    stats: {
        type: Array,
        default: () => [],
    },
    tabs: {
        type: Array,
        default: () => [],
    },
    activeTab: {
        type: [String, Number],
        default: '',
    },
    national_teams: {
        type: String,
        default: '',
    },
    is_national_team_selected: {
        type: Boolean,
        default: false,
    },
});

const avatarPreview = ref(props.avatarSrc)
const s3_url_link = import.meta.env.VITE_S3_URL
const emit = defineEmits(['tab-click']);

const derivedAvatarText = computed(() => {
    if (props.avatarText) return props.avatarText;
    if (!props.title) return '?';

    const parts = props.title.trim().split(' ').filter(Boolean);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();

    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
});

const metaItems = computed(() => {
    const items = [];

    if (props.location) {
        items.push({
            type: 'location',
            text: props.location,
            icon: 'bi bi-geo-alt',
        });
    }

    if (props.position_first) {
        items.push({
            type: 'code',
            text: props.position_first,
        });
    }

    if (props.position_second) {
        items.push({
            type: 'position_second',
            text: props.position_second,
        });
    }

    return items;
});

function isActiveTab(tab) {
    const value = typeof tab === 'object' ? (tab.value ?? tab.label) : tab;
    return value === props.activeTab;
}

function tabLabel(tab) {
    return typeof tab === 'object' ? (tab.label ?? tab.value ?? '') : tab;
}

function tabValue(tab) {
    return typeof tab === 'object' ? (tab.value ?? tab.label) : tab;
}

watch(() => props.avatarSrc, (newVal) => {
    avatarPreview.value = newVal
})


function openPlayerPictureModal(){
    if (!props.edit_access) {
        return;
    }
    $('#player-picture-modal').modal('show')
}

function updatePlayerProfile(dataSet){
    DBService.postData('/api/players/change-profile-pic',{picture: dataSet.path, player_id: props.player_id}).then((data) => {
        bootbox.alert(data.message)
        if(data.success){
            avatarPreview.value = data.path
        }
    })
};

</script>

<template>
    <div class="info-box">
        <div class="info-box__top">
            <div class="info-box__accent"></div>

            <div class="d-flex justify-content-between">
                <div class="info-box__identity">
                    <!-- <div class="info-box__avatar">
                        <img v-if="avatarPreview" :src="s3_url_link + avatarPreview" :alt="title || 'Profile'" class="info-box__avatar-img"  @click="openPlayerPictureModal" />

                        <span v-else @click="openPlayerPictureModal">{{ derivedAvatarText }}</span>
                    </div> -->

                    <div class="info-box__avatar-wrapper">
                        <div class="info-box__avatar">
                            <img v-if="avatarPreview" :src="s3_url_link + avatarPreview" :alt="title || 'Profile'" class="info-box__avatar-img" @click="openPlayerPictureModal" />

                            <span v-else @click="openPlayerPictureModal">
                                {{ derivedAvatarText }}
                            </span>
                        </div>

                        <div v-if="is_national_team_selected" class="player-flag" title="National Team Selected">
                            <span v-if="is_national_team_selected" class="national-team-badge-round">
                                NT
                            </span>
                            <!-- 🇮🇳 -->
                        </div>
                    </div>
    
                    <div class="info-box__content">
                        <div v-if="title" class="info-box__title text-capitalize">{{ title }}</div>
                        <div class="d-flex align-items-center gap-2 mt-2" v-if="is_national_team_selected">
                           <span class="national-team-badge">{{ national_team_count > 1 ? 'National Teams' : 'National Team' }}</span>
                            <span class="national-team-name">{{ national_teams }}</span>
                        </div>
                        <div v-if="subtitle" class="info-box__subtitle">{{ subtitle }}</div>
    
                        <div v-if="metaItems.length" class="info-box__meta">
                            <div v-for="(item, index) in metaItems" :key="index" class="info-box__meta-item" :class="`info-box__meta-item--${item.type}`" >
                                <i v-if="item.icon" :class="item.icon"></i>
                                <span>{{ item.text }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <slot></slot>
                </div>
            </div>

        </div>

        <div v-if="stats.length" class="info-box__stats">
            <div v-for="(stat, index) in stats" :key="index" class="info-box__stat" style="background-color: #f2f2f2;">
                <div class="info-box__stat-value">
                    <span v-if="stat.type == 'text'">{{ stat.value ?? '-' }}</span>
                    <span v-if="stat.type == 'money'"><Money :amount="stat.value"></Money></span>
                </div>
                <div class="info-box__stat-label">{{ stat.label ?? '' }}</div>
            </div>
        </div>

        <div v-if="tabs.length" class="info-box__tabs">
            <button v-for="(tab, index) in tabs" :key="index" type="button" class="info-box__tab" :class="{ 'is-active': isActiveTab(index) }" @click="emit('tab-click', tabValue(index))">
                {{ tabLabel(tab) }}
            </button>
        </div>
    </div>

    <ChangePictureModal modal-id="player-picture-modal" upload-url="/upload/photo" update-url="/api/players/change-profile-pic" :entity-id="Number(props.player_id)" field-name="picture" id-field="player_id"  @uploaded="updatePlayerProfile" />
</template>

<style scoped>
    .info-box {
        background: #fff;
        border: 1px solid #dfe6f1;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    }

    .info-box__top {
        position: relative;
        min-height: 160px;
        padding: 10px 28px 3px;
        background:
            linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98)),
            repeating-linear-gradient(
                135deg,
                rgba(37, 99, 235, 0.04) 0,
                rgba(37, 99, 235, 0.04) 2px,
                transparent 2px,
                transparent 10px
            );
        background-position: 0 0, right top;
        background-size: 100% 100%, 220px 100%;
        background-repeat: no-repeat;
    }

    .info-box__accent {
        position: absolute;
        inset: 0 auto 0 0;
        width: 4px;
        background: linear-gradient(180deg, #1d4ed8, #1e40af);
    }

    .info-box__identity {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .info-box__avatar {
        width: 92px;
        height: 92px;
        border-radius: 12px;
        border: 1px solid #d6dbe7;
        background: linear-gradient(180deg, #eef2ff, #e0e7ff);
        color: #1e3a8a;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: 800;
        letter-spacing: -0.03em;
        flex-shrink: 0;
        overflow: hidden;
    }

    .info-box__avatar-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .info-box__content {
        min-width: 0;
    }

    .info-box__title {
        color: #0f172a;
        font-size: 24px;
        font-weight: 800;
        letter-spacing: -0.03em;
        line-height: 1.15;
    }

    .info-box__subtitle {
        margin-top: 6px;
        color: #64748b;
        font-size: 14px;
        font-weight: 500;
        padding: 0px 3px;
    }

    .info-box__meta {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 10px;
        padding: 0px 3px;
    }

    .info-box__meta-item {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-height: 28px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 700;
        line-height: 1;
    }

    .info-box__meta-item--location {
        color: #94a3b8;
    }

    .info-box__meta-item--code {
        padding: 0 10px;
        color: #dc2626;
        background: #ffdcdc;
        border: 1px solid #f87f7f;
    }

    .info-box__meta-item--position_second {
        padding: 0 12px;
        color: #1b2266;
        background: #a3ccf3;
        border: 1px solid #3d76e0;
    }

    .info-box__stats {
        display: grid;
        /*grid-template-columns: repeat(8, minmax(0, 1fr));*/
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        border-top: 1px solid #dfe6f1;
        border-bottom: 1px solid #dfe6f1;
    }

    /*.info-box__stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    }*/

    .info-box__stat {
        min-height: 78px;
        padding: 16px 14px;
        text-align: center;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .info-box__stat + .info-box__stat {
        border-left: 1px solid #c0c7d3;
    }

    .info-box__stat-value {
        color: #0f172a;
        font-size: 20px;
        font-weight: 800;
        line-height: 1.2;
    }

    .info-box__stat-label {
        margin-top: 4px;
        color: #94a3b8;
        font-size: 13px;
        font-weight: 600;
    }

    .info-box__tabs {
        display: flex;
        align-items: center;
        gap: 4px;
        padding: 0 20px;
        overflow-x: auto;
        background: #fff;
    }

    .info-box__tab {
        position: relative;
        padding: 16px 18px 17px;
        border: 0;
        background: transparent;
        color: #94a3b8;
        font-size: 14px;
        font-weight: 700;
        white-space: nowrap;
        cursor: pointer;
        transition: color 0.15s ease;
    }

    .info-box__tab:hover,
    .info-box__tab.is-active {
        color: #1e40af;
    }

    .info-box__tab.is-active::after {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 3px;
        border-radius: 999px 999px 0 0;
        background: #1d4ed8;
    }

    @media (max-width: 991px) {
        .info-box__top {
            padding: 24px 20px 22px;
        }

        .info-box__identity {
            align-items: flex-start;
        }

        .info-box__title {
            font-size: 22px;
        }

        .info-box__stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .info-box__stat:nth-child(2n) {
            border-left: 1px solid #dfe6f1;
        }
    }





    .info-box__avatar-wrapper{
        position: relative;
    }

    .player-flag{
        position: absolute;
        top: -8px;
        right: -8px;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 8px rgba(0,0,0,.15);
        font-size: 14px;
    }

    .national-team-badge{
        background: #16a34a;
        color: #fff;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }

    .national-team-name{
        color: #475569;
        font-size: 13px;
        font-weight: 600;
    }

    @media (max-width: 576px) {
        .info-box__identity {
            flex-direction: column;
            gap: 16px;
        }

        .info-box__avatar {
            width: 80px;
            height: 80px;
            font-size: 16px;
        }

        .info-box__title {
            font-size: 20px;
        }

        .info-box__stats {
            grid-template-columns: 1fr;
        }

        .info-box__stat + .info-box__stat {
            border-left: 0;
            border-top: 1px solid #dfe6f1;
        }

        .info-box__tabs {
            padding: 0 12px;
        }
    }


    .national-team-badge-round{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        width:20px;
        height:20px;
        border-radius:50%;
        background:#16a34a;
        color:#fff;
        font-size:10px;
        font-weight:700;
    }
</style>
