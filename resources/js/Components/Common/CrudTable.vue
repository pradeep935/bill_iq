<template>
  <div class="crud-table-wrap">
    <table class="crud-table">
      <thead>
        <tr>
          <th v-if="selectable" class="crud-select-column">
            <input type="checkbox" :checked="allSelected" @change="$emit('toggle-select-all')" />
          </th>
          <th v-for="column in columns" :key="column.key" :class="column.class">
            <span class="crud-heading" :title="columnHint(column)">
              {{ column.label }}
              <span class="crud-heading-hint" aria-hidden="true">?</span>
            </span>
          </th>
          <th v-if="showStatus">
            <span class="crud-heading" title="Current record state, for example active, draft, posted or cancelled.">
              Status
              <span class="crud-heading-hint" aria-hidden="true">?</span>
            </span>
          </th>
          <th v-if="showActions" class="crud-action-column">
            <span class="crud-heading" title="Available row actions such as edit, view, delete, print or workflow updates.">
              Actions
              <span class="crud-heading-hint" aria-hidden="true">?</span>
            </span>
          </th>
        </tr>
      </thead>

      <tbody>
        <tr v-if="loading">
          <td :colspan="colspan" class="crud-state loading-cell">
            <slot name="loading">
              <TableLoadingState :title="loadingText" :description="loadingDescription" />
            </slot>
          </td>
        </tr>
        <tr v-else-if="!rows.length">
          <td :colspan="colspan" class="crud-state">{{ emptyText }}</td>
        </tr>
        <tr v-for="(row, index) in rows" :key="keyFor(row, index)" :class="rowClass(row, index)">
          <td v-if="selectable" class="crud-select-column">
            <input type="checkbox" :checked="selectedIds.includes(keyFor(row, index))" @change="$emit('toggle-row', keyFor(row, index))" />
          </td>
          <td v-for="column in columns" :key="column.key" :class="column.class">
            <slot :name="`cell-${column.key}`" :row="row" :column="column">
              {{ valueFor(row, column) }}
            </slot>
          </td>
          <td v-if="showStatus">
            <span class="crud-status" :class="row.status || 'active'">
              <span></span>
              {{ statusLabel(row.status || 'active') }}
            </span>
          </td>
          <td v-if="showActions" class="crud-action-column">
            <div class="crud-row-actions">
              <slot name="actions" :row="row">
                <button type="button" class="crud-action" @click="$emit('edit', row)">Edit</button>
                <button type="button" class="crud-action danger" @click="$emit('delete', row)">Delete</button>
              </slot>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import TableLoadingState from './TableLoadingState.vue';

const props = defineProps({
  columns: { type: Array, required: true },
  rows: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  valueFor: { type: Function, default: (row, column) => row[column.key] || '-' },
  selectable: { type: Boolean, default: false },
  selectedIds: { type: Array, default: () => [] },
  rowKey: { type: [String, Function], default: 'id' },
  rowClass: { type: Function, default: () => '' },
  showStatus: { type: Boolean, default: true },
  showActions: { type: Boolean, default: true },
  loadingText: { type: String, default: 'Loading records...' },
  loadingDescription: { type: String, default: 'Please wait while data is loaded.' },
  emptyText: { type: String, default: 'No records found.' },
});

defineEmits(['edit', 'delete', 'toggle-select-all', 'toggle-row']);

const colspan = computed(() =>
  props.columns.length +
  (props.selectable ? 1 : 0) +
  (props.showStatus ? 1 : 0) +
  (props.showActions ? 1 : 0)
);

const allSelected = computed(() =>
  props.rows.length > 0 && props.rows.every((row, index) => props.selectedIds.includes(keyFor(row, index)))
);

const keyFor = (row, index) => {
  if (typeof props.rowKey === 'function') {
    return props.rowKey(row, index);
  }

  return row?.[props.rowKey] ?? index;
};

const columnHint = (column) => column?.hint || `${column?.label || 'This'} column value.`;

const statusLabel = (status) => String(status).charAt(0).toUpperCase() + String(status).slice(1);
</script>

<style scoped>
.crud-table-wrap {
  margin-bottom: -170px;
  overflow-x: auto;
  overflow-y: visible;
  padding-bottom: 170px;
}

.crud-table {
  border-collapse: collapse;
  color: #27344c;
  font-size: 12px;
  width: 100%;
}

.crud-table th {
  background: #f8fafc;
  border-bottom: 1px solid #e7ecf2;
  color: #69758a;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: .45px;
  padding: 13px 12px;
  text-align: left;
  text-transform: uppercase;
  white-space: nowrap;
}

.crud-heading {
  align-items: center;
  display: inline-flex;
  gap: 5px;
}

.crud-heading-hint {
  align-items: center;
  background: #eef3ff;
  border: 1px solid #d4def8;
  border-radius: 50%;
  color: #4562a8;
  display: inline-flex;
  font-size: 9px;
  font-weight: 900;
  height: 15px;
  justify-content: center;
  line-height: 1;
  text-transform: none;
  width: 15px;
}

.crud-table td {
  border-bottom: 1px solid #edf1f5;
  color: #27344c;
  font-size: 12px;
  padding: 14px 12px;
  vertical-align: middle;
  white-space: nowrap;
}

.crud-select-column {
  text-align: center !important;
  width: 38px;
}

.crud-table tbody tr:hover {
  background: #fbfcff;
}

.crud-table tbody tr.duplicate-highlight-row {
  animation: duplicate-row-pulse 1.25s ease-in-out 2;
  background: #fff8e6;
  box-shadow: inset 4px 0 0 #d79a20;
}

@keyframes duplicate-row-pulse {
  0%, 100% {
    background: #fff8e6;
  }

  50% {
    background: #ffedbd;
  }
}

.crud-state {
  color: #8490a2 !important;
  font-size: 12px;
  height: 180px;
  text-align: center !important;
}

.crud-state.loading-cell {
  padding: 0 !important;
}

.crud-status {
  align-items: center;
  border-radius: 7px;
  display: inline-flex;
  font-size: 9px;
  font-weight: 750;
  gap: 6px;
  padding: 5px 8px;
  text-transform: capitalize;
}

.crud-status span {
  border-radius: 50%;
  height: 6px;
  width: 6px;
}

.crud-status.active {
  background: #eaf8f1;
  color: #168757;
}

.crud-status.active span {
  background: #20a464;
}

.crud-status.inactive {
  background: #f0f2f5;
  color: #69758a;
}

.crud-status.inactive span {
  background: #8d97a7;
}

.crud-status.posted,
.crud-status.approved,
.crud-status.confirmed {
  background: #eaf8f1;
  color: #168757;
}

.crud-status.posted span,
.crud-status.approved span,
.crud-status.confirmed span {
  background: #20a464;
}

.crud-status.cancelled,
.crud-status.reversed {
  background: #fff1f2;
  color: #d23f49;
}

.crud-status.cancelled span,
.crud-status.reversed span {
  background: #d23f49;
}

.crud-status.draft {
  background: #edf2ff;
  color: #2457d6;
}

.crud-status.draft span {
  background: #2457d6;
}

.crud-action-column {
  text-align: right !important;
  width: 150px;
}

.crud-row-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}

.crud-action,
:slotted(.crud-action) {
  align-items: center;
  background: #fff;
  border: 1px solid #dce3ec;
  border-radius: 8px;
  color: #536179;
  cursor: pointer;
  display: inline-flex;
  font-size: 10px;
  font-weight: 750;
  height: 30px;
  justify-content: center;
  min-width: 46px;
  padding: 0 9px;
}

.crud-action:hover,
:slotted(.crud-action:hover) {
  background: #edf2ff;
  border-color: #ccdaff;
  color: #2457d6;
}

.crud-action.danger,
:slotted(.crud-action.danger) {
  color: #d23f49;
}

.crud-action.danger:hover,
:slotted(.crud-action.danger:hover) {
  background: #fff1f2;
  border-color: #ffd5d8;
}

:slotted(.crud-action:disabled) {
  cursor: not-allowed;
  opacity: .65;
}
</style>
