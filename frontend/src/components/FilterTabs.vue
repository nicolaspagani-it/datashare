<!--
  FilterTabs.vue = le sélecteur "Tous / Actifs / Expiré" (le "Switch Component" du design).

  Il utilise v-model : le composant parent écrit <FilterTabs v-model="filter" />
  et reçoit la valeur choisie, sans écrire de code de synchronisation.
  En coulisses, v-model = la prop "modelValue" (valeur actuelle) + l'événement
  "update:modelValue" (émis quand l'utilisateur clique).
-->
<script setup>
defineProps({
  modelValue: { type: String, required: true },
  options: { type: Array, required: true }, // [{ value: 'all', label: 'Tous' }, ...]
})
defineEmits(['update:modelValue'])
</script>

<template>
  <div class="tabs" role="tablist">
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      role="tab"
      class="tabs__item"
      :class="{ 'tabs__item--active': option.value === modelValue }"
      :aria-selected="option.value === modelValue"
      @click="$emit('update:modelValue', option.value)"
    >
      {{ option.label }}
    </button>
  </div>
</template>

<style scoped>
.tabs {
  display: flex;
  align-items: center;
  overflow: hidden;
  border: 1px solid var(--border-row);
  border-radius: 24px;
  background: var(--tab-bg);
}

.tabs__item {
  padding: 7px 16px; /* 7 + 16 + 7 = 30, + 2px de bordure du conteneur = 32px comme Figma */
  border: 0;
  background: transparent;
  color: var(--text);
  font-size: 16px;
  line-height: 16px;
  cursor: pointer;
}

.tabs__item--active {
  background: var(--tab-active);
  color: #fff;
}

/* Sur mobile le sélecteur occupe toute la largeur, chaque onglet pareil */
@media (max-width: 767px) {
  .tabs {
    width: 100%;
  }

  .tabs__item {
    flex: 1;
  }
}

/* Sur desktop il garde sa largeur naturelle */
@media (min-width: 768px) {
  .tabs {
    width: fit-content;
  }
}
</style>
