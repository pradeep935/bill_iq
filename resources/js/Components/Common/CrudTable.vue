<template>
  <div class="crud-table-wrap" :class="[wrapClass, { 'has-selection': selectable, 'has-actions': showActions }]">
    <table class="crud-table" :class="tableClass">
      <thead>
        <tr>
          <th v-if="selectable" class="crud-select-column">
            <input type="checkbox" :checked="allSelected" @change="$emit('toggle-select-all')" />
          </th>
          <th v-for="column in columns" :key="column.key" :class="column.class">
            <span class="crud-heading">
              {{ column.label }}
            </span>
          </th>
          <th v-if="showStatus">
            <span class="crud-heading">
              Status
            </span>
          </th>
          <th v-if="showActions" class="crud-action-column">
            <span class="crud-heading">
              Actions
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
  wrapClass: { type: [String, Array, Object], default: '' },
  tableClass: { type: [String, Array, Object], default: '' },
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

const statusLabel = (status) => String(status).charAt(0).toUpperCase() + String(status).slice(1);
</script>

<style scoped>
.crud-table-wrap {
  max-height: calc(100vh - 340px);
  min-height: 280px;
  margin-bottom: -170px;
  overflow-x: auto;
  overflow-y: auto;
  padding-bottom: 170px;
  position: relative;
}

.crud-table {
  border-collapse: separate;
  border-spacing: 0;
  color: #27344c;
  font-size: 12px;
  min-width: 100%;
  width: 100%;
}

.crud-table th {
  position: sticky;
  top: 0;
  z-index: 20;
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

.crud-table td {
  background: #fff;
  border-bottom: 1px solid #edf1f5;
  color: #27344c;
  font-size: 12px;
  padding: 14px 12px;
  vertical-align: middle;
  white-space: nowrap;
}

.crud-select-column {
  background: #fff;
  left: 0;
  min-width: 44px;
  position: sticky;
  z-index: 18;
  text-align: center !important;
  width: 44px;
}

thead .crud-select-column {
  background: #f8fafc;
  z-index: 32;
}

.crud-table-wrap.has-selection .crud-table th:nth-child(2),
.crud-table-wrap.has-selection .crud-table td:nth-child(2) {
  left: 44px;
}

.crud-table-wrap:not(.has-selection) .crud-table th:first-child,
.crud-table-wrap:not(.has-selection) .crud-table td:first-child,
.crud-table-wrap.has-selection .crud-table th:nth-child(2),
.crud-table-wrap.has-selection .crud-table td:nth-child(2) {
  background: #fff;
  min-width: 180px;
  position: sticky;
  z-index: 17;
  box-shadow: 8px 0 14px rgba(15, 23, 42, .06);
}

.crud-table-wrap:not(.has-selection) .crud-table th:first-child,
.crud-table-wrap.has-selection .crud-table th:nth-child(2) {
  background: #f8fafc;
  z-index: 31;
}

.crud-table tbody tr:hover {
  background: #fbfcff;
}

.crud-table tbody tr:hover td,
.crud-table tbody tr:hover .crud-select-column,
.crud-table tbody tr:hover .crud-action-column {
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
  background: #fff;
  min-width: 138px;
  position: sticky;
  right: 0;
  z-index: 18;
  box-shadow: -8px 0 14px rgba(15, 23, 42, .06);
  text-align: right !important;
  width: 150px;
}

thead .crud-action-column {
  background: #f8fafc;
  z-index: 32;
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

@media (max-width: 760px) {
  .crud-table-wrap {
    max-height: none;
    min-height: 0;
    overflow-x: auto;
    overflow-y: visible;
  }

  .crud-table th,
  .crud-table td,
  .crud-select-column,
  .crud-action-column,
  .crud-table-wrap:not(.has-selection) .crud-table th:first-child,
  .crud-table-wrap:not(.has-selection) .crud-table td:first-child,
  .crud-table-wrap.has-selection .crud-table th:nth-child(2),
  .crud-table-wrap.has-selection .crud-table td:nth-child(2) {
    position: static;
    box-shadow: none;
  }
}
</style>
