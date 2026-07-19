<script setup>
    import {computed, ref, watch, nextTick, onMounted, onBeforeUnmount} from "vue";
    import DBService from "@/Service/Utils/DBService.js";

    const emit = defineEmits(["update:modelValue", "selected"]);

    const props = defineProps({
        modelValue: {
            type: [Number, String],
            default: null
        },
        label: {
            type: String,
            default: "Player"
        },
        placeholder: {
            type: String,
            default: "Search by Name / AIFF ID"
        },
        required: {
            type: Boolean,
            default: false
        },
        endpoint: {
            type: String,
            default: "/api/player/search"
        },
        itemEndpoint: {
            type: String,
            default: "/api/player"
        }
    });

    const root = ref(null);
    const dropdown = ref(null);
    const searchInput = ref(null);

    const search = ref("");
    const loading = ref(false);
    const loadingMore = ref(false);
    const dropdownVisible = ref(false);
    const highlightedIndex = ref(-1);
    const players = ref([]);
    const selectedPlayer = ref(null);
    const page = ref(1);
    const hasMore = ref(true);
    const searchCompleted = ref(false);
    const s3_url_link = import.meta.env.VITE_S3_URL

    const defaultAvatar = "/images/default-user.png";
    const listboxId = `player-search-listbox-${Math.random().toString(36).slice(2)}`;

    let debounceTimer = null;
    let searchRequestId = 0;
    let selectedPlayerRequestId = 0;

    const canShowNoResults = computed(() => {
        return searchCompleted.value && search.value.trim().length >= 2 && !loading.value && !players.value.length;
    });

    const highlightedOptionId = computed(() => {
        const player = players.value[highlightedIndex.value];

        return player ? `${listboxId}-option-${player.id}` : undefined;
    });

    function buildSearchUrl(searchText, pageNumber) {
        const url = new URL(props.endpoint, window.location.origin);

        url.searchParams.set("search", searchText);
        url.searchParams.set("page", pageNumber);

        return url.pathname + url.search;
    }

    function normalizePlayerRows(response) {
        const payload = response?.data ?? response;
        const rows = payload?.data ?? payload?.players ?? payload?.results ?? payload;

        return Array.isArray(rows) ? rows : [];
    }

    function normalizeHasMore(response) {
        const payload = response?.data ?? response;

        if (typeof payload?.has_more === "boolean") {
            return payload.has_more;
        }

        if (typeof payload?.hasMore === "boolean") {
            return payload.hasMore;
        }

        if (payload?.next_page_url !== undefined) {
            return Boolean(payload.next_page_url);
        }

        if (payload?.current_page !== undefined && payload?.last_page !== undefined) {
            return Number(payload.current_page) < Number(payload.last_page);
        }

        return false;
    }

    function normalizeSelectedPlayer(response) {
        const payload = response?.data ?? response;

        return payload?.player ?? payload;
    }

    function resetSearchResults() {
        players.value = [];
        page.value = 1;
        hasMore.value = true;
        highlightedIndex.value = -1;
        searchCompleted.value = false;
    }

    function openDropdown() {
        if (selectedPlayer.value) return;
        if (search.value.trim().length >= 2 &&(loading.value || players.value.length || canShowNoResults.value)) {
            dropdownVisible.value = true;
        }
    }

    function closeDropdown() {
        dropdownVisible.value = false;
    }

    function clearSelection() {
        selectedPlayer.value = null;
        search.value = "";
        resetSearchResults();
        closeDropdown();
        emit("update:modelValue", null);
        emit("selected", null);

        nextTick(() => {
            searchInput.value?.focus();
        });
    }

    function handleImageError(event) {
        if (event.target.getAttribute("src") !== defaultAvatar) {
            event.target.src = defaultAvatar;
        }
    }

    function scrollIntoView() {
        nextTick(() => {
            const activeItem = dropdown.value?.querySelector(".player-item.active");

            activeItem?.scrollIntoView({
                block: "nearest"
            });
        });
    }

    function handleKeyDown(event) {
        if (event.key === "Escape") {
            closeDropdown();
            highlightedIndex.value = -1;
            return;
        }

        if (event.key === "Tab") {
            if (
                dropdownVisible.value &&
                highlightedIndex.value >= 0 &&
                highlightedIndex.value < players.value.length
            ) {
                selectPlayer(players.value[highlightedIndex.value]);
            }
            closeDropdown();
            return;
        }

        if (!dropdownVisible.value) {
            if (event.key === "ArrowDown" && players.value.length) {
                event.preventDefault();
                openDropdown();
                highlightedIndex.value = Math.max(highlightedIndex.value, 0);
                scrollIntoView();
            }
            return;
        }

        switch (event.key) {
            case "ArrowDown":
                event.preventDefault();
                if (!players.value.length) return;
                highlightedIndex.value = highlightedIndex.value < players.value.length - 1 ? highlightedIndex.value + 1 : 0;
                scrollIntoView();
                break;

            case "ArrowUp":
                event.preventDefault();
                if (!players.value.length) return;
                highlightedIndex.value = highlightedIndex.value > 0 ? highlightedIndex.value - 1 : players.value.length - 1;
                scrollIntoView();
                break;

            case "Enter":
                event.preventDefault();
                if (
                    highlightedIndex.value >= 0 &&
                    highlightedIndex.value < players.value.length
                ) {
                    selectPlayer(players.value[highlightedIndex.value]);
                }
                break;
            default:
                break;
        }
    }

    async function fetchPlayers(reset = true) {
        const trimmedSearch = search.value.trim();

        if (trimmedSearch.length < 2) {
            resetSearchResults();
            loading.value = false;
            loadingMore.value = false;
            return;
        }

        const requestId = ++searchRequestId;

        if (reset) {
            resetSearchResults();
            loading.value = true;
            loadingMore.value = false;
            dropdownVisible.value = true;
        } else {
            loadingMore.value = true;
        }

        try {
            const response = await DBService.getData(buildSearchUrl(trimmedSearch, page.value));

            if (requestId !== searchRequestId || trimmedSearch !== search.value.trim()) {
                return;
            }

            const rows = normalizePlayerRows(response);

            players.value = reset ? rows : [...players.value, ...rows];
            hasMore.value = normalizeHasMore(response);
            searchCompleted.value = true;
            dropdownVisible.value = Boolean(players.value.length || (reset && !rows.length));

            if (reset) {
                highlightedIndex.value = rows.length ? 0 : -1;
            }
        } catch (error) {
            if (requestId === searchRequestId) {
                console.error(error);

                if (!reset && page.value > 1) {
                    page.value--;
                }

                if (!players.value.length) {
                    closeDropdown();
                }
            }
        } finally {
            if (requestId === searchRequestId) {
                loading.value = false;
                loadingMore.value = false;
            }
        }
    }

    async function handleScroll(event) {
        if (loading.value || loadingMore.value || !hasMore.value) {
            return;
        }

        const element = event.target;
        const distanceFromBottom =
            element.scrollHeight - Math.ceil(element.scrollTop + element.clientHeight);

        if (distanceFromBottom > 24) {
            return;
        }

        page.value++;
        await fetchPlayers(false);
    }

    function selectPlayer(player) {
        selectedPlayer.value = player;
        search.value = "";
        resetSearchResults();
        closeDropdown();
        emit("update:modelValue", player.id);
        emit("selected", player);
    }

    function handleClickOutside(event) {
        if (!root.value?.contains(event.target)) {
            closeDropdown();
        }
    }

    watch(search, (value, oldValue) => {
        clearTimeout(debounceTimer);

        if (selectedPlayer.value) {
            return;
        }

        const trimmedValue = value.trim();
        const previousTrimmedValue = oldValue?.trim() ?? "";

        if (trimmedValue === previousTrimmedValue) {
            return;
        }

        searchRequestId++;

        resetSearchResults();
        loadingMore.value = false;

        if (trimmedValue.length < 2) {
            loading.value = false;
            closeDropdown();
            return;
        }

        debounceTimer = setTimeout(() => {
            fetchPlayers(true);
        }, 350);
    });

    watch(
        () => props.modelValue,
        async (value) => {
            const requestId = ++selectedPlayerRequestId;

            if (!value) {
                selectedPlayer.value = null;
                return;
            }

            if (selectedPlayer.value && Number(selectedPlayer.value.id) === Number(value)) {
                return;
            }

            try {
                const response = await DBService.getData(`${props.itemEndpoint}/${value}`);

                if (requestId === selectedPlayerRequestId) {
                    selectedPlayer.value = normalizeSelectedPlayer(response);
                }
            } catch (error) {
                if (requestId === selectedPlayerRequestId) {
                    console.error(error);
                }
            }
        },
        {immediate: true}
    );

    onMounted(() => {
        document.addEventListener("click", handleClickOutside);
    });

    onBeforeUnmount(() => {
        clearTimeout(debounceTimer);
        searchRequestId++;
        selectedPlayerRequestId++;
        document.removeEventListener("click", handleClickOutside);
    });
</script>

<template>
    <div ref="root" class="player-search">
        <label v-if="label" class="form-label fw-semibold" >
            {{ label }}
            <span v-if="required" class="text-danger">
                *
            </span>
        </label>

        <!-- Selected Player -->
        <div v-if="selectedPlayer" class="selected-player">
            <div class="d-flex align-items-center">
                <img :src="s3_url_link + selectedPlayer.photo || defaultAvatar" class="player-avatar me-3" @error="handleImageError">
                <div>
                    <div class="fw-bold">
                        {{ selectedPlayer.name }}
                    </div>
                    <div class="small text-muted">
                        {{ selectedPlayer.club_name }}
                    </div>

                    <div class="small">
                        <span class="badge bg-primary">
                            {{ selectedPlayer.position }}
                        </span>
                    </div>
                </div>
            </div>

            <button class="btn btn-light btn-sm" @click="clearSelection">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Search Box -->
        <div v-else class="position-relative">
            <input ref="searchInput" v-model="search" type="text" class="form-control" :placeholder="placeholder" autocomplete="off" role="combobox" aria-autocomplete="list" :aria-expanded="dropdownVisible" :aria-controls="listboxId" :aria-activedescendant="highlightedOptionId" @focus="openDropdown" @keydown="handleKeyDown">

            <!-- Loading -->
            <div v-if="loading && !players.length && !dropdownVisible" class="search-loader">
                <div class="spinner-border spinner-border-sm text-primary"></div>
            </div>

            <!-- Dropdown -->
            <div v-if="dropdownVisible" :id="listboxId" ref="dropdown" class="search-dropdown" role="listbox" @scroll="handleScroll">
                <div v-if="loading && !players.length">
                    <div v-for="i in 5" :key="i" class="loading-item"></div>
                </div>

                <div v-else-if="canShowNoResults" class="empty-result">
                    No Player Found
                </div>

                <div v-for="(player,index) in players" :id="`${listboxId}-option-${player.id}`" :key="player.id" class="player-item" :class="{ active:index===highlightedIndex }" role="option" :aria-selected="index===highlightedIndex" @mouseenter="highlightedIndex=index" @mousedown.prevent="selectPlayer(player)">
                    <img :src="s3_url_link + player.photo || defaultAvatar" class="player-avatar" @error="handleImageError">
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="player-name">
                                {{ player.name }}
                            </div>
                            <span class="badge bg-primary">
                                {{ player.position }}
                            </span>
                        </div>
                        <div class="small text-muted">
                            AIFF :
                            {{ player.aiff_id || '-' }}
                        </div>

                        <div class="small text-muted">
                            {{ player.club_name || '-' }}
                        </div>

                        <div v-if="player.age" class="small text-muted">
                            Age :
                            {{ player.age }}
                        </div>
                    </div>
                </div>

                <div v-if="loadingMore" class="text-center py-2">
                    <div class="spinner-border spinner-border-sm text-primary"></div>
                </div>

                <div v-if="!loadingMore && !hasMore && players.length" class="text-center text-muted py-2">
                    End of Results
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>

    .player-search{
        position:relative;
        width:100%;
    }

    .player-item{
        display:flex;
        gap:14px;
        padding:12px;
        cursor:pointer;
        border-bottom:1px solid #f1f1f1;
        transition:.15s;
    }

    .player-item:hover{
        background:#f7fbff;
    }

    .player-item.active{
        background:#e8f2ff;
    }

    .player-name{
        font-size:15px;
        font-weight:600;
    }

    .player-avatar{
        width:48px;
        height:48px;
        border-radius:50%;
        object-fit:cover;
        border:2px solid #f2f2f2;
    }

    .player-dropdown::-webkit-scrollbar{
        width:7px;
    }

    .player-dropdown::-webkit-scrollbar-thumb{
        background:#d4d4d4;
        border-radius:20px;
    }

    .player-dropdown::-webkit-scrollbar-track{
        background:#f8f8f8;
    }

    .search-dropdown{
        position:absolute;
        left:0;
        right:0;
        top:100%;
        margin-top:4px;
        background:#fff;
        border:1px solid #dee2e6;
        border-radius:8px;
        max-height:300px;
        overflow:auto;
        z-index:1000;
        box-shadow:0 8px 20px rgba(0,0,0,.08);
    }

    .search-item{
        padding:12px 15px;
        cursor:pointer;
        transition:.15s;
    }

    .search-item:hover,
    .search-item.active{
        background:#f5f9ff;
    }

    .empty-result{
        padding:18px;
        text-align:center;
        color:#888;
    }

    .selected-player{
        display:flex;
        justify-content:space-between;
        align-items:center;
        padding:12px;
        border:1px solid #dee2e6;
        border-radius:10px;
        background:#fff;
    }

    .search-loader{
        position:absolute;
        top:50%;
        right:12px;
        transform:translateY(-50%);
    }

    .loading-item{
        height:60px;
        margin:8px;
        border-radius:8px;
        background:linear-gradient(90deg,#f1f1f1,#fafafa,#f1f1f1);
        animation:loading 1s infinite;
    }

    @keyframes loading{
        0%{background-position:-120px;}
        100%{background-position:220px;}
    }

    @media(max-width:768px){
        .player-avatar{
            width:40px;
            height:40px;
        }

        .player-name{
            font-size:14px;
        }

        .player-dropdown{
            max-height:280px;
        }
    }

</style>
