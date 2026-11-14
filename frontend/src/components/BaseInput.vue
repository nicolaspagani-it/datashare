<!--
  BaseInput.vue = un champ de formulaire avec son label (le "Input Component" du design).

  Il s'utilise avec v-model, comme un champ de formulaire Symfony :
    <BaseInput v-model="email" label="Email" type="email" />

  Les attributs non déclarés (required, minlength, autocomplete, placeholder...) sont
  automatiquement transmis au <input> grâce à "inheritAttrs: false" + v-bind="$attrs"
  (par défaut Vue les mettrait sur la balise racine <div>, pas sur l'<input>).
-->
<script setup>
import { useId } from 'vue'

defineOptions({ inheritAttrs: false })

defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  type: { type: String, default: 'text' },
})
defineEmits(['update:modelValue'])

// Identifiant unique, pour relier <label for="..."> et <input id="..."> (accessibilité :
// cliquer sur le label met le focus dans le champ).
const id = useId()
</script>

<template>
  <div class="field">
    <label class="field__label" :for="id">{{ label }}</label>
    <input
      :id="id"
      class="field__input"
      :type="type"
      :value="modelValue"
      v-bind="$attrs"
      @input="$emit('update:modelValue', $event.target.value)"
    />
  </div>
</template>

<style scoped>
.field {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.field__label {
  font-size: 14px;
  line-height: 24px;
}

.field__input {
  height: 40px;
  padding: 12px;
  border: 1px solid rgba(0, 0, 0, 0.15);
  border-radius: 8px;
  background: #fff;
  color: var(--text);
  font-size: 16px;
  line-height: 16px;
}

.field__input::placeholder {
  color: rgba(0, 0, 0, 0.3);
}

.field__input:focus {
  outline: 2px solid var(--accent-border);
  outline-offset: 0;
  border-color: var(--accent-border);
}
</style>
