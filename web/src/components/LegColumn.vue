<script setup lang="ts">
import { computed } from 'vue'
import OptionCard from './OptionCard.vue'
import type { LegState, Option } from '../types'

const props = defineProps<{ title: string; leg: LegState }>()

// Opties met bekende treinprijs zijn gerangschikt op totaalprijs.
// Opties met onbekende treinprijs staan achteraan en zijn niet vergeleken.
const rankedOptions = computed<Option[]>(() =>
  props.leg.result?.options.filter(o => o.cost.trainCostKnown) ?? [],
)
const unrankedOptions = computed<Option[]>(() =>
  props.leg.result?.options.filter(o => !o.cost.trainCostKnown) ?? [],
)
</script>

<template>
  <section class="leg" data-testid="leg-column">
    <h2>{{ title }}</h2>

    <p v-if="leg.loading" class="sub">Zoeken…</p>
    <p v-if="leg.error" class="error">{{ leg.error }}</p>

    <template v-if="leg.result">
      <p v-if="leg.result.options.length === 0">
        Geen opties die aan de regels voldoen op deze datum.
      </p>

      <OptionCard
        v-for="(option, i) in rankedOptions"
        :key="'ranked-' + i"
        :option="option"
      />

      <template v-if="unrankedOptions.length > 0">
        <p class="unranked-notice" data-testid="unranked-notice">
          Niet gerangschikt — treinprijs onbekend
        </p>

        <OptionCard
          v-for="(option, i) in unrankedOptions"
          :key="'unranked-' + i"
          :option="option"
        />
      </template>
    </template>
  </section>
</template>

<style scoped>
h2 { font-size: 1rem; margin: 0 0 .5rem; }
.sub { font-size: .8rem; color: #888; }
.error { color: #b00020; }
.unranked-notice { color: #666; font-size: .85rem; margin: .75rem 0 .25rem; border-top: 1px solid #e0e0e0; padding-top: .5rem; }
</style>
