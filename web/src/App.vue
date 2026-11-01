<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { search } from './api'
import LegColumn from './components/LegColumn.vue'
import TotalBar from './components/TotalBar.vue'
import { chosenOption, defaultSelection, legDirections, shiftedDate, validateDates } from './roundTrip'
import type { Direction, LegState, StartCountry, TravellerId } from './types'

// No traveller choice: without baggage costs (ADR-0005) both get the same
// price. The API still takes one, so baggage can come back without an API change.
const traveller: TravellerId = 'partner'
const start = ref<StartCountry>('nl')
const directions = computed(() => legDirections(start.value))

const arrow = (direction: Direction) => (direction === 'nl-fi' ? 'NL → FI' : 'FI → NL')

const emptyLeg = (direction: Direction, date: string): LegState =>
  ({ direction, date, result: null, error: '', loading: false, selected: null })

// Local date: the picker and "today" are the user's, not UTC.
const today = () => new Intl.DateTimeFormat('en-CA').format(new Date())

const out = reactive<LegState>(emptyLeg('nl-fi', today()))
const back = reactive<LegState>(emptyLeg('fi-nl', ''))

// The return picker starts at the outbound date (via :min); an outbound moved past
// the return takes it along. An empty return stays empty: one-way stays one-way.
watch(() => out.date, date => {
  if (back.date !== '' && back.date < date) {
    back.date = date
  }
})

const formError = ref('')
// The dates from the last search: column headers and the return column follow
// what was searched, not what has been typed in the form since.
const searched = ref<{ out: string; back: string } | null>(null)

const dayLabel = (date: string) =>
  new Date(date).toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'long', timeZone: 'UTC' })

async function runLeg(leg: LegState) {
  // Clear at once: old results under a new date header are misleading.
  leg.loading = true
  leg.error = ''
  leg.result = null
  leg.selected = null

  try {
    leg.result = await search(leg.date, leg.direction, traveller)
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
  out.direction = directions.value.out
  Object.assign(back, { direction: directions.value.back, result: null, error: '', selected: null })

  // Both sides simultaneously: two separate one-way trips, each with their own result.
  // Without a return date, it's a one-way trip: one call.
  await Promise.allSettled(back.date ? [runLeg(out), runLeg(back)] : [runLeg(out)])
}

const loading = () => out.loading || back.loading

// A day earlier or later for one leg: searches only that leg, from the date that
// was searched (not what has been typed into the form since). One ignav call
// per click, none in advance.
const shiftTo = (leg: 'out' | 'back', days: number) =>
  searched.value && !loading() ? shiftedDate(searched.value, leg, days, today()) : null

async function shift(leg: 'out' | 'back', days: number) {
  const date = shiftTo(leg, days)

  if (date === null || searched.value === null) {
    return
  }

  searched.value = { ...searched.value, [leg]: date }
  const state = leg === 'out' ? out : back
  state.date = date
  await runLeg(state)
}

const isDefaultLeg = (leg: LegState) =>
  leg.selected === (leg.result ? defaultSelection(leg.result) : null)

const isDefault = computed(() =>
  isDefaultLeg(out) && (!searched.value?.back || isDefaultLeg(back)),
)
</script>

<template>
  <main>
    <h1>Leiden ↔ Turku</h1>

    <form @submit.prevent="run">
      <label>Departing from
        <select v-model="start" data-testid="start">
          <option value="nl">Netherlands</option>
          <option value="fi">Finland</option>
        </select>
      </label>

      <label>Outbound ({{ arrow(directions.out) }})
        <input v-model="out.date" type="date" required data-testid="out-date" />
      </label>

      <label>Return ({{ arrow(directions.back) }}) — optional
        <input v-model="back.date" type="date" :min="out.date" data-testid="back-date" />
      </label>

      <button type="submit" :disabled="loading()">
        {{ loading() ? 'Searching…' : 'Search' }}
      </button>
    </form>

    <p v-if="formError" class="error" data-testid="form-error">{{ formError }}</p>

    <template v-if="searched">
      <TotalBar
        :out="chosenOption(out)"
        :back="chosenOption(back)"
        :back-requested="searched.back !== ''"
        :is-default="isDefault"
        :searching="loading()"
      />

      <div class="columns">
        <LegColumn
          :title="`Outbound — ${dayLabel(searched.out)}`"
          :leg="out"
          :can-prev="shiftTo('out', -1) !== null"
          :can-next="shiftTo('out', 1) !== null"
          @select="i => (out.selected = i)"
          @shift="d => shift('out', d)"
        />
        <LegColumn
          v-if="searched.back"
          :title="`Return — ${dayLabel(searched.back)}`"
          :leg="back"
          :can-prev="shiftTo('back', -1) !== null"
          :can-next="shiftTo('back', 1) !== null"
          @select="i => (back.selected = i)"
          @shift="d => shift('back', d)"
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
.error { color: #b00020; }
.columns { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1.5rem; }
@media (max-width: 70rem) { .columns { grid-template-columns: 1fr; } }
</style>
