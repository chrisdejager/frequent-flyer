<script setup lang="ts">
import { computed } from 'vue'
import type { Option } from '../types'
import { timezoneFor } from '../airports'
import { euro } from '../money'

const props = defineProps<{ option: Option }>()


const time = (iso: string, airport: string) =>
  new Date(iso).toLocaleTimeString('nl-NL', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: timezoneFor(airport),
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
  <article class="row">
    <div class="flight">
      <strong>{{ option.carrierLabel }}</strong>
      <span class="numbers">{{ option.flightNumbers.join(' · ') }}</span>
    </div>

    <div class="route">
      <span class="times">{{ time(option.departAt, option.origin) }} → {{ time(option.arriveAt, option.destination) }}</span>
      <span v-if="option.stops > 0" class="stops">via {{ option.via }} ({{ option.connectionMinutes }} min)</span>
      <span v-else class="stops">direct</span>
    </div>

    <dl class="breakdown">
      <div v-for="row in rows" :key="row.label" data-testid="cost-row">
        <dt>{{ row.label }}</dt>
        <dd v-if="row.excluded" data-testid="train-excluded">niet meegerekend</dd>
        <dd v-else-if="row.unknown">onbekend</dd>
        <dd v-else>{{ euro(row.cents) }}</dd>
      </div>
    </dl>

    <div class="total">
      <strong>{{ option.cost.totalFormatted }}</strong>
      <em v-if="option.cost.indicative" data-testid="indicative">indicatief</em>
      <small
        v-if="!option.cost.trainCostIncluded"
        class="excluded-notice"
        data-testid="excluded-notice"
      >Totaal is exclusief transfer HEL→TKU</small>
    </div>
  </article>
</template>

<style scoped>
.row {
  display: grid;
  grid-template-columns: minmax(7rem, 1fr) minmax(9rem, 1fr) minmax(14rem, 2fr) minmax(7rem, auto);
  align-items: center;
  gap: 1rem;
  border-bottom: 1px solid #eee;
  padding: .5rem 0;
}
.flight { display: flex; flex-direction: column; }
.numbers { font-size: .8rem; color: #888; }
.route { display: flex; flex-direction: column; color: #555; font-size: .9rem; }
.stops { font-size: .8rem; color: #888; }

.breakdown { display: flex; flex-wrap: wrap; gap: 0; font-size: .85rem; color: #555; }
.breakdown div { display: flex; gap: .3rem; padding: 0 .6rem; border-left: 1px solid #ddd; }
.breakdown div:first-child { padding-left: 0; border-left: none; }
.breakdown dt, .breakdown dd { margin: 0; }

.total { display: flex; flex-direction: column; align-items: flex-end; text-align: right; font-size: 1.15rem; }
.total em { font-size: .75rem; color: #a15c00; font-style: normal; }
.excluded-notice { font-size: .7rem; color: #888; font-weight: normal; }
</style>
