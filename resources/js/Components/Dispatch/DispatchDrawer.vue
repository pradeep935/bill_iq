<template>
  <teleport to="body">
    <div v-if="open" class="drawer-backdrop" @click.self="$emit('close')">
      <aside class="dispatch-drawer">
        <header>
          <div><span>DISPATCH DETAILS</span><h2>{{ dispatch?.number || 'Dispatch' }}</h2></div>
          <button type="button" title="Close dispatch details" @click="$emit('close')">Close</button>
        </header>
        <div v-if="loading" class="drawer-loading">Loading dispatch details...</div>
        <template v-else-if="dispatch">
          <section class="drawer-card">
            <h3>Customer Information</h3>
            <div class="drawer-grid"><div><span>Customer</span><strong>{{ dispatch.customer || '-' }}</strong></div><div><span>Mobile</span><strong>{{ dispatch.mobile || '-' }}</strong></div><div><span>Delivery Address</span><strong>{{ dispatch.delivery_address || '-' }}</strong></div></div>
          </section>
          <section class="drawer-card">
            <h3>Invoice Details</h3>
            <div class="drawer-grid"><div><span>Invoice</span><strong>{{ dispatch.invoice_number || '-' }}</strong></div><div><span>Order</span><strong>{{ dispatch.order_number || '-' }}</strong></div><div><span>Date</span><strong>{{ dispatch.date || '-' }}</strong></div><div><span>Branch</span><strong>{{ dispatch.branch || '-' }}</strong></div><div><span>Warehouse</span><strong>{{ dispatch.warehouse || '-' }}</strong></div></div>
          </section>
          <section class="drawer-card">
            <h3>Transporter Details</h3>
            <div class="drawer-grid"><div><span>Transporter</span><strong>{{ dispatch.transporter || '-' }}</strong></div><div><span>Vehicle</span><strong>{{ dispatch.vehicle_number || '-' }}</strong></div><div><span>Driver</span><strong>{{ dispatch.driver_name || '-' }}</strong></div><div><span>Driver Mobile</span><strong>{{ dispatch.driver_mobile || '-' }}</strong></div><div><span>LR Number</span><strong>{{ dispatch.lr_number || '-' }}</strong></div><div><span>E-Way Bill</span><strong>{{ dispatch.e_way_bill_number || '-' }}</strong></div></div>
          </section>
          <section class="drawer-card">
            <h3>Product List</h3>
            <div class="drawer-table">
              <table><thead><tr><th>Product</th><th>Batch</th><th>Quantity</th><th>Picked</th><th>Packed</th><th>Remaining</th></tr></thead><tbody><tr v-for="(item,index) in dispatch.items || []" :key="index"><td><strong>{{ item.product || '-' }}</strong><small>{{ item.sku || '-' }}</small></td><td>{{ item.batch || '-' }}</td><td>{{ item.quantity }}</td><td>{{ item.picked_quantity }}</td><td>{{ item.packed_quantity }}</td><td>{{ item.remaining_quantity }}</td></tr></tbody></table>
            </div>
          </section>
        </template>
      </aside>
    </div>
  </teleport>
</template>

<script setup>
defineProps({ open: Boolean, loading: Boolean, dispatch: { type: Object, default: null } });
defineEmits(['close']);
</script>

<style scoped>
.drawer-backdrop{position:fixed;inset:0;z-index:1000;display:flex;justify-content:flex-end;background:rgba(15,23,42,.36)}.dispatch-drawer{width:min(760px,100vw);height:100%;overflow:auto;background:#f8fafc;border-left:1px solid #dfe6ef;box-shadow:-18px 0 42px rgba(15,23,42,.18);padding:18px}.dispatch-drawer header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}.dispatch-drawer header span{color:#2457d6;font-size:10px;font-weight:900;letter-spacing:.08em}.dispatch-drawer h2{margin:2px 0 0;color:#142139}.dispatch-drawer button{min-height:36px;padding:8px 10px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;font-size:12px;font-weight:850}.drawer-card{margin-bottom:12px;padding:14px;background:#fff;border:1px solid #dfe6ef;border-radius:8px}.drawer-card h3{margin:0 0 10px;color:#142139;font-size:14px}.drawer-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.drawer-grid div{padding:9px;border:1px solid #edf1f5;border-radius:8px;background:#fbfdff}.drawer-grid span{display:block;color:#69758a;font-size:10px;font-weight:900;text-transform:uppercase}.drawer-grid strong{display:block;margin-top:3px;color:#26344d;font-size:12px}.drawer-table{overflow:auto;border:1px solid #edf1f5;border-radius:8px}table{width:100%;min-width:640px;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap;font-size:12px}th{background:#f8fafc;color:#69758a;font-size:10px;text-transform:uppercase}small{display:block;color:#7a869a}.drawer-loading{padding:20px;color:#536174;font-weight:800}@media(max-width:720px){.drawer-grid{grid-template-columns:1fr}}
</style>
