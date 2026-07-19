<script setup>
    import { computed, ref } from 'vue';
    import VueDatePicker from '@vuepic/vue-datepicker';
    import '@vuepic/vue-datepicker/dist/main.css';

    const props = defineProps({
        filters: {
            type: Object,
            required: true,
        },
        competitions: {
            type: Array,
            default: () => [],
        },
        scouts: {
            type: Array,
            default: () => [],
        }, 
        isManager: {
            type: Boolean,
            default: false,
        },

    });

    const emit = defineEmits(['apply', 'reset', 'update:filters']);

    const isOpen = ref(false);
    const competitionSearch = ref('');

    const matchStatusOptions = [
        { value: 1, label: 'Upcoming' },
        { value: 2, label: 'Ongoing' },
        { value: 3, label: 'Completed' },
    ];

    const responseStatusOptions = [
        { value: 3, label: 'Approved' },
        { value: 2, label: 'Awaiting Review' },
        { value: 4, label: 'Need Revision' },
        { value: 1, label: 'Rejected' },
    ];

    const formatDate = (date) => {
        if (!date) return '';

        const dateObj = date instanceof Date ? date : new Date(date);
        if (Number.isNaN(dateObj.getTime())) return '';

        return dateObj.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
        });
    };

    const updateFilter = (key, value) => {
        emit('update:filters', {
            ...props.filters,
            [key]: value,
        });
    };

    const getArrayFilter = (key) => {
        const value = props.filters[key];
        return Array.isArray(value) ? value : [];
    };

    const toggleArrayFilter = (key, value) => {
        const current = getArrayFilter(key);
        const normalizedValue = Number(value);
        const next = current.map(Number).includes(normalizedValue)
            ? current.filter((item) => Number(item) !== normalizedValue)
            : [...current, normalizedValue];

        updateFilter(key, next);
    };

    const removeArrayFilter = (key, value) => {
        updateFilter(key, getArrayFilter(key).filter((item) => Number(item) !== Number(value)));
    };

    const optionLabel = (options, value) => {
        return options.find((option) => Number(option.value) === Number(value))?.label || value;
    };

    const selectedCompetition = computed(() => {
        return props.competitions.find((competition) => {
            return String(competition.value) === String(props.filters.competition);
        });
    });

    const filteredCompetitions = computed(() => {
        const term = competitionSearch.value.toLowerCase();

        return props.competitions.filter((competition) => {
            return competition.label.toLowerCase().includes(term);
        });
    });

    const selectedScout = computed(() => {
        return props.scouts.find((scout) => String(scout.value) === String(props.filters.scout_id));
    });

    const chips = computed(() => {
        const activeChips = [];

        getArrayFilter('status').forEach((status) => {
            activeChips.push({
                key: `status-${status}`,
                label: `Status: ${optionLabel(matchStatusOptions, status)}`,
                remove: () => removeArrayFilter('status', status),
            });
        });

        getArrayFilter('response_status').forEach((status) => {
            activeChips.push({
                key: `response-${status}`,
                label: optionLabel(responseStatusOptions, status),
                remove: () => removeArrayFilter('response_status', status),
            });
        });

        if (props.filters.start_date || props.filters.end_date) {
            activeChips.push({
                key: 'date-range',
                label: `${formatDate(props.filters.start_date) || 'Start'} - ${formatDate(props.filters.end_date) || 'End'}`,
                remove: () => {
                    emit('update:filters', {
                        ...props.filters,
                        start_date: '',
                        end_date: '',
                    });
                },
            });
        }

        if (props.filters.competition) {
            activeChips.push({
                key: 'competition',
                label: `Competition: ${selectedCompetition.value?.label || props.filters.competition}`,
                remove: () => updateFilter('competition', ''),
            });
        }

        if (props.filters.scout_id) {
            activeChips.push({
                key: 'scout',
                label: `Scout: ${selectedScout.value?.label || props.filters.scout_id}`,
                remove: () => updateFilter('scout_id', ''),
            });
        }

        if (props.filters.venue) {
            activeChips.push({
                key: 'venue',
                label: `Venue: ${props.filters.venue}`,
                remove: () => updateFilter('venue', ''),
            });
        }

        return activeChips;
    });

    const activeFilterCount = computed(() => chips.value.length);
</script>

<template>
    <div class="matches-filter">
        <button
            class="matches-filter__toggle"
            type="button"
            :aria-expanded="isOpen"
            @click="isOpen = !isOpen"
        >
            <span class="matches-filter__toggle-title">
                <i class="bi bi-funnel"></i>
                Filters
                <span v-if="activeFilterCount" class="matches-filter__badge">{{ activeFilterCount }}</span>
            </span>
            <i class="bi bi-chevron-down matches-filter__chevron" :class="{ 'is-open': isOpen }"></i>
        </button>

        <Transition name="matches-filter-panel">
            <div v-show="isOpen" class="matches-filter__panel">
                <div class="matches-filter__card">
                    <div class="matches-filter__grid">
                        <div class="matches-filter__field">
                            <label>Match Status</label>
                            <div class="matches-filter__multi">
                                <button
                                    v-for="option in matchStatusOptions"
                                    :key="option.value"
                                    type="button"
                                    class="matches-filter__option"
                                    :class="{ 'is-selected': getArrayFilter('status').map(Number).includes(option.value) }"
                                    @click="toggleArrayFilter('status', option.value)"
                                >
                                    <i class="bi bi-check2"></i>
                                    {{ option.label }}
                                </button>
                            </div>
                        </div>

                        <div class="matches-filter__field">
                            <label>Date Range</label>
                            <div class="matches-filter__date-range">
                                <VueDatePicker
                                    :model-value="filters.start_date"
                                    :format="formatDate"
                                    :enable-time-picker="false"
                                    auto-apply
                                    placeholder="From"
                                    :hide-input-icon="true"
                                    :teleport="true"
                                    input-class-name="matches-filter__date-input"
                                    :max-date="filters.end_date || null"
                                    @update:model-value="updateFilter('start_date', $event)"
                                />
                                <span>-</span>
                                <VueDatePicker
                                    :model-value="filters.end_date"
                                    :format="formatDate"
                                    :enable-time-picker="false"
                                    auto-apply
                                    placeholder="To"
                                    :hide-input-icon="true"
                                    :teleport="true"
                                    input-class-name="matches-filter__date-input"
                                    :min-date="filters.start_date || null"
                                    @update:model-value="updateFilter('end_date', $event)"
                                />
                            </div>
                        </div>

                        <div class="matches-filter__field">
                            <label>Competition</label>
                            <div class="matches-filter__search-select">
                                <input
                                    v-model="competitionSearch"
                                    class="form-control form-control-sm"
                                    type="search"
                                    placeholder="Search competition"
                                />
                                <select
                                    class="form-select form-select-sm"
                                    :value="filters.competition || ''"
                                    @change="updateFilter('competition', $event.target.value)"
                                >
                                    <option value="">All competitions</option>
                                    <option
                                        v-for="competition in filteredCompetitions"
                                        :key="competition.value"
                                        :value="competition.label"
                                    >
                                        {{ competition.label }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="matches-filter__field">
                            <label>Response Status</label>
                            <div class="matches-filter__multi">
                                <button
                                    v-for="option in responseStatusOptions"
                                    :key="option.value"
                                    type="button"
                                    class="matches-filter__option"
                                    :class="{ 'is-selected': getArrayFilter('response_status').map(Number).includes(option.value) }"
                                    @click="toggleArrayFilter('response_status', option.value)"
                                >
                                    <i class="bi bi-check2"></i>
                                    {{ option.label }}
                                </button>
                            </div>
                        </div>

                        <div class="matches-filter__field" v-if="isManager">
                            <label>Assigned Scout</label>
                            <select
                                class="form-select form-select-sm"
                                :value="filters.scout_id || ''"
                                @change="updateFilter('scout_id', $event.target.value)"
                            >
                                <option value="">All scouts</option>
                                <option v-for="scout in scouts" :key="scout.id" :value="scout.id">
                                    {{ scout.name }}
                                </option>
                            </select>
                        </div>

                        <div class="matches-filter__field">
                            <label>Venue</label>
                            <input
                                class="form-control form-control-sm"
                                type="search"
                                placeholder="Venue"
                                :value="filters.venue || ''"
                                @input="updateFilter('venue', $event.target.value)"
                            />
                        </div>
                    </div>

                    <div v-if="chips.length" class="matches-filter__chips" aria-label="Active filters">
                        <button
                            v-for="chip in chips"
                            :key="chip.key"
                            type="button"
                            class="matches-filter__chip"
                            @click="chip.remove"
                        >
                            {{ chip.label }}
                            <i class="bi bi-x"></i>
                        </button>
                    </div>

                    <div class="matches-filter__actions">
                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="emit('reset')">
                            Reset Filters
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" @click="emit('apply')">
                            Apply Filters
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
    .matches-filter {
        display: grid;
        gap: 10px;
    }

    .matches-filter__toggle,
    .matches-filter__card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
    }

    .matches-filter__toggle {
        min-height: 46px;
        padding: 0 16px;
        color: #111827;
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-weight: 700;
    }

    .matches-filter__toggle-title {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .matches-filter__badge {
        min-width: 22px;
        height: 22px;
        padding: 0 7px;
        border-radius: 999px;
        background: #2563eb;
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
    }

    .matches-filter__chevron {
        transition: transform 250ms ease;
    }

    .matches-filter__chevron.is-open {
        transform: rotate(180deg);
    }

    .matches-filter-panel-enter-active,
    .matches-filter-panel-leave-active {
        transition: max-height 250ms ease, opacity 250ms ease;
        overflow: hidden;
    }

    .matches-filter-panel-enter-from,
    .matches-filter-panel-leave-to {
        max-height: 0;
        opacity: 0;
    }

    .matches-filter-panel-enter-to,
    .matches-filter-panel-leave-from {
        max-height: 720px;
        opacity: 1;
    }

    .matches-filter__card {
        padding: 16px;
    }

    .matches-filter__grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
    }

    .matches-filter__field {
        min-width: 0;
        display: grid;
        align-content: start;
        gap: 7px;
    }

    .matches-filter__field label {
        margin: 0;
        color: #4b5563;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .matches-filter__multi {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
    }

    .matches-filter__option {
        min-height: 31px;
        padding: 5px 9px;
        border: 1px solid #d1d5db;
        border-radius: 999px;
        background: #fff;
        color: #374151;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
    }

    .matches-filter__option i {
        display: none;
    }

    .matches-filter__option.is-selected {
        border-color: #2563eb;
        background: #eff6ff;
        color: #1d4ed8;
    }

    .matches-filter__option.is-selected i {
        display: inline-block;
    }

    .matches-filter__date-range,
    .matches-filter__search-select {
        display: grid;
        gap: 7px;
    }

    .matches-filter__date-range {
        grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
        align-items: center;
    }

    .matches-filter__date-range span {
        color: #6b7280;
        font-size: 12px;
        font-weight: 700;
    }

    .matches-filter__chips {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        padding-top: 14px;
        margin-top: 14px;
        border-top: 1px solid #eef2f7;
    }

    .matches-filter__chip {
        min-height: 30px;
        padding: 5px 9px 5px 11px;
        border: 1px solid #bfdbfe;
        border-radius: 999px;
        background: #eff6ff;
        color: #1e40af;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        font-weight: 700;
    }

    .matches-filter__chip i {
        font-size: 14px;
    }

    .matches-filter__actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        padding-top: 14px;
        margin-top: 14px;
        border-top: 1px solid #eef2f7;
    }

    :deep(.matches-filter__date-input) {
        min-height: 31px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        padding: 4px 8px;
        font-size: 14px;
    }

    @media (max-width: 991px) {
        .matches-filter__grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 575px) {
        .matches-filter__grid {
            grid-template-columns: 1fr;
        }

        .matches-filter__actions {
            flex-direction: column-reverse;
        }

        .matches-filter__actions .btn {
            width: 100%;
        }
    }
</style>
