<script setup lang="ts">
import { computed } from 'vue'
import type { Option } from '../types'

const props = defineProps<{ option: Option }>()

const euro = (cents: number) => `€ ${(cents / 100).toFixed(2).replace('.', ',')}`

const time = (iso: string) =>
  new Date(iso).toLocaleTimeString('nl-NL', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'UTC',
  })

interface CostRow {
  label: string
  cents: number
  unknown?: boolean
  /** Bewust niet meegeteld — anders dan "onbekend", want de optie blijft gerangschikt. */
  excluded?: boolean
}

const rows = computed(() => {
  const r: CostRow[] = [
    { label: 'Vlucht', cents: props.option.cost.basePrice },
    { label: 'Bagage', cents: props.option.cost.baggageCost },
  ]

  if (props.option.pattern === 'via_hel_train') {
    r.push({
      label: 'Trein HEL–TKU',
      cents: props.option.cost.trainCost,
      unknown: !props.option.cost.trainCostKnown,
      excluded: !props.option.cost.trainCostIncluded,
    })
  }

  return r
})
</script>

<template>
  <article class="card">
    <header>
      <strong>{{ option.carrierLabel }}</strong>
      <span>{{ time(option.departAt) }} → {{ time(option.arriveAt) }}</span>
      <span v-if="option.stops > 0">
        via {{ option.via }} ({{ option.connectionMinutes }} min)
      </span>
      <span v-else>direct</span>
    </header>

    <p class="total">
      {{ option.cost.totalFormatted }}
      <em v-if="option.cost.indicative" data-testid="indicative">indicatief</em>
    </p>

    <dl>
      <div v-for="row in rows" :key="row.label" data-testid="cost-row">
        <dt>{{ row.label }}</dt>
        <dd v-if="row.excluded" data-testid="train-excluded">niet meegerekend</dd>
        <dd v-else-if="row.unknown">onbekend</dd>
        <dd v-else>{{ euro(row.cents) }}</dd>
      </div>
    </dl>

    <p v-if="!option.cost.trainCostIncluded" class="excluded-notice" data-testid="excluded-notice">
      Totaal is exclusief transfer HEL→TKU
    </p>

    <footer>{{ option.flightNumbers.join(' · ') }}</footer>
  </article>
</template>

<style scoped>
.card { border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin-bottom: .75rem; }
header { display: flex; gap: .75rem; flex-wrap: wrap; color: #555; }
.total { font-size: 1.5rem; margin: .5rem 0; }
.total em { font-size: .8rem; color: #a15c00; font-style: normal; }
dl div { display: flex; justify-content: space-between; border-top: 1px solid #f0f0f0; padding: .2rem 0; }
dt, dd { margin: 0; }
footer { margin-top: .5rem; color: #888; font-size: .85rem; }
</style>
