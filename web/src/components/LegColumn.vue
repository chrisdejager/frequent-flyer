<script setup lang="ts">
import { computed } from 'vue'
import OptionCard from './OptionCard.vue'
import { pricesAsOf } from '../freshness'
import type { LegState } from '../types'

const props = withDefaults(
  defineProps<{ title: string; leg: LegState; canPrev?: boolean; canNext?: boolean }>(),
  { canPrev: false, canNext: false },
)
const emit = defineEmits<{ select: [index: number]; shift: [days: number] }>()

const asOf = computed(() => pricesAsOf(props.leg.result?.options ?? [], new Date()))

const asOfLabel = (at: string) =>
  new Date(at).toLocaleString('nl-NL', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
</script>

<template>
  <section class="leg" data-testid="leg-column">
    <h2>
      <button type="button" class="shift" aria-label="Previous day" data-testid="prev-day" :disabled="!canPrev" @click="emit('shift', -1)">‹</button>
      {{ title }}
      <button type="button" class="shift" aria-label="Next day" data-testid="next-day" :disabled="!canNext" @click="emit('shift', 1)">›</button>
    </h2>

    <p v-if="leg.loading" class="sub">Searching…</p>
    <p v-if="leg.error" class="error">{{ leg.error }}</p>

    <template v-if="leg.result">
      <p v-if="asOf" class="sub" data-testid="prices-as-of">
        Prices as of {{ asOfLabel(asOf.observedAt) }}<template v-if="asOf.hoursOld > 0"> · {{ asOf.hoursOld }} h old</template>
      </p>

      <p v-if="leg.result.options.length === 0">
        No options meeting the criteria on this date.
      </p>

      <OptionCard
        v-for="(option, index) in leg.result.options"
        :key="index"
        :option="option"
        :selected="index === leg.selected"
        @select="emit('select', index)"
      />
    </template>
  </section>
</template>

<style scoped>
h2 { font-size: 1rem; margin: 0 0 .5rem; display: flex; align-items: center; gap: .5rem; }
.shift { padding: .1rem .55rem; font-size: 1.1rem; line-height: 1; cursor: pointer; }
.shift:disabled { cursor: default; opacity: .35; }
.sub { font-size: .8rem; color: #888; }
.error { color: #b00020; }
</style>
