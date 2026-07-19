<script setup>
    const props = defineProps({
        event: {
            type: Object,
            required: true
        }
    })

    const activityTypes = {
        1: 'Live Match',
        2: 'Video Analysis',
        3: 'Trials',
        4: 'Training Session'
    }

    const getActivityType = (event) => {
        return (
            activityTypes[Number(event.activity_type_id)] ||
            event.activity_type_other ||
            'Other'
        )
    }

    const formatDate = (date) => {
        if (!date) return ''

        return new Date(date).toLocaleDateString('en-US', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        })
    }

    const formatTime = (time) => {
        if (!time) return ''

        const [hours, minutes] = time.split(':')
        const d = new Date()
        d.setHours(hours)
        d.setMinutes(minutes)

        return d.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit'
        })
    }
</script>

<template>
    <article class="fixture-card">
        <div class="fixture-card__header">
            <span class="fixture-badge" :class="'activity-' + event.activity_type_id">
                {{ getActivityType(event) }}
            </span>

            <span class="fixture-date">
                {{ formatDate(event.start_date) }}
            </span>
        </div>

        <h5 class="fixture-title">
            {{ event.title }}
        </h5>

        <p v-if="event.competition" class="fixture-competition">
            {{ event.competition }}
        </p>

        <div v-if="event.home_team || event.away_team" class="fixture-match">
            <div class="team">
                {{ event.home_team || 'TBD' }}
            </div>

            <div class="vs">
                VS
            </div>

            <div class="team">
                {{ event.away_team || 'TBD' }}
            </div>
        </div>

        <div class="fixture-footer">
            <span>{{ event.venue }}</span>

            <span>
                {{ formatTime(event.start_time) }}
            </span>
        </div>

        <div v-if="event.note" class="fixture-note">
            {{ event.note }}
        </div>
    </article>
</template>

<style scoped>
    .fixture-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 16px;
        transition: all .2s ease;
    }

    .fixture-card:hover {
        border-color: #dbeafe;
        box-shadow: 0 4px 14px rgba(37, 99, 235, .08);
    }

    .fixture-card__header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }

    .fixture-badge {
        padding: 5px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
    }

    .activity-1 {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .activity-2 {
        background: #ede9fe;
        color: #7c3aed;
    }

    .activity-3 {
        background: #fef3c7;
        color: #b45309;
    }

    .activity-4 {
        background: #dcfce7;
        color: #15803d;
    }

    .fixture-date {
        font-size: 12px;
        color: #64748b;
    }

    .fixture-title {
        margin: 0;
        font-size: 18px;
        font-weight: 700;
        color: #111827;
    }

    .fixture-competition {
        margin: 4px 0 12px;
        font-size: 13px;
        color: #64748b;
    }

    .fixture-match {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        align-items: center;
        gap: 16px;
        padding: 14px;
        margin-bottom: 12px;
        background: #f8fafc;
        border-radius: 12px;
    }

    .team {
        text-align: center;
        font-size: 15px;
        font-weight: 700;
        color: #111827;
    }

    .vs {
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: #2563eb;
        color: #fff;
        font-size: 11px;
        font-weight: 700;
    }

    .fixture-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: 10px;
        border-top: 1px solid #f1f5f9;
        font-size: 13px;
        color: #64748b;
    }

    .fixture-note {
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid #f1f5f9;
        font-size: 13px;
        line-height: 1.5;
        color: #475569;
    }

    @media (max-width: 576px) {
        .fixture-match {
            gap: 10px;
            padding: 12px;
        }

        .team {
            font-size: 13px;
        }

        .vs {
            width: 32px;
            height: 32px;
            font-size: 10px;
        }
    }
</style>