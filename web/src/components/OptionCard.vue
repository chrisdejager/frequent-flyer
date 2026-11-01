<script setup lang="ts">
import { computed } from 'vue'
import type { Option } from '../types'
import { timezoneFor } from '../airports'
import { euro } from '../money'
import { bookingPageUrl } from '../booking'

const props = withDefaults(defineProps<{ option: Option; selected?: boolean }>(), { selected: false })
const emit = defineEmits<{ select: [] }>()

const time = (iso: string, airport: string) =>
  new Date(iso).toLocaleTimeString('nl-NL', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: timezoneFor(airport),
  })

interface CostRow {
  label: string
  cents: number
}

const rows = computed((): CostRow[] => [
  { label: 'Flight', cents: props.option.cost.basePrice },
])

const bookingUrl = computed(() => bookingPageUrl(props.option))

function openBooking() {
  if (bookingUrl.value) {
    window.open(bookingUrl.value, '_blank', 'noopener')
  }
}
</script>

<template>
  <article
    class="row"
    :class="{ selected }"
    role="button"
    tabindex="0"
    :aria-pressed="selected ? 'true' : 'false'"
    @click="emit('select')"
    @keydown.enter.prevent="emit('select')"
    @keydown.space.prevent="emit('select')"
  >
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
        <dd>{{ euro(row.cents) }}</dd>
      </div>
    </dl>

    <div class="total">
      <strong>{{ option.cost.totalFormatted }}</strong>
      <button v-if="bookingUrl" type="button" class="book" data-testid="book" @click.stop="openBooking" @keydown.stop>Book ↗</button>
    </div>
  </article>
</template>

<style scoped>
.row {
  display: grid;
  /* Narrow enough for half a column next to the other side. */
  grid-template-columns: minmax(5.5rem, 1fr) minmax(7rem, 1fr) minmax(10rem, 2fr) minmax(6rem, auto);
  align-items: center;
  gap: .75rem;
  border-bottom: 1px solid #eee;
  padding: .5rem .25rem;
  cursor: pointer;
}
.row.selected { background: #eef3ff; outline: 1px solid #c7d7ff; }
.row:focus-visible { outline: 2px solid #4a74d9; }
.flight { display: flex; flex-direction: column; }
.numbers { font-size: .8rem; color: #888; }
.route { display: flex; flex-direction: column; color: #555; font-size: .9rem; }
.stops { font-size: .8rem; color: #888; }

.breakdown { display: flex; flex-wrap: wrap; gap: 0; font-size: .85rem; color: #555; }
.breakdown div { display: flex; gap: .3rem; padding: 0 .6rem; border-left: 1px solid #ddd; }
.breakdown div:first-child { padding-left: 0; border-left: none; }
.breakdown dt, .breakdown dd { margin: 0; }

.total { display: flex; flex-direction: column; align-items: flex-end; text-align: right; font-size: 1.15rem; }

.book { margin-top: .25rem; font-size: .8rem; padding: .2rem .7rem; border: 1px solid #4a74d9; color: #4a74d9; background: #fff; border-radius: 4px; cursor: pointer; }
.book:hover { background: #4a74d9; color: #fff; }
</style>
