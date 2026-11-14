<!--
  BaseSelect.vue = une liste déroulante avec son label (le "Select Component" du design).

  Comme BaseInput, il s'utilise avec v-model :
    <BaseSelect v-model="expiration" label="Expiration" :options="[{ value: '1d', label: 'Une journée' }]" />

  C'est un vrai <select> natif (accessible au clavier, adapté au mobile) :
  on cache seulement sa flèche d'origine pour afficher celle du design (ChevronDown).
-->
<script setup>
import { useId } from 'vue'
import { ChevronDown } from 'lucide-vue-next'

defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  options: { type: Array, required: true }, // [{ value: '1d', label: 'Une journée' }, ...]
})
defineEmits(['update:modelValue'])

const id = useId()
</script>

<template>
  <div class="field">
    <label class="field__label" :for="id">{{ label }}</label>
    <div class="field__wrapper">
      <select :id="id" class="field__select" :value="modelValue" @change="$emit('update:modelValue', $event.target.value)">
        <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
      </select>
      <ChevronDown class="field__chevron" :size="16" aria-hidden="true" />
    </div>
  </div>
</template>

<style scoped>
.field {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.field__label {
  font-size: 16px;
  line-height: 24px;
}

.field__wrapper {
  position: relative;
}

.field__select {
  width: 100%;
  height: 40px;
  padding: 0 40px 0 12px;
  border: 1px solid rgba(0, 0, 0, 0.15);
  border-radius: 8px;
  background: #fff;
  color: var(--text);
  font-size: 16px;
  appearance: none; /* masque la flèche native, remplacée par le chevron ci-dessous */
  cursor: pointer;
}

.field__select:focus {
  outline: 2px solid var(--accent-border);
  border-color: var(--accent-border);
}

.field__chevron {
  position: absolute;
  top: 50%;
  right: 12px;
  transform: translateY(-50%);
  pointer-events: none; /* le clic traverse l'icône et ouvre bien la liste */
}
</style>
