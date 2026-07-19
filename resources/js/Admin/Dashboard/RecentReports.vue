<script setup>
    import { ref } from 'vue';
    const props = defineProps(['player_reports']);

</script>
<template>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <ul class="list-group list-group-flush card-manual">
                <li class="list-group-item d-flex align-items-center gap-3 py-3 px-4 report-item" v-for="(report, index) in props.player_reports" :key="index">
                    <div class="card-body-manual" :class="{
                        'card-earmark-color1': report.status !== 'draft', 
                        'card-earmark-color2': report.status == 'draft' 
                    }">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate">
                            {{ report.template_name }} — {{ report.player_name }}
                        </div>

                        <div class="text-muted small">
                            Scout: {{ report.scout_name }} · {{ report.day_diff }}
                        </div>
                    </div>
                    <span class="badge rounded-pill" :class="report.status == 'draft' ? 'bg-warning text-dark' : 'bg-success'" >
                        {{ report.status == 'draft' ? 'Draft' : 'Done' }}
                    </span>
                </li>
            </ul>
        </div>
    </div>

    <!-- <div class="card-body p-0">
        <ul class="list-group list-group-flush" style="border-radius:0 0 var(--r-md) var(--r-md);">
            <li class="list-group-item d-flex align-items-center gap-3 py-3 px-4" style="border-color:var(--border);">
                <div style="width:34px;height:34px;border-radius:var(--r-sm);background:#eff6ff;display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:16px;flex-shrink:0;">
                    <i class="bi bi-file-earmark-text"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="fs-13 fw-600 text-truncate">Performance Assessment — Sunil Chhetri</div>
                    <div class="fs-12 text-muted">Scout: Ramesh Kumar · 2 days ago</div>
                </div>
                <span class="badge badge-success">Done</span>
            </li>
            <li class="list-group-item d-flex align-items-center gap-3 py-3 px-4" style="border-color:var(--border);">
                <div style="width:34px;height:34px;border-radius:var(--r-sm);background:#fffbeb;display:flex;align-items:center;justify-content:center;color:#d97706;font-size:16px;flex-shrink:0;">
                    <i class="bi bi-file-earmark-text"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="fs-13 fw-600 text-truncate">Scouting Report — Anirudh Thapa</div>
                    <div class="fs-12 text-muted">Scout: Priya Sharma · 5 days ago</div>
                </div>
                <span class="badge badge-warning">Draft</span>
            </li>
            <li class="list-group-item d-flex align-items-center gap-3 py-3 px-4" style="border-color:var(--border);">
                <div style="width:34px;height:34px;border-radius:var(--r-sm);background:#f0fdf4;display:flex;align-items:center;justify-content:center;color:#16a34a;font-size:16px;flex-shrink:0;">
                    <i class="bi bi-file-earmark-text"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="fs-13 fw-600 text-truncate">Biannual Fitness Assessment</div>
                    <div class="fs-12 text-muted">Scout: Vinod Nair · 1 week ago</div>
                </div>
                <span class="badge badge-success">Done</span>
            </li>
        </ul>
    </div> -->
</template>
<style scoped>
   .card-manual {
        border-radius: 0 0 var(--r-md) var(--r-md);
    }

    .card-body-manual {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        /*color: #2563eb;*/
        font-size: 16px;
        flex-shrink: 0;
    }

    .card-earmark-color1{
        background:#f0fdf4;
        color: #16a34a; 
    }
    .card-earmark-color2{
       color: #d97706; 
       background: #fffbeb;
    }

    .report-item {
        transition: all 0.2s ease;
    }

    .report-item:hover {
        background: #f9fafb;
        transform: translateX(2px);
    }

    .min-w-0 {
        min-width: 0;
    }
</style>
