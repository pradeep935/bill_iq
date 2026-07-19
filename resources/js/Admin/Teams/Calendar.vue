<script setup>
    import { computed, ref, onMounted } from 'vue';
    import DBService from '@/Service/Utils/DBService';
    import EventCard from '@/Admin/Teams/EventCard.vue'
    const { team_id } = defineProps(['team_id']);

    onMounted(()=>{
        getTeamEvents();
    })

    const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const today = new Date();
    const currentMonth = ref(new Date(today.getFullYear(), today.getMonth(), 1));
    const selectedDate = ref(new Date(today.getFullYear(), today.getMonth(), today.getDate()));
    const timelineScroller = ref(null);
    const isDraggingTimeline = ref(false);
    const loading = ref(false);
    const processing = ref(false);

    const events = ref([]);

    const currentMonthLabel = computed(() =>
        currentMonth.value.toLocaleDateString('en-US', {
            month: 'long',
            year: 'numeric',
        })
    );

    const selectedDateLabel = computed(() =>
        selectedDate.value.toLocaleDateString('en-US', {
            weekday: 'long',
            month: 'long',
            day: 'numeric',
            year: 'numeric',
        })
    );

    const selectedDateEvents = computed(() => {
        const selectedKey = formatDateKey(selectedDate.value);
        return events.value.filter((event) => {
            return selectedKey >= event.start && selectedKey <= event.end;
        });
    });

    const calendarDays = computed(() => {
        const year = currentMonth.value.getFullYear();
        const month = currentMonth.value.getMonth();
        const firstDayOfMonth = new Date(year, month, 1);
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const leadingDays = firstDayOfMonth.getDay();
        const totalCells = Math.ceil((leadingDays + daysInMonth) / 7) * 7;

        return Array.from({ length: totalCells }, (_, index) => {
            const dayOffset = index - leadingDays + 1;
            const date = new Date(year, month, dayOffset);
            const dateKey = formatDateKey(date);
            const isCurrentMonth = date.getMonth() === month;

            return {
                date,
                dateKey,
                dayNumber: date.getDate(),
                isCurrentMonth,
                isToday: dateKey === formatDateKey(today),
                isSelected: dateKey === formatDateKey(selectedDate.value),
                events: events.value.filter((event) => {
                    return dateKey === event.start;
                }),
                hasEvent: events.value.some((event) => {
                    return dateKey >= event.start && dateKey <= event.end;
                }),
            };
        });
    });

    function formatDateKey(date) {
        return date.toISOString().split('T')[0];
    }

    function selectDate(date) {
        selectedDate.value = new Date(date);
    }

    function goToPreviousMonth() {
        currentMonth.value = new Date(currentMonth.value.getFullYear(), currentMonth.value.getMonth() - 1, 1);
    }

    function changeYear(val) {
        if (val === -1) {
            currentMonth.value = new Date(currentMonth.value.getFullYear() + val, currentMonth.value.getMonth() );
        } else{
            currentMonth.value = new Date(currentMonth.value.getFullYear() + val, currentMonth.value.getMonth() );
        }
    }

    function goToNextMonth() {
        currentMonth.value = new Date(currentMonth.value.getFullYear(), currentMonth.value.getMonth() + 1, 1);
    }

    function getTeamEvents(){
        loading.value = true;
        DBService.postData('/api/teams/get-team-events/' + team_id).then((data)=>{
            if(data.success){
                events.value = data.events.map(event => ({
                    ...event,
                    start: event.start_date,
                    end: event.end_date
                }));
            }
            loading.value = false;
        });
    };

    function formatTime(time) {
        return new Date(`1970-01-01T${time}`).toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    function addEvent(dataSet){
        
    };

    const activityTypes = {
        1: 'Live Match',
        2: 'Video Analysis',
        3: 'Trials',
        4: 'Training Session'
    };

    function getActivityType(event) {
        return (
            activityTypes[Number(event.activity_type_id)] ||
            event.activity_type_other ||
            'Other'
            )
    };
    
</script>
<template>
    <div class="calendar-wrapper">
        <div class="calendar-header">
            <div>
                <h3 class="calendar-title">{{ currentMonthLabel }}</h3>
                <p class="calendar-subtitle">Team activity timeline</p>
            </div>

            <div class="nav-buttons">
                <button type="button" class="nav-btn" @click="changeYear(-1)" aria-label="Previous year">
                    <i class="bi bi-chevron-double-left"></i>
                </button>
                <button type="button" class="nav-btn" @click="goToPreviousMonth" aria-label="Previous month">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <button type="button" class="nav-btn" @click="goToNextMonth" aria-label="Next month">
                    <i class="bi bi-chevron-right"></i>
                </button>
                <button type="button" class="nav-btn" @click="changeYear(1)" aria-label="Next year">
                    <i class="bi bi-chevron-double-right"></i>
                </button>
            </div>
        </div>
        <div class="timeline-container" ref="timelineScroller">
            <div class="events-row">
                <div v-for="day in calendarDays" :key="day.dateKey" class="event-column">
                    <div v-if="day.events.length">
                        <div v-for="event in day.events" :key="event.title" class="event-card">
                            {{ event.title }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="days-row">
                <button v-for="day in calendarDays" :key="day.dateKey" class="day-card" :class="{
                        selected: day.isSelected,
                        today: day.isToday,
                        faded: !day.isCurrentMonth
                    }" @click="selectDate(day.date)">
                    <span class="day-name">
                        {{ day.date.toLocaleDateString('en-US', { weekday: 'short' }) }}
                    </span>

                    <span class="day-number">
                        {{ day.dayNumber }}
                    </span>

                    <span class="day-month">
                        {{ day.date.toLocaleDateString('en-US', { month: 'short' }) }}
                    </span>
                    <span class="event-dot" :class="{ active: day.hasEvent }"></span>

                    <!-- <div style="height:100%; position: relative;">
                        <span class="al-btn" @click="addEvent(day.date)"><i class="bi bi-plus"></i> Add Event</span>
                    </div> -->
                </button>
            </div>
        </div>
        <div class="selected-panel">
            <div class="selected-header">
                <div>
                    <p class="label">Selected Date</p>
                    <h4>{{ selectedDateLabel }}</h4>
                </div>

                <span class="badge">
                    {{ selectedDateEvents.length }} events
                </span>
            </div>
            <div v-if="selectedDateEvents.length" class="event-list">
                <EventCard v-for="event in selectedDateEvents" :key="event.id" :event="event" />

             <!--    <div v-for="event in selectedDateEvents" :key="event.id" class="event-item" :class="event?.event_label?.toLowerCase()">
                    <div class="event-header">
                        <span class="event-title">{{ event?.title }}</span>
                        <span class="event-type">{{ event?.event_label }}</span>
                    </div>

                    <div class="event-time">
                        ⏱ {{ event.start_time }} - {{ event.end_time }}
                    </div>

                    <div v-if="event.note" class="event-note">
                        {{ event.note }}
                    </div>
                </div> -->
            </div>

            <div v-else class="empty-state">
                <div>
                    <i class="bi bi-calendar-day"></i>
                </div>
                <p>No events</p>
                <span>You're all clear</span>
            </div>
        </div>

    </div>
</template>
<style scoped>
    .calendar-wrapper {
        background: #fff;
        border-radius: 20px;
        padding: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.05);
    }

    /* Header */
    .calendar-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .calendar-title {
        font-size: 20px;
        font-weight: 700;
    }

    .calendar-subtitle {
        font-size: 13px;
        color: #777;
    }

    .nav-buttons button {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        border: 1px solid #ddd;
        background: white;
        cursor: pointer;
    }

    .nav-buttons button:hover {
        background: #f5f5f5;
    }

    /* Timeline */
    .timeline-container {
        overflow-x: auto;
    }

    .events-row,
    .days-row {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 10px;
    }

    /* Event */
    .event-card {
        background: white;
        padding: 6px 10px;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        font-size: 12px;
        margin-bottom: 8px;
        transition: 0.2s;
    }

    .event-card:hover {
        transform: translateY(-4px);
    }

    /* Day */
    .day-card {
        width: 100%;   /* REMOVE fixed width */
        padding: 10px;
        border-radius: 16px;
        border: 1px solid #eee;
        background: white;
        cursor: pointer;
        position: relative;
        transition: 0.2s;
        padding: 12px 10px 20px; 
    }

    .day-card:hover {
        transform: scale(1.05);
    }

    .day-card.selected {
        background: #e0f2fe;
        border-color: #38bdf8;
    }

    .day-card.today {
        background: #dcfce7;
    }

    .day-card.faded {
        opacity: 0.4;
    }

    .day-name {
        font-size: 11px;
        color: #999;
    }

    .day-number {
        font-size: 16px;
        font-weight: bold;
    }

    .day-month {
        font-size: 11px;
    }

    .event-dot {
        position: absolute;
        bottom: 6px;
        left: 50%;
        transform: translateX(-50%);
        
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #ddd;
    }

    .event-dot.active {
        background: #ef4444;
        box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
    }

    /* Selected panel */
    .selected-panel {
        margin-top: 20px;
        padding: 15px;
        border-radius: 16px;
        background: #f9fafb;
    }

    .selected-header {
        display: flex;
        justify-content: space-between;
    }

    .label {
        font-size: 11px;
        color: #999;
    }

    .badge {
        background: white;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 12px;
        color: #999;
    }

    /* Empty */
    .empty-state {
        text-align: center;
        margin-top: 15px;
        color: #888;
    }
    .event-list {
        margin-top: 12px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .event-item {
        background: white;
        padding: 12px 14px;
        border-radius: 12px;
        box-shadow: 0 6px 14px rgba(0,0,0,0.05);
        border-left: 4px solid #3b82f6;
        transition: 0.2s;
    }

    .event-item:hover {
        transform: translateY(-2px);
    }

    /* Header */
    .event-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .event-title {
        font-size: 14px;
        font-weight: 600;
        color: #111;
    }

    .event-type {
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 20px;
        background: #f1f5f9;
    }

    /* Time */
    .event-time {
        font-size: 12px;
        color: #666;
        margin-top: 4px;
    }

    /* Note */
    .event-note {
        font-size: 12px;
        color: #888;
        margin-top: 6px;
    }

    /* 🎨 Color by type */
    .event-item.practice {
        border-left-color: #3b82f6;
    }

    .event-item.game {
        border-left-color: #ef4444;
    }

    .al-btn {
        position: absolute;
        top: 6px;
        right: 6px;
        background: #73dad4;
        color: #fff;
        font-size: 10px;
        padding: 4px 8px;
        border-radius: 6px;
        cursor: pointer;

        opacity: 0;
        visibility: hidden;
        transform: translateY(-5px);
        transition: all 0.2s ease;
    }

    /* 👇 parent hover pe show */
    .day-card:hover .al-btn {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    .al-btn:hover {
        background: #4338ca;
    }

</style>