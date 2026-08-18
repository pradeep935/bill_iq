<template>
  <div class="dispatch-table-wrap">
    <table>
      <thead>
        <tr>
          <th v-for="column in columns" :key="column.key">{{ column.label }}</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="`${row.row_type || row.reservation_number || row.transaction_type || 'row'}-${row.id}`" @click="$emit('open', row)">
          <td v-for="column in columns" :key="column.key">
            <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">{{ row[column.key] ?? '-' }}</slot>
          </td>
          <td class="actions-cell" @click.stop><slot name="actions" :row="row" /></td>
        </tr>
        <tr v-if="!rows.length"><td :colspan="columns.length + 1" class="empty">{{ emptyText }}</td></tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
defineProps({
  columns: { type: Array, required: true },
  rows: { type: Array, default: () => [] },
  emptyText: { type: String, default: 'No dispatch records found.' },
});
defineEmits(['open']);
</script>

<style scoped>
.dispatch-table-wrap{border:1px solid #e4eaf2;border-radius:8px;overflow:auto}table{width:100%;min-width:1560px;border-collapse:separate;border-spacing:0}th{position:sticky;top:0;z-index:3;padding:11px 10px;background:#f8fafc;color:#65758b;border-bottom:1px solid #e4eaf2;text-align:left;white-space:nowrap;font-size:10px;font-weight:900;text-transform:uppercase}td{padding:11px 10px;border-bottom:1px solid #edf1f5;color:#27344c;white-space:nowrap;font-size:12px}tbody tr{cursor:pointer}tbody tr:hover td{background:#fbfdff}.actions-cell{position:sticky;right:0;background:#fff;min-width:270px}.empty{padding:32px!important;text-align:center;color:#8490a2}
</style>
