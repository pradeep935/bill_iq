<script setup>
    import { ref } from 'vue';
    import DBService from '@/Service/Utils/DBService';

    const { team_id } = defineProps(['team_id']);

    const processing = ref(false);

    function deleteTeam(){
        if (team_id > 0) {
            processing.value = true;

            bootbox.confirm("Are you sure you want to delete this team?", function(result){
                if (result) {
                    DBService.getData("/api/teams/delete-team/"+ team_id).then((data)=>{
                        processing.value = false;

                        if (data.success) {
                            window.location = data.url;
                        }

                        bootbox.alert(data.message);
                    });
                } else {
                    processing.value = false;
                }
            });
        }
    }
</script>
<template>

<div class="danger-card">
    <div class="danger-header">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <h5>Delete Team</h5>
    </div>
    <div class="warning-box">
        <h6>⚠️ This action is permanent</h6>
        <p>
            Deleting this team will remove all associated data including players,
            records, and configurations. This action cannot be undone.
        </p>
    </div>
    <div class="danger-actions">
        <Button2 cls="btn btn-danger modern-danger-btn" @click="deleteTeam()" :processing="processing">
            <i class="bi bi-trash"></i> Delete Team
        </Button2>
    </div>

</div>

</template>
<style>
    .danger-card {
        /*background: #fff;*/
        /*border-radius: 16px;*/
        /*padding: 20px;*/
        /*box-shadow: 0 8px 24px rgba(0,0,0,0.06);*/
    }

    /* HEADER */
    .danger-header {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #c62828;
        margin-bottom: 15px;
    }

    .danger-header i {
        font-size: 20px;
    }

    /* WARNING BOX */
    .warning-box {
        background: #fdecea;
        border: 1px solid #f5c6cb;
        border-radius: 12px;
        padding: 15px;
        color: #b71c1c;
        margin-bottom: 20px;
    }

    .warning-box h6 {
        font-weight: 600;
        margin-bottom: 8px;
    }

    /* ACTION */
    .danger-actions {
        display: flex;
        justify-content: flex-end;
    }

    /* BUTTON */
    .modern-danger-btn {
        border-radius: 10px;
        padding: 8px 18px;
        font-weight: 600;
    }
</style>