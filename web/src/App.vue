<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { plannedTrips, search } from './api'
import OptionCard from './components/OptionCard.vue'
import type { Direction, Option, SearchResult, Trip, TravellerId } from './types'

const date = ref('2026-10-29')
const direction = ref<Direction>('nl-fi')
const traveller = ref<TravellerId>('partner')

const trips = ref<Trip[]>([])
const result = ref<SearchResult | null>(null)
const error = ref('')
const loading = ref(false)

onMounted(async () => {
  trips.value = await plannedTrips()
})

function pick(trip: Trip) {
  date.value = trip.date
  direction.value = trip.direction
  traveller.value = trip.traveller
}

const label = (trip: Trip) =>
  `${new Date(trip.date).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', timeZone: 'UTC' })} ` +
  `${trip.direction === 'nl-fi' ? '→ Finland' : '→ Nederland'}`

async function run() {
  loading.value = true
  error.value = ''

  try {
    result.value = await search(date.value, direction.value, traveller.value)
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
    result.value = null
  } finally {
    loading.value = false
  }
}

// Opties met bekende treinprijs zijn gerangschikt op totaalprijs.
// Opties met onbekende treinprijs staan achteraan en zijn niet vergeleken.
const rankedOptions = computed<Option[]>(() =>
  result.value?.options.filter(o => o.cost.trainCostKnown) ?? [],
)
const unrankedOptions = computed<Option[]>(() =>
  result.value?.options.filter(o => !o.cost.trainCostKnown) ?? [],
)
</script>

<template>
  <main>
    <h1>Leiden ↔ Turku</h1>

    <nav v-if="trips.length > 0" class="trips">
      <span>Gepland:</span>
      <button v-for="trip in trips" :key="trip.date" type="button" @click="pick(trip)">
        {{ label(trip) }}
      </button>
    </nav>

    <form @submit.prevent="run">
      <input v-model="date" type="date" required />

      <select v-model="direction">
        <option value="nl-fi">Nederland → Finland</option>
        <option value="fi-nl">Finland → Nederland</option>
      </select>

      <select v-model="traveller">
        <option value="chris">Chris</option>
        <option value="partner">Partner</option>
      </select>

      <button type="submit" :disabled="loading">
        {{ loading ? 'Zoeken…' : 'Zoek' }}
      </button>
    </form>

    <p v-if="error" class="error">{{ error }}</p>

    <template v-if="result">
      <p class="traveller-label">Prijzen voor {{ result.traveller.name }}</p>

      <p v-if="result.options.length === 0">
        Geen opties die aan de regels voldoen op deze datum.
      </p>

      <p v-if="unrankedOptions.length > 0" class="unranked-notice" data-testid="unranked-notice">
        Niet gerangschikt — treinprijs onbekend
      </p>

      <OptionCard
        v-for="(option, i) in rankedOptions"
        :key="'ranked-' + i"
        :option="option"
      />

      <template v-if="unrankedOptions.length > 0">
        <OptionCard
          v-for="(option, i) in unrankedOptions"
          :key="'unranked-' + i"
          :option="option"
        />
      </template>
    </template>
  </main>
</template>

<style>
body { font-family: system-ui, sans-serif; margin: 0; background: #fafafa; }
main { max-width: 42rem; margin: 0 auto; padding: 1.5rem; }
form { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
input, select, button { padding: .5rem; font: inherit; }
.trips { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; margin-bottom: .75rem; }
.trips span { color: #666; font-size: .9rem; }
.trips button { background: #eef3ff; border: 1px solid #c7d7ff; border-radius: 999px; cursor: pointer; }
.error { color: #b00020; }
.traveller-label { font-size: .85rem; color: #555; margin-bottom: .75rem; }
.unranked-notice { color: #666; font-size: .85rem; margin: .75rem 0 .25rem; border-top: 1px solid #e0e0e0; padding-top: .5rem; }
</style>
