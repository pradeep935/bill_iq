<template>
  <div class="bill-app" :class="{ 'menu-open': menuOpen }">
    <button class="bill-menu-backdrop" type="button" aria-label="Close menu" @click="menuOpen = false"></button>
    <aside class="bill-sidebar">
      <div class="bill-brand">
        <span class="bill-logo">₹</span>
        <div>
          <strong>Bill IQ</strong>
          <small>Billing + Accounting</small>
        </div>
      </div>
      <div class="bill-company">
        <strong>ABC Retail Pvt Ltd</strong>
        <span>Noida · FY 2026-27</span>
        <div class="bill-module-picker">
          <button type="button" @click="moduleOpen = !moduleOpen">
            {{ activeSection.label }}
            <span>⌄</span>
          </button>
          <div v-if="moduleOpen" class="bill-module-menu">
            <button
              v-for="section in visibleSections"
              :key="section.key"
              type="button"
              :class="{ active: section.key === activeSection.key }"
              @click="selectSection(section.key)"
            >
              <span class="bill-module-icon" v-html="iconSvg(section.icon)"></span>
              {{ section.label }}
            </button>
          </div>
        </div>
      </div>
      <nav class="bill-nav">
        <div class="bill-nav-section" :key="activeSection.key">
          <span class="bill-nav-heading">{{ activeSection.label }}</span>
          <a
            v-for="item in activeSection.items"
            :key="item.href"
            :class="{ active: page === item.page }"
            :href="item.href"
          >
            <span class="bill-nav-icon" v-html="iconSvg(item.icon)"></span>
            {{ item.label }}
          </a>
        </div>
      </nav>
      <div class="bill-sidebar-footer">
        <a class="bill-logout" href="/app/logout">
          <span class="bill-nav-icon" v-html="iconSvg('log-out')"></span>
          Logout
        </a>
      </div>
    </aside>

    <main class="bill-main">
      <header class="bill-topbar">
        <button class="bill-menu" type="button" aria-label="Open menu" @click="menuOpen = true">☰</button>
        <div>
          <span class="bill-eyebrow">Billing Software</span>
          <h1>{{ title }}</h1>
        </div>
        <div class="bill-top-actions">
          <a href="/app/sales/pos">New Bill</a>
          <a href="/app/accounting/vouchers">Voucher</a>
          <a href="/app/reports/business">Reports</a>
        </div>
        <div class="bill-user">
          <span>{{ userInitials }}</span>
          <div>
            <strong>{{ userName }}</strong>
            <small>Business Owner</small>
          </div>
        </div>
      </header>
      <slot />
    </main>
  </div>
</template>

<script setup>
import { computed, ref, watchEffect } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
  page: { type: String, required: true },
  title: { type: String, required: true },
});

const menuOpen = ref(false);
const moduleOpen = ref(false);
const selectedSectionKey = ref('');
const inertiaPage = usePage();
const roleId = computed(() => Number(inertiaPage.props.auth?.user?.role_id || inertiaPage.props.role_id || 2));
const userName = computed(() => inertiaPage.props.auth?.user?.name || 'Amit Kumar');
const userInitials = computed(() => userName.value.split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase());

const sections = [
  {
    key: 'dashboard',
    label: 'ADMIN',
    icon: 'layout-dashboard',
    items: [
      { label: 'Business Dashboard', page: 'dashboard', href: '/app', icon: 'layout-dashboard' },
      { label: 'Admin Workspace', page: 'admin-workspace', href: '/app/admin/workspace', icon: 'shield-check' },
      { label: 'Staff Workspace', page: 'staff-workspace', href: '/app/staff/workspace', icon: 'users' },
      { label: 'Onboarding', page: 'onboarding', href: '/app/admin/onboarding', icon: 'clipboard-check' },
    ],
  },
  {
    key: 'sales',
    label: 'SALES',
    icon: 'receipt',
    items: [
      { label: 'POS Billing', page: 'pos', href: '/app/sales/pos', icon: 'scan-barcode' },
      { label: 'Sales Invoices', page: 'sales', href: '/app/sales/invoices', icon: 'receipt' },
      { label: 'Customers', page: 'customers', href: '/app/sales/customers', icon: 'users' },
      { label: 'Stock Outward', page: 'inventory-outward', href: '/app/sales/stock-outward', icon: 'package-minus' },
      { label: 'Reserved Stock', page: 'inventory-reserved', href: '/app/sales/reserved-stock', icon: 'bookmark-check' },
    ],
  },
  {
    key: 'purchase',
    label: 'PURCHASE',
    icon: 'shopping-bag',
    items: [
      { label: 'Purchases', page: 'purchases', href: '/app/purchase/bills', icon: 'shopping-bag' },
      { label: 'Suppliers', page: 'suppliers', href: '/app/purchase/suppliers', icon: 'truck' },
      { label: 'Stock Inward / GRN', page: 'inventory-inward', href: '/app/purchase/grn', icon: 'package-plus' },
      { label: 'Reorder Suggestions', page: 'inventory-reorder', href: '/app/purchase/reorder', icon: 'rotate-cw' },
      { label: 'Inventory Orders', page: 'inventory-orders', href: '/app/purchase/orders', icon: 'clipboard-list' },
    ],
  },
  {
    key: 'stock',
    label: 'INVENTORY',
    icon: 'boxes',
    items: [
      { label: 'Inventory Dashboard', page: 'inventory', href: '/app/inventory', icon: 'boxes' },
      { label: 'Add Product Master', page: 'products', href: '/app/inventory/products', icon: 'tag' },
      { label: 'Add Inventory', page: 'inventory-add', href: '/app/inventory/add', icon: 'package-plus' },
      { label: 'Current Stock', page: 'inventory-current-stock', href: '/app/inventory/current-stock', icon: 'warehouse' },
      { label: 'Inventory Vouchers', page: 'inventory-vouchers', href: '/app/inventory/vouchers', icon: 'file-stack' },
      { label: 'Batch & Expiry', page: 'inventory-batches', href: '/app/inventory/batches', icon: 'calendar-clock' },
      { label: 'Serial Numbers', page: 'inventory-serials', href: '/app/inventory/serials', icon: 'list-ordered' },
      { label: 'Barcode Center', page: 'inventory-barcode-center', href: '/app/inventory/barcode-center', icon: 'barcode' },
      { label: 'Manufacturing / BOM', page: 'inventory-manufacturing', href: '/app/inventory/manufacturing', icon: 'factory' },
    ],
  },
  {
    key: 'warehouse',
    label: 'WAREHOUSE',
    icon: 'warehouse',
    items: [
      { label: 'Warehouses / Bins', page: 'inventory-warehouses', href: '/app/warehouse/warehouses', icon: 'warehouse' },
      { label: 'Bins / Racks', page: 'inventory-bins', href: '/app/warehouse/bins', icon: 'layers' },
      { label: 'Godown Balances', page: 'inventory-godown-balance', href: '/app/warehouse/godown-balances', icon: 'scale' },
      { label: 'Stock Transfer', page: 'inventory-transfer', href: '/app/warehouse/transfer', icon: 'arrow-left-right' },
      { label: 'Transfer Requests', page: 'inventory-transfer-requests', href: '/app/warehouse/transfer-requests', icon: 'repeat' },
      { label: 'Stock Adjustment', page: 'inventory-adjustment', href: '/app/warehouse/adjustment', icon: 'sliders' },
      { label: 'Physical Audit', page: 'inventory-audit', href: '/app/warehouse/audit', icon: 'search-check' },
      { label: 'Batch / Serial Allocation', page: 'inventory-allocation', href: '/app/warehouse/allocation', icon: 'git-branch' },
    ],
  },
  {
    key: 'accounting',
    label: 'ACCOUNTING',
    icon: 'landmark',
    items: [
      { label: 'Chart of Accounts', page: 'accounts', href: '/app/accounting/chart-of-accounts', icon: 'landmark' },
      { label: 'Vouchers', page: 'vouchers', href: '/app/accounting/vouchers', icon: 'file-plus' },
      { label: 'Ledgers', page: 'ledgers', href: '/app/accounting/ledgers', icon: 'book-open' },
      { label: 'Expenses', page: 'expenses', href: '/app/accounting/expenses', icon: 'wallet' },
      { label: 'GST', page: 'gst', href: '/app/accounting/gst', icon: 'percent' },
      { label: 'GST Returns', page: 'inventory-gst-returns', href: '/app/accounting/gst-returns', icon: 'file-check' },
    ],
  },
  {
    key: 'reports',
    label: 'REPORTS',
    icon: 'bar-chart',
    items: [
      { label: 'Business Reports', page: 'reports', href: '/app/reports/business', icon: 'bar-chart' },
      { label: 'Inventory Reports', page: 'inventory-reports', href: '/app/reports/inventory', icon: 'pie-chart' },
      { label: 'Stock Ledger', page: 'stock-ledger', href: '/app/reports/stock-ledger', icon: 'file-text' },
      { label: 'Stock Valuation', page: 'inventory-valuation', href: '/app/reports/stock-valuation', icon: 'indian-rupee' },
      { label: 'Voucher Audit Trail', page: 'inventory-audit-trail', href: '/app/reports/audit-trail', icon: 'history' },
      { label: 'Acceptance Matrix', page: 'acceptance', href: '/app/reports/acceptance', icon: 'badge-check' },
    ],
  },
  {
    key: 'admin',
    label: 'SETUP',
    icon: 'settings',
    items: [
      { label: 'Branches', page: 'branches', href: '/app/setup/branches', icon: 'building-2' },
      { label: 'Employees', page: 'employees', href: '/app/setup/employees', icon: 'id-card' },
      { label: 'Users & Roles', page: 'users', href: '/app/setup/users', icon: 'user-cog' },
      { label: 'SaaS Admin', page: 'saas', href: '/app/setup/saas', icon: 'cloud-cog' },
      { label: 'Acceptance Matrix', page: 'acceptance', href: '/app/reports/acceptance', icon: 'badge-check' },
      { label: 'Settings', page: 'settings', href: '/app/setup/settings', icon: 'settings' },
    ],
  },
];

const iconPaths = {
  'layout-dashboard': '<rect x="3" y="3" width="7" height="8" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="15" width="7" height="6" rx="1.5"/>',
  'shield-check': '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
  users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  'clipboard-check': '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-5"/>',
  receipt: '<path d="M4 2v20l3-2 3 2 3-2 3 2 4-2V2l-3 2-3-2-3 2-3-2-3 2-3-2Z"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/>',
  'scan-barcode': '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M8 7v10"/><path d="M12 7v10"/><path d="M17 7v10"/>',
  'package-minus': '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/><path d="M9 16h6"/>',
  'bookmark-check': '<path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2Z"/><path d="m9 10 2 2 4-4"/>',
  'shopping-bag': '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
  truck: '<path d="M10 17h4V5H2v12h3"/><path d="M14 8h4l4 4v5h-3"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
  'package-plus': '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/><path d="M9 16h6"/><path d="M12 13v6"/>',
  'rotate-cw': '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/>',
  'clipboard-list': '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M8 12h8"/><path d="M8 16h8"/>',
  boxes: '<path d="M2 7.5 12 2l10 5.5-10 5.5Z"/><path d="M2 12.5 12 18l10-5.5"/><path d="M2 17.5 12 23l10-5.5"/><path d="M12 13v5"/>',
  tag: '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
  warehouse: '<path d="M3 21h18"/><path d="M5 21V8l7-5 7 5v13"/><path d="M9 21v-7h6v7"/><path d="M8 10h8"/>',
  'file-stack': '<path d="M16 2H8a2 2 0 0 0-2 2v16"/><path d="M18 6H10a2 2 0 0 0-2 2v14"/><path d="M20 10H12a2 2 0 0 0-2 2v10h10Z"/>',
  'calendar-clock': '<path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18"/><path d="M12 14v3l2 1"/>',
  'list-ordered': '<path d="M10 6h10"/><path d="M10 12h10"/><path d="M10 18h10"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1-2-1"/>',
  barcode: '<path d="M4 7v10"/><path d="M8 7v10"/><path d="M12 7v10"/><path d="M16 7v10"/><path d="M20 7v10"/>',
  factory: '<path d="M3 21h18"/><path d="M5 21V8l6 4V8l6 4V8h2v13"/><path d="M9 17h1"/><path d="M14 17h1"/>',
  layers: '<path d="m12 2 10 5-10 5L2 7Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',
  scale: '<path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h18"/>',
  'arrow-left-right': '<path d="M8 3 4 7l4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/>',
  repeat: '<path d="m17 2 4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
  sliders: '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>',
  'search-check': '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/><path d="m8 11 2 2 4-4"/>',
  'git-branch': '<line x1="6" y1="3" x2="6" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/>',
  landmark: '<path d="M3 21h18"/><path d="M5 21V10"/><path d="M19 21V10"/><path d="M9 21V10"/><path d="M15 21V10"/><path d="M2 10h20"/><path d="m12 3 9 7H3Z"/>',
  'file-plus': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15h6"/>',
  'book-open': '<path d="M2 4h7a4 4 0 0 1 4 4v12a3 3 0 0 0-3-3H2Z"/><path d="M22 4h-7a4 4 0 0 0-4 4v12a3 3 0 0 1 3-3h8Z"/>',
  wallet: '<path d="M20 12V8H5a2 2 0 0 1 0-4h13v4"/><path d="M5 8h16v12H5a3 3 0 0 1-3-3V6"/><path d="M16 14h2"/>',
  percent: '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
  'file-check': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="m9 15 2 2 4-4"/>',
  'bar-chart': '<path d="M3 3v18h18"/><path d="M8 17V9"/><path d="M13 17V5"/><path d="M18 17v-3"/>',
  'pie-chart': '<path d="M21 12a9 9 0 1 1-9-9v9Z"/><path d="M12 3a9 9 0 0 1 9 9h-9Z"/>',
  'file-text': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h6"/>',
  'indian-rupee': '<path d="M6 3h12"/><path d="M6 8h12"/><path d="M6 13l8 8"/><path d="M6 13h3a4 4 0 0 0 0-8H6"/>',
  history: '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 3v6h6"/><path d="M12 7v5l3 2"/>',
  'badge-check': '<path d="M12 2 15 5l4-.5.5 4 3 3-3 3-.5 4-4-.5-3 3-3-3-4 .5-.5-4-3-3 3-3 .5-4 4 .5Z"/><path d="m9 12 2 2 4-4"/>',
  settings: '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1-1.55 1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 1.55-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.37.61 1 .97 1.68 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51 1Z"/>',
  'building-2': '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18"/><path d="M6 12H4a2 2 0 0 0-2 2v8h20v-8a2 2 0 0 0-2-2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
  'id-card': '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M15 8h3"/><path d="M15 12h3"/><path d="M7 16h4"/>',
  'user-cog': '<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h5"/><path d="M19 15a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/><path d="M19 13v2"/><path d="M19 19v2"/><path d="M17.3 14 16 15.3"/><path d="M22 15.3 20.7 14"/><path d="M16 18.7l1.3 1.3"/><path d="M20.7 20 22 18.7"/>',
  'cloud-cog': '<path d="M17.5 19H8a6 6 0 1 1 1-11.92A7 7 0 0 1 22 12.5"/><circle cx="18" cy="17" r="2"/><path d="M18 13v2"/><path d="M18 19v2"/><path d="m16.3 14 1.1 1.1"/><path d="m18.6 18.9 1.1 1.1"/><path d="m20 14-1.1 1.1"/><path d="m17.4 18.9-1.1 1.1"/>',
  'log-out': '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
};

const iconSvg = (name) => `<svg viewBox="0 0 24 24" aria-hidden="true">${iconPaths[name] || iconPaths['layout-dashboard']}</svg>`;

const visibleSections = computed(() => {
  if (roleId.value === 1) return sections;
  if (roleId.value === 2) {
    return sections.filter((section) => !['admin'].includes(section.key));
  }

  const allowedPages = ['staff-workspace', 'pos', 'sales', 'customers', 'inventory-current-stock', 'inventory-reserved', 'stock-ledger'];
  return sections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => allowedPages.includes(item.page)),
    }))
    .filter((section) => section.items.length);
});

const currentPageSection = computed(() => (
  visibleSections.value.find((section) => section.items.some((item) => item.page === props.page)) || visibleSections.value[0] || sections[0]
));

const activeSection = computed(() => (
  visibleSections.value.find((section) => section.key === selectedSectionKey.value) || currentPageSection.value
));

const selectSection = (key) => {
  moduleOpen.value = false;
  const section = visibleSections.value.find((item) => item.key === key);

  if (section?.items?.[0]?.href) {
    window.location.href = section.items[0].href;
  }
};

watchEffect(() => {
  selectedSectionKey.value = currentPageSection.value.key;
});

</script>
