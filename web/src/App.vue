<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { plannedTrips, search } from './api'
import LegColumn from './components/LegColumn.vue'
import TotalBar from './components/TotalBar.vue'
import { chosenOption, defaultSelection, validateDates } from './roundTrip'
import type { Direction, LegState, Trip, TravellerId } from './types'

const traveller = ref<TravellerId>('partner')

const emptyLeg = (direction: Direction, date: string): LegState =>
  ({ direction, date, result: null, error: '', loading: false, selected: null })

const out = reactive<LegState>(emptyLeg('nl-fi', '2026-10-29'))
const back = reactive<LegState>(emptyLeg('fi-nl', ''))

const trips = ref<Trip[]>([])
const formError = ref('')
// De datums van de laatste zoekactie: kolomkoppen en de terugkolom volgen
// wat er gezocht is, niet wat er sindsdien in het formulier is getypt.
const searched = ref<{ out: string; back: string } | null>(null)

onMounted(async () => {
  trips.value = await plannedTrips()
})

function pick(trip: Trip) {
  if (trip.direction === out.direction) {
    out.date = trip.date
  } else {
    back.date = trip.date
  }
  traveller.value = trip.traveller
}

const label = (trip: Trip) =>
  `${new Date(trip.date).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', timeZone: 'UTC' })} ` +
  `${trip.direction === 'nl-fi' ? '→ Finland' : '→ Nederland'}`

const dayLabel = (date: string) =>
  new Date(date).toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'long', timeZone: 'UTC' })

async function runLeg(leg: LegState) {
  leg.loading = true
  leg.error = ''

  try {
    leg.result = await search(leg.date, leg.direction, traveller.value)
    leg.selected = defaultSelection(leg.result)
  } catch (e) {
    leg.error = e instanceof Error ? e.message : String(e)
    leg.result = null
    leg.selected = null
  } finally {
    leg.loading = false
  }
}

async function run() {
  formError.value = validateDates(out.date, back.date) ?? ''

  if (formError.value) {
    searched.value = null
    return
  }

  searched.value = { out: out.date, back: back.date }
  Object.assign(back, { result: null, error: '', selected: null })

  // Beide kanten tegelijk: twee losse enkele reizen, elk met hun eigen uitkomst.
  // Zonder terugdatum is het een enkele reis: één call.
  await Promise.allSettled(back.date ? [runLeg(out), runLeg(back)] : [runLeg(out)])
}

const loading = () => out.loading || back.loading

const isDefaultLeg = (leg: LegState) =>
  leg.selected === (leg.result ? defaultSelection(leg.result) : null)

const isDefault = computed(() =>
  isDefaultLeg(out) && (!searched.value?.back || isDefaultLeg(back)),
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
      <label>Heen (NL → FI)
        <input v-model="out.date" type="date" required data-testid="out-date" />
      </label>

      <label>Terug (FI → NL) — optioneel
        <input v-model="back.date" type="date" data-testid="back-date" />
      </label>

      <label>Reiziger
        <select v-model="traveller">
          <option value="chris">Chris</option>
          <option value="partner">Partner</option>
        </select>
      </label>

      <button type="submit" :disabled="loading()">
        {{ loading() ? 'Zoeken…' : 'Zoek' }}
      </button>
    </form>

    <p v-if="formError" class="error" data-testid="form-error">{{ formError }}</p>

    <template v-if="searched">
      <p v-if="out.result" class="traveller-label">Prijzen voor {{ out.result.traveller.name }}</p>

      <TotalBar
        :out="chosenOption(out)"
        :back="chosenOption(back)"
        :back-requested="searched.back !== ''"
        :is-default="isDefault"
      />

      <div class="columns">
        <LegColumn :title="`Heen — ${dayLabel(searched.out)}`" :leg="out" @select="i => (out.selected = i)" />
        <LegColumn
          v-if="searched.back"
          :title="`Terug — ${dayLabel(searched.back)}`"
          :leg="back"
          @select="i => (back.selected = i)"
        />
      </div>
    </template>
  </main>
</template>

<style>
body { font-family: system-ui, sans-serif; margin: 0; background: #fafafa; }
main { max-width: 76rem; margin: 0 auto; padding: 1.5rem; }
form { display: flex; gap: .75rem; flex-wrap: wrap; align-items: end; margin-bottom: 1.5rem; }
label { display: flex; flex-direction: column; font-size: .75rem; color: #666; gap: .2rem; }
input, select, button { padding: .5rem; font: inherit; }
.trips { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; margin-bottom: .75rem; }
.trips span { color: #666; font-size: .9rem; }
.trips button { background: #eef3ff; border: 1px solid #c7d7ff; border-radius: 999px; cursor: pointer; }
.traveller-label { font-size: .85rem; color: #555; margin-bottom: .75rem; }
.error { color: #b00020; }
.columns { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1.5rem; }
@media (max-width: 70rem) { .columns { grid-template-columns: 1fr; } }
</style>
