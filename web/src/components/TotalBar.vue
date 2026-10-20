<script setup lang="ts">
import { computed } from 'vue'
import { combine } from '../roundTrip'
import { euro } from '../money'
import type { Option } from '../types'

const props = defineProps<{
  out: Option | null
  back: Option | null
  backRequested: boolean
  /** True = the chosen options are the default choice (cheapest ranked). */
  isDefault: boolean
  /** A side is being searched: its amount is coming, not missing. */
  searching?: boolean
}>()

const combined = computed(() => combine(props.out, props.back))

const legLine = (option: Option) => `${option.carrierLabel} · ${option.flightNumbers.join(' · ')} · ${option.cost.totalFormatted}`
</script>

<template>
  <section class="totalbar" data-testid="total-bar">
    <div class="legs">
      <span v-if="out"><strong>Outbound</strong> {{ legLine(out) }}</span>
      <span v-if="back"><strong>Return</strong> {{ legLine(back) }}</span>
    </div>

    <div class="sum">
      <template v-if="searching">
        <strong data-testid="total-sum">…</strong>
        <small>Searching…</small>
      </template>
      <template v-else>
        <strong data-testid="total-sum">{{ euro(combined.totalCents) }}</strong>
        <small>{{ isDefault ? 'Cheapest combination' : 'Your choice' }} · booked separately</small>
        <small v-if="!backRequested">Outbound only</small>
        <small v-if="!out" class="warn">Outbound missing — not counted</small>
        <small v-if="backRequested && !back" class="warn">Return missing — not counted</small>
        <small v-if="combined.indicative" class="warn">indicative</small>
        <small v-if="combined.incomplete" class="warn">incomplete — train cost unknown</small>
        <small v-if="combined.excludesTransfer">excl. transfer HEL→TKU</small>
      </template>
    </div>
  </section>
</template>

<style scoped>
.totalbar {
  display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;
  background: #fff; border: 1px solid #ddd; border-radius: .5rem; padding: .75rem 1rem; margin-bottom: 1rem;
}
.legs { display: flex; flex-direction: column; gap: .15rem; font-size: .9rem; color: #444; }
.sum { display: flex; flex-direction: column; align-items: flex-end; text-align: right; }
.sum strong { font-size: 1.5rem; }
.sum small { font-size: .7rem; color: #888; }
.sum .warn { color: #a15c00; }
</style>
