<template>
  <section class="listing-card">
    <slot></slot>
  </section>
</template>

<style scoped>
.listing-card {
  background: #fff;
  border: 1px solid #dfe6ef;
  border-radius: 12px;
  box-shadow: 0 10px 28px rgba(25, 50, 84, .04);
  overflow: visible;
  padding: 18px;
}
</style>
