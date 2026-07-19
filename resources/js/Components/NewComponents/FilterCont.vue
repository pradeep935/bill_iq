<script setup>
    import { ref, onMounted, watch } from 'vue';
</script>

<template>
    <div>
        <slot></slot>
    </div>
</template>

<style>
  .filter-count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 17px;
    height: 17px;
    border-radius: 50%;
    background: #fff;
    color: var(--primary);
    font-size: 10px;
    font-weight: 700;
    margin-left: 4px;
  }

  .btn-primary .filter-count-badge {
    background: rgba(255, 255, 255, 0.25);
    color: #fff;
  }

  .btn-ghost-danger {
    background: transparent;
    color: var(--danger);
    border: 1.5px solid var(--danger);
  }

  .btn-ghost-danger:hover {
    background: var(--danger);
    color: #fff;
  }

  .filter-panel {
    overflow: hidden;
    max-height: 0;
    transition: max-height 0.3s ease, opacity 0.25s ease;
    opacity: 0;
  }

  .filter-panel.open {
    max-height: 800px;
    opacity: 1;
  }

  .filter-panel-inner {
    padding: 16px 20px 20px;
    border-bottom: 1px solid var(--border);
    background: #fafafa;
  }

  .filter-group-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    margin-bottom: 6px;
  }

  .filter-range-pair {
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .filter-range-pair input {
    min-width: 0;
  }

  .filter-range-sep {
    font-size: 12px;
    color: var(--text-muted);
    white-space: nowrap;
  }

  .filter-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    padding-top: 4px;
    border-top: 1px solid var(--border);
    margin-top: 4px;
  }
</style>