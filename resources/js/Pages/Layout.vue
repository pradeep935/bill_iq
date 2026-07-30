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
        <strong>{{ currentBusiness.name }}</strong>
        <span>{{ currentBranch.name }} · FY {{ currentFinancialYear.name }}</span>
        <div class="bill-module-picker">
          <button type="button" title="Switch module" @click="moduleOpen = !moduleOpen">
            {{ activeSection.label }}
            <span>⌄</span>
          </button>
          <div v-if="moduleOpen" class="bill-module-menu">
            <button
              v-for="section in visibleSections"
              :key="section.key"
              type="button"
              :class="{ active: section.key === activeSection.key }"
              :title="`Open ${section.label}`"
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
            :href="normalizeUrl(item.href)"
            :title="item.label"
          >
            <span class="bill-nav-icon" v-html="iconSvg(item.icon)"></span>
            {{ item.label }}
          </a>
        </div>
      </nav>

      <div class="bill-sidebar-footer">
        <button class="bill-logout" type="button" title="Logout" @click="logout">
          <span class="bill-nav-icon" v-html="iconSvg('log-out')"></span>
          Logout
        </button>
      </div>
    </aside>

    <main class="bill-main">
      <header class="bill-topbar">
        <div class="bill-topbar-left">
          <button class="bill-menu" type="button" aria-label="Open menu" title="Open menu" @click="menuOpen = true">☰</button>
          <slot name="topbar-title" />
        </div>

        <div class="bill-saas-context" aria-label="Current tenant context">
          <label title="Switch business">
            <span>Business</span>
            <select v-model="selectedBusinessId" aria-label="Current business" @change="switchContext">
              <option v-for="business in businesses" :key="business.id" :value="business.id">{{ business.name }}</option>
            </select>
          </label>
          <label title="Switch branch">
            <span>Branch</span>
            <select v-model="selectedBranchId" aria-label="Current branch" @change="switchContext">
              <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
            </select>
          </label>
          <label title="Switch financial year">
            <span>FY</span>
            <select v-model="selectedFinancialYearValue" aria-label="Current financial year" @change="switchContext">
              <option v-for="year in financialYears" :key="year.id || year.name" :value="year.id || year.name">{{ year.name }}</option>
            </select>
          </label>
        </div>

        <div class="bill-global-search">
          <span class="bill-nav-icon" v-html="iconSvg('search')"></span>
          <input v-model="globalSearch" type="search" placeholder="Search invoices, customers, products" @keyup.enter="runGlobalSearch" />
        </div>

        <div class="bill-top-actions">
          <a v-if="permissions['sales.create'] !== false" :href="routeUrl('sales.invoices.create', '/app/sales/invoices/create')" title="Create a new bill">
            <span class="bill-nav-icon" v-html="iconSvg('receipt')"></span>
            New Bill
          </a>
          <a v-if="permissions['accounting.create'] !== false" :href="routeUrl('accounting.vouchers', '/app/accounting/vouchers')" title="Open accounting vouchers">
            <span class="bill-nav-icon" v-html="iconSvg('file-plus')"></span>
            Voucher
          </a>
          <a v-if="permissions['reports.view'] !== false" :href="routeUrl('reports.index', '/app/reports')" title="Open reports">
            <span class="bill-nav-icon" v-html="iconSvg('bar-chart')"></span>
            Reports
          </a>
          <button type="button" title="Notifications" @click="showNotice('No unread notifications.')">
            <span class="bill-nav-icon" v-html="iconSvg('bell')"></span>
            Notifications
          </button>
          <button type="button" title="Help center" @click="showNotice('BillIQ help is available from Settings > Support.')">
            <span class="bill-nav-icon" v-html="iconSvg('circle-help')"></span>
            Help
          </button>
        </div>

        <a class="bill-user" :href="routeUrl('profile.edit', '/profile')" title="Open user profile">
          <span>{{ userInitials }}</span>
          <div>
            <strong>{{ userName }}</strong>
            <small>{{ roleLabel }}</small>
          </div>
        </a>
      </header>
      <slot />
    </main>
  </div>
</template>

<script setup>
import { computed, ref, watchEffect } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const props = defineProps({
  page: { type: String, required: true },
  title: { type: String, required: true },
});

const menuOpen = ref(false);
const moduleOpen = ref(false);
const selectedSectionKey = ref('');
const globalSearch = ref('');
const inertiaPage = usePage();
const roleId = computed(() => Number(inertiaPage.props.auth?.user?.role_id || inertiaPage.props.role_id || 2));
const userName = computed(() => inertiaPage.props.auth?.user?.name || 'BillIQ User');
const userInitials = computed(() => userName.value.split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase());
const baseUrl = computed(() => String(inertiaPage.props.app?.base_url || '').replace(/\/$/, ''));
const dashboardRoutes = computed(() => inertiaPage.props.routes || {});
const permissions = computed(() => inertiaPage.props.permissions || {});
const context = computed(() => inertiaPage.props.context || {});
const currentBusiness = computed(() => context.value.business || { id: '', name: 'BillIQ' });
const currentBranch = computed(() => context.value.branch || { id: '', name: 'Primary Branch' });
const currentFinancialYear = computed(() => context.value.financial_year || { id: '', name: inertiaPage.props.app?.financial_year || 'Current' });
const businesses = computed(() => context.value.businesses?.length ? context.value.businesses : [currentBusiness.value]);
const branches = computed(() => context.value.branches?.length ? context.value.branches : [currentBranch.value]);
const financialYears = computed(() => context.value.financial_years?.length ? context.value.financial_years : [currentFinancialYear.value]);
const selectedBusinessId = ref(currentBusiness.value.id || '');
const selectedBranchId = ref(currentBranch.value.id || '');
const selectedFinancialYearValue = ref(currentFinancialYear.value.id || currentFinancialYear.value.name || '');

const roleLabel = computed(() => {
  if (roleId.value === 1) return 'Super Admin';
  if (roleId.value === 3) return 'Staff';
  return 'Business Owner';
});

const appUrl = (path) => `${baseUrl.value}/${String(path || '').replace(/^\/+/, '')}`;
const normalizeUrl = (url) => {
  if (!url || String(url).startsWith('http')) return url || '#';
  if (baseUrl.value && String(url).startsWith(`${baseUrl.value}/`)) return url;
  return appUrl(url);
};
const routeUrl = (name, fallback) => normalizeUrl(dashboardRoutes.value?.[name]?.url || fallback);

const sections = [
  {
    key: 'dashboard',
    label: 'ADMIN',
    icon: 'layout-dashboard',
    items: [
      { label: 'Business Dashboard', page: 'dashboard', href: routeUrl('business.dashboard', '/app'), icon: 'layout-dashboard' },
      { label: 'Admin Workspace', page: 'admin-workspace', href: routeUrl('admin.workspace', '/app/admin/workspace'), icon: 'shield-check' },
      { label: 'Staff Workspace', page: 'staff-workspace', href: routeUrl('staff.workspace', '/app/staff/workspace'), icon: 'users' },
      { label: 'Onboarding', page: 'onboarding', href: routeUrl('onboarding', '/app/admin/onboarding'), icon: 'clipboard-check' },
    ],
  },
  {
    key: 'crm',
    label: 'CRM',
    icon: 'users',
    items: [
      { label: 'Leads & Pipeline', page: 'crm', href: '/app/crm', icon: 'users' },
    ],
  },
  {
    key: 'sales',
    label: 'SALES',
    icon: 'receipt',
    items: [
      { label: 'POS Billing', page: 'pos', href: routeUrl('sales.pos', '/app/sales/pos'), icon: 'scan-barcode' },
      { label: 'Sales Invoices', page: 'sales', href: routeUrl('sales.invoices', '/app/sales/invoices'), icon: 'receipt' },
      { label: 'Sales Returns', page: 'sales-returns', href: routeUrl('sales.returns', '/app/sales/returns'), icon: 'rotate-cw' },
      { label: 'Customers', page: 'customers', href: routeUrl('sales.customers', '/app/sales/customers'), icon: 'users' },
      { label: 'Stock Outward', page: 'inventory-outward', href: routeUrl('sales.stock-outward', '/app/sales/stock-outward'), icon: 'package-minus' },
      { label: 'Reserved Stock', page: 'inventory-reserved', href: routeUrl('sales.reserved-stock', '/app/sales/reserved-stock'), icon: 'bookmark-check' },
    ],
  },
  {
    key: 'purchase',
    label: 'PURCHASE',
    icon: 'shopping-bag',
    items: [
      { label: 'Purchases', page: 'purchases', href: routeUrl('purchases.index', '/app/purchase/bills'), icon: 'shopping-bag' },
      { label: 'Purchase Returns', page: 'purchase-returns', href: '/app/purchase/returns', icon: 'rotate-cw' },
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
      { label: 'Inventory Dashboard', page: 'inventory', href: routeUrl('inventory.dashboard', '/app/inventory'), icon: 'boxes' },
      { label: 'Add Product Master', page: 'products', href: routeUrl('products.index', '/app/inventory/products'), icon: 'tag' },
      { label: 'Opening Stock', page: 'opening-stock', href: '/app/inventory/add', icon: 'package-plus' },
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
      { label: 'Chart of Accounts', page: 'accounts', href: routeUrl('accounting.dashboard', '/app/accounting/chart-of-accounts'), icon: 'landmark' },
      { label: 'Vouchers', page: 'vouchers', href: routeUrl('accounting.vouchers.index', '/app/accounting/vouchers'), icon: 'file-plus' },
      { label: 'Ledgers', page: 'ledgers', href: '/app/accounting/ledgers', icon: 'book-open' },
      { label: 'Expenses', page: 'expenses', href: '/app/accounting/expenses', icon: 'wallet' },
      { label: 'Fixed Assets', page: 'fixed-assets', href: '/app/fixed-assets', icon: 'boxes' },
      { label: 'Payroll', page: 'payroll', href: '/app/payroll', icon: 'id-card' },
      { label: 'GST', page: 'gst', href: '/app/accounting/gst', icon: 'percent' },
      { label: 'GST Returns', page: 'inventory-gst-returns', href: '/app/accounting/gst-returns', icon: 'file-check' },
    ],
  },
  {
    key: 'reports',
    label: 'REPORTS',
    icon: 'bar-chart',
    items: [
      { label: 'Business Reports', page: 'reports', href: routeUrl('reports.index', '/app/reports/business'), icon: 'bar-chart' },
      { label: 'Inventory Reports', page: 'inventory-reports', href: '/app/reports/inventory', icon: 'pie-chart' },
      { label: 'Stock Ledger', page: 'stock-ledger', href: routeUrl('inventory.stock-ledger', '/app/reports/stock-ledger'), icon: 'file-text' },
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
      { label: 'Masters', page: 'masters', href: '/app/setup/masters', icon: 'settings' },
      { label: 'Branches', page: 'branches', href: '/app/setup/branches', icon: 'building-2' },
      { label: 'Employees', page: 'employees', href: '/app/setup/employees', icon: 'id-card' },
      { label: 'Users & Roles', page: 'users', href: '/app/setup/users', icon: 'user-cog' },
      { label: 'SaaS Admin', page: 'saas', href: '/app/setup/saas', icon: 'cloud-cog' },
      { label: 'Acceptance Matrix', page: 'setup-acceptance', href: '/app/setup/acceptance', icon: 'badge-check' },
      { label: 'Settings', page: 'settings', href: '/app/setup/settings', icon: 'settings' },
    ],
  },
];

const iconPaths = {
  'layout-dashboard': '<rect x="3" y="3" width="7" height="8" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="15" width="7" height="6" rx="1.5"/>',
  'shield-check': '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
  users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  'clipboard-check': '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-5"/>',
  receipt: '<path d="M4 2v20l3-2 3 2 3-2 3 2 4-2V2l-3 2-3-2-3 2-3-2-3 2Z"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/>',
  'scan-barcode': '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M8 7v10"/><path d="M12 7v10"/><path d="M17 7v10"/>',
  'package-minus': '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/><path d="M9 16h6"/>',
  'bookmark-check': '<path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2Z"/><path d="m9 10 2 2 4-4"/>',
  'shopping-bag': '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
  truck: '<path d="M10 17h4V5H2v12h3"/><path d="M14 8h4l4 4v5h-3"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
  'package-plus': '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/><path d="M12 13v6"/><path d="M9 16h6"/>',
  'rotate-cw': '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/>',
  boxes: '<path d="M2 7.5 12 2l10 5.5-10 5.5Z"/><path d="M2 12.5 12 18l10-5.5"/><path d="M2 17.5 12 23l10-5.5"/><path d="M12 13v5"/>',
  tag: '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
  warehouse: '<path d="M3 21h18"/><path d="M5 21V8l7-5 7 5v13"/><path d="M9 21v-7h6v7"/><path d="M8 10h8"/>',
  'file-stack': '<path d="M16 2H8a2 2 0 0 0-2 2v16"/><path d="M18 6H10a2 2 0 0 0-2 2v14"/><path d="M20 10H12a2 2 0 0 0-2 2v10h10Z"/>',
  'calendar-clock': '<path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18"/><path d="M12 14v3l2 1"/>',
  'list-ordered': '<path d="M10 6h10"/><path d="M10 12h10"/><path d="M10 18h10"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1-2-1"/>',
  barcode: '<path d="M4 7v10"/><path d="M8 7v10"/><path d="M12 7v10"/><path d="M16 7v10"/><path d="M20 7v10"/>',
  landmark: '<path d="M3 21h18"/><path d="M5 21V10"/><path d="M19 21V10"/><path d="M9 21V10"/><path d="M15 21V10"/><path d="M2 10h20"/><path d="m12 3 9 7H3Z"/>',
  'file-plus': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15h6"/>',
  'book-open': '<path d="M2 4h7a4 4 0 0 1 4 4v12a3 3 0 0 0-3-3H2Z"/><path d="M22 4h-7a4 4 0 0 0-4 4v12a3 3 0 0 1 3-3h8Z"/>',
  wallet: '<path d="M20 12V8H5a2 2 0 0 1 0-4h13v4"/><path d="M5 8h16v12H5a3 3 0 0 1-3-3V6"/><path d="M16 14h2"/>',
  percent: '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
  'bar-chart': '<path d="M3 3v18h18"/><path d="M8 17V9"/><path d="M13 17V5"/><path d="M18 17v-3"/>',
  'pie-chart': '<path d="M21 12a9 9 0 1 1-9-9v9Z"/><path d="M12 3a9 9 0 0 1 9 9h-9Z"/>',
  'file-text': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h6"/>',
  'indian-rupee': '<path d="M6 3h12"/><path d="M6 8h12"/><path d="M6 13l8 8"/><path d="M6 13h3a4 4 0 0 0 0-8H6"/>',
  settings: '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1-1.55 1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 1.55-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.37.61 1 .97 1.68 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51 1Z"/>',
  'building-2': '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18"/><path d="M6 12H4a2 2 0 0 0-2 2v8h20v-8a2 2 0 0 0-2-2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
  'user-cog': '<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h5"/><path d="M19 15a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/><path d="M19 13v2"/><path d="M19 19v2"/><path d="M17.3 14 16 15.3"/><path d="M22 15.3 20.7 14"/><path d="M16 18.7l1.3 1.3"/><path d="M20.7 20 22 18.7"/>',
  'log-out': '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
  search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
  bell: '<path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8"/>',
  'circle-help': '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 1 1 5.8 1c0 2-3 2-3 4"/><path d="M12 17h.01"/>',
};

const iconSvg = (name) => `<svg viewBox="0 0 24 24" aria-hidden="true">${iconPaths[name] || iconPaths['layout-dashboard']}</svg>`;

const visibleSections = computed(() => {
  if (roleId.value === 1) return sections;
  if (roleId.value === 2) return sections.filter((section) => section.key !== 'admin');

  const allowedPages = ['staff-workspace', 'pos', 'sales', 'customers', 'inventory-current-stock', 'inventory-reserved', 'stock-ledger'];
  return sections
    .map((section) => ({
      ...section,
      label: section.key === 'dashboard' ? 'WORKSPACE' : section.label,
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
  if (section?.items?.[0]?.href) window.location.href = normalizeUrl(section.items[0].href);
};

const switchContext = () => {
  router.post(routeUrl('app.context.switch', '/app/context'), {
    business_id: selectedBusinessId.value || null,
    branch_id: selectedBranchId.value || null,
    financial_year_id: financialYears.value.find((year) => String(year.id) === String(selectedFinancialYearValue.value))?.id || null,
    financial_year: financialYears.value.find((year) => String(year.id || year.name) === String(selectedFinancialYearValue.value))?.name || currentFinancialYear.value.name || null,
  }, { preserveScroll: false });
};

const runGlobalSearch = () => {
  const q = globalSearch.value.trim();
  if (!q) return;
  window.location.href = normalizeUrl(`/app/sales/invoices?search=${encodeURIComponent(q)}`);
};

const showNotice = (text) => window.alert(text);
const logout = () => router.post(routeUrl('logout', '/logout'));

watchEffect(() => {
  selectedSectionKey.value = currentPageSection.value.key;
  selectedBusinessId.value = currentBusiness.value.id || '';
  selectedBranchId.value = currentBranch.value.id || '';
  selectedFinancialYearValue.value = currentFinancialYear.value.id || currentFinancialYear.value.name || '';
});
</script>
