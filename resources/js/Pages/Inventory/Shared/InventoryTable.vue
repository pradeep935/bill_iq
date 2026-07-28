<script setup>
defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    emptyText: { type: String, default: 'No records found.' },
});
</script>

<template>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th v-for="column in columns" :key="column.key">{{ column.label }}</th>
                    <th v-if="$slots.actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id">
                    <td v-for="column in columns" :key="column.key">
                        <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">
                            {{ row[column.key] ?? '-' }}
                        </slot>
                    </td>
                    <td v-if="$slots.actions" class="actions-cell"><slot name="actions" :row="row" /></td>
                </tr>
                <tr v-if="!rows.length">
                    <td :colspan="columns.length + ($slots.actions ? 1 : 0)" class="empty">{{ emptyText }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.table-wrapper{border:1px solid #edf1f5;border-radius:8px;overflow:auto}table{border-collapse:collapse;min-width:1100px;width:100%}th,td{border-bottom:1px solid #edf1f5;font-size:12px;padding:11px 10px;text-align:left;white-space:nowrap}th{background:#f8fafc;color:#69758a;font-size:10px;letter-spacing:.04em;text-transform:uppercase}.actions-cell{background:#fff;min-width:230px;position:sticky;right:0}.empty{color:#8490a2;text-align:center}
</style>
