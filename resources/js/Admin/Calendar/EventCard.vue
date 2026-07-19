<script setup>

    defineProps({ event: Object, isOpen: Boolean, activityType: String, badgeClass: String });

    const emit = defineEmits(['edit', 'toggle']);

</script>
<template>
    <article class="event-card mt-2" :class="{ 'event-card--open': isOpen }">
        <div class="event-card__header" @click="emit('toggle')">
            <div class="event-title-wrap">
                <h4 class="event-card__title">
                    {{ event.title }}
                </h4>
            </div>
            <button type="button" class="event-card__toggle">
                <i v-if="isOpen" class="bi bi-chevron-up"></i>
                <i v-else class="bi bi-chevron-down"></i>
            </button>
        </div>

        <Transition name="accordion">
            <div v-if="isOpen" class="event-card__content">
                <div class="event-meta">
                    <div class="event-card__datetime">
                        {{ event.display_start_date }}
                        •
                        {{ event.display_start_time }}
                        -
                        {{ event.display_end_time }}
                    </div>
                     <span class="event-card__badge mx-2" :class="badgeClass">
                        {{ activityType }}
                    </span>
                </div>

                <!-- <div v-if="event.home_team || event.away_team" class="event-card__teams">
                    <div class="team-name">
                        {{ event.home_team }}
                    </div>

                    <div class="team-divider">
                        VS
                    </div>

                    <div class="team-name">
                        {{ event.away_team }}
                    </div>
                </div> -->
                <div v-if="event.fixture" class="event-card__fixture">
                    <div class="info-item fixture-item">
                        <label>Fixture</label>
                        <span>{{ event.fixture }}</span>
                    </div>
                </div>
                <div v-if="event.competition || event.venue" class="event-card__info">
                    <div v-if="event.competition" class="info-item">
                        <label>Competition</label>
                        <span>{{ event.competition }}</span>
                    </div>

                    <div v-if="event.venue" class="info-item">
                        <label>Venue</label>
                        <span>{{ event.venue }}</span>
                    </div>
                </div>
                <div v-if="event.scout_focus_team" class="event-card__fixture">
                    <div class="info-item fixture-item">
                        <label>Scout Focus Team</label>
                        <span>
                            {{ event.scout_focus_team === 'Others'
                                ? event.other_focus_team
                                : event.scout_focus_team }}
                        </span>
                    </div>
                </div>

                <div v-if="event.scouts?.length" class="member-section">
                    <div class="member-header">
                        <div class="section-title">
                            Assigned Scouts
                        </div>

                        <span class="count-pill">
                            {{ event.scouts.length }} 
                        </span>
                    </div>

                    <div class="member-list">
                        <span v-for="(scout, index) in event.scouts" :key="scout.id" class="member-name">
                            {{ scout.selected_user_name }}
                            <span v-if="index < event.scouts.length - 1">, </span>
                        </span>
                    </div>
                </div>

               <!--  <div v-if="event.players?.length" class="member-section">
                    <div class="member-header">
                        <div class="section-title">
                            Selected Players
                        </div>

                        <span class="count-pill">
                            {{ event.players.length }} 
                        </span>
                    </div>
                    <div class="member-list">
                        <span v-for="player in event.players" :key="player.id" class="member-name">
                            {{ player.selected_player_name }}
                        </span>
                    </div>
                </div> -->
                <div v-if="event.note" class="event-card__notes">
                    <div class="section-title">
                        Notes
                    </div>
                    <p>
                        {{ event.note }}
                    </p>
                </div>
            </div>
        </Transition>
    </article>
</template>
<style scoped>
    .event-card{
        position:relative;
        overflow:hidden;
        background:#fff;
        border:1px solid #e5e7eb;
        border-radius:16px;
        padding:20px;
        box-shadow:0 1px 2px rgba(15,23,42,.04);
        transition:border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }

    .event-card::before{
        content:"";
        position:absolute;
        inset:0 0 auto;
        height:4px;
        background:linear-gradient(90deg,#2563eb,#14b8a6);
    }

    .event-card:hover{
        border-color:#cbd5e1;
        box-shadow:0 16px 32px rgba(15,23,42,.08);
        transform:translateY(-2px);
    }

    .event-card__header{
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:16px;
    }

    .event-card__title{
        margin: 0px;
        color:#111827;
        font-size: 19px;
        font-weight: 700;
        line-height:1.25;
    }

    .event-card__badge{
        display:inline-flex;
        align-items:center;
        max-width:100%;
        padding:8px 12px;
        border-radius:999px;
        background:#eef2ff;
        color:#3730a3;
        font-size:12px;
        font-weight:800;
        letter-spacing:.04em;
        line-height:1;
        text-transform:uppercase;
    }

    .event-card__datetime{
        /*margin-top: 3px;*/
        display:inline-flex;
        align-items:center;
        max-width:100%;
        padding:8px 12px;
        border-radius:999px;
        border:1px solid #dbeafe;
        background:#eff6ff;
        color:#1e40af;
        font-size:12px;
        font-weight:700;
        line-height:1.35;
    }

    .event-meta{
        display:flex;
        flex-wrap:wrap;
        gap:10px;
        margin-bottom:16px;
    }

    .event-card__teams{
        margin-top:16px;
        display:grid;
        grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);
        align-items:center;
        gap:12px;
        border:1px solid #e2e8f0;
        border-radius:14px;
        background:linear-gradient(180deg,#f8fafc,#fff);
        padding:9px 14px;
    }

    .team-name{
        min-width:0;
        color:#0f172a;
        font-size:17px;
        font-weight:800;
        line-height:1.25;
        overflow-wrap:anywhere;
    }

    .team-name:last-child{
        text-align:right;
    }

    .team-divider{
        width:40px;
        height:40px;
        border-radius:50%;
        background:#fff;
        border:1px solid #dbeafe;
        box-shadow:0 6px 14px rgba(37,99,235,.12);
        display:flex;
        align-items:center;
        justify-content:center;
        color:#2563eb;
        font-size:11px;
        font-weight:900;
        letter-spacing:.04em;
    }

    .event-card__info{
        margin-top:16px;
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:10px;
    }

    .info-item{
        display:flex;
        flex-direction:column;
        gap:5px;
        min-width:0;
        padding:12px 14px;
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#f8fafc;
    }

    .info-item label{
        color:#64748b;
        font-size:10px;
        font-weight:800;
        letter-spacing:.06em;
        line-height:1;
        text-transform:uppercase;
    }

    .info-item span{
        color:#1f2937;
        font-size:13px;
        font-weight:700;
        line-height:1.35;
        word-break:break-word;
    }

    .event-card__counts{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
        margin-top:16px;
    }

    .count-pill{
        display:inline-flex;
        align-items:center;
        padding:7px 12px;
        border:1px solid #ddd6fe;
        border-radius:999px;
        background:#f5f3ff;
        color:#5b21b6;
        font-size:12px;
        font-weight:800;
        line-height:1;
    }

    .member-section{
        margin-top:18px;
    }

    .section-title{
        margin-bottom:9px;
        color:#64748b;
        font-size:11px;
        font-weight:900;
        letter-spacing:.06em;
        line-height:1;
        text-transform:uppercase;
    }

    .member-list{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
    }

    .member-chip{
        display:inline-flex;
        align-items:center;
        max-width:100%;
        padding:7px 11px;
        border-radius:999px;
        font-size:12px;
        font-weight:700;
        line-height:1.2;
        overflow-wrap:anywhere;
    }

    .member-chip.scout{
        background:#eff6ff;
        border:1px solid #bfdbfe;
        color:#1d4ed8;
    }

    .member-chip.player{
        background:#ecfdf5;
        border:1px solid #a7f3d0;
        color:#047857;
    }

    .event-card__notes{
        margin-top:18px;
        padding:14px;
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#f8fafc;
    }

    .event-card__notes p{
        margin:0;
        color:#475569;
        font-size:13px;
        line-height:1.6;
    }

    .event-card__edit{
        flex:0 0 auto;
        border:1px solid #dbeafe;
        background:#fff;
        border-radius:10px;
        padding:8px 14px;
        color:#1d4ed8;
        font-size:13px;
        font-weight:800;
        line-height:1;
        box-shadow:0 1px 2px rgba(15,23,42,.04);
        cursor:pointer;
        transition:background .2s ease, border-color .2s ease, color .2s ease, box-shadow .2s ease;
    }

    .event-card__edit:hover{
        border-color:#93c5fd;
        background:#eff6ff;
        box-shadow:0 8px 16px rgba(37,99,235,.1);
    }

    .event-card__edit:focus-visible{
        outline:3px solid rgba(37,99,235,.2);
        outline-offset:2px;
    }

    @media (max-width:640px){
        .event-card{
            padding:18px;
            border-radius:14px;
        }

        .event-card__header{
            flex-direction:column;
            gap:12px;
        }

        .event-card__edit{
            width:100%;
            justify-content:center;
        }

        .event-card__datetime{
            width:100%;
            border-radius:12px;
        }

        .event-card__teams,
        .event-card__info{
            grid-template-columns:1fr;
        }

        .team-name,
        .team-name:last-child{
            text-align:center;
        }

        .team-divider{
            justify-self:center;
            width:36px;
            height:36px;
        }
    }

    .event-card__header{
        cursor:pointer;
    }

    .event-card__content{
        margin-top:16px;
    }

    .event-card--open{
        border-color:#3b82f6;
    }

    .event-title-wrap{
        display:flex;
        align-items:center;
        gap:10px;
        flex-wrap:wrap;
    }

    .accordion-enter-active,
    .accordion-leave-active{
        transition:all .25s ease;
        overflow:hidden;
    }

    .accordion-enter-from,
    .accordion-leave-to{
        opacity:0;
        max-height:0;
    }

    .accordion-enter-to,
    .accordion-leave-from{
        opacity:1;
        max-height:1000px;
    }

    .member-list{
        margin-top:6px;
    }

    .member-name{
        color:#334155;
        font-size:14px;
        font-weight:500;
        line-height:1.6;
    }

    .member-header{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        margin-bottom:10px;
    }

    .section-title{
        margin:0;
        color:#64748b;
        font-size:11px;
        font-weight:900;
        letter-spacing:.08em;
        text-transform:uppercase;
    }

    .count-pill{
        display:inline-flex;
        align-items:center;
        padding:4px 10px;
        border-radius:999px;
        border:1px solid #ddd6fe;
        background:#f5f3ff;
        color:#6d28d9;
        font-size:11px;
        font-weight:800;
        line-height:1;
    }

    .event-card__toggle{
        width:36px;
        height:36px;
        border:none;
        border-radius:10px;
        background:#f8fafc;
        color:#64748b;
        display:flex;
        align-items:center;
        justify-content:center;
        cursor:pointer;
        transition:all .2s ease;
    }

    .event-card__toggle:hover{
        background:#eff6ff;
        color:#2563eb;
    }

    .event-card__toggle i{
        font-size:18px;
        font-weight:700;
    }

    .event-card__fixture {
        margin-top: 16px;
    }

    .event-card__fixture .info-item {
        width: 100%;
    }

    .fixture-item {
        grid-column: 1 / -1;
    }
</style>
