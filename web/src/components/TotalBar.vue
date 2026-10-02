<script setup lang="ts">
// Tracer bullet: het echte totaal volgt in slice 2.
</script>

<template>
  <section class="totalbar" data-testid="total-bar">
    <span>Goedkoopste combinatie · los te boeken</span>
    <strong>€ —</strong>
  </section>
</template>

<style scoped>
.totalbar {
  display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;
  background: #fff; border: 1px solid #ddd; border-radius: .5rem; padding: .75rem 1rem; margin-bottom: 1rem;
}
.totalbar strong { font-size: 1.5rem; }
</style>
