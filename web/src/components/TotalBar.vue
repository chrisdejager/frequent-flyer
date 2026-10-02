<script setup lang="ts">
import { computed } from 'vue'
import { combine } from '../roundTrip'
import { euro } from '../money'
import type { Option } from '../types'

const props = defineProps<{
  out: Option | null
  back: Option | null
  backRequested: boolean
  /** True = de gekozen opties zijn de standaardkeuze (goedkoopste gerangschikte). */
  isDefault: boolean
}>()

const combined = computed(() => combine(props.out, props.back))

const legLine = (option: Option) => `${option.carrierLabel} · ${option.flightNumbers.join(' · ')} · ${option.cost.totalFormatted}`
</script>

<template>
  <section class="totalbar" data-testid="total-bar">
    <div class="legs">
      <span v-if="out"><strong>Heen</strong> {{ legLine(out) }}</span>
      <span v-if="back"><strong>Terug</strong> {{ legLine(back) }}</span>
    </div>

    <div class="sum">
      <strong data-testid="total-sum">{{ euro(combined.totalCents) }}</strong>
      <small>{{ isDefault ? 'Goedkoopste combinatie' : 'Jouw keuze' }} · los te boeken</small>
      <small v-if="!backRequested">Alleen heenreis</small>
      <small v-if="combined.indicative" class="warn">indicatief</small>
      <small v-if="combined.incomplete" class="warn">onvolledig — treinprijs onbekend</small>
      <small v-if="combined.excludesTransfer">exclusief transfer HEL→TKU</small>
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
