<!--
  BaseInput.vue = un champ de formulaire avec son label et ses messages d'erreur
  (le "Input Component" du design).

  Il s'utilise avec v-model, comme un champ de formulaire Symfony :
    <BaseInput v-model="email" label="Email" type="email" :errors="['Adresse invalide']" />

  "errors" : la liste des messages à afficher SOUS le champ (vide = aucune erreur).
  Quand il y en a, le champ passe en rouge et est marqué comme invalide pour les
  lecteurs d'écran (aria-invalid + aria-describedby).

  Les attributs non déclarés (required, minlength, autocomplete, placeholder...) sont
  automatiquement transmis au <input> grâce à "inheritAttrs: false" + v-bind="$attrs"
  (par défaut Vue les mettrait sur la balise racine <div>, pas sur l'<input>).
-->
<script setup>
import { computed, useId } from 'vue'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  type: { type: String, default: 'text' },
  errors: { type: Array, default: () => [] },
})
defineEmits(['update:modelValue'])

// Identifiant unique, pour relier <label for="..."> et <input id="..."> (accessibilité :
// cliquer sur le label met le focus dans le champ).
const id = useId()
const errorId = `${id}-error`
const hasErrors = computed(() => props.errors.length > 0)
</script>

<template>
  <div class="field">
    <label class="field__label" :for="id">{{ label }}</label>
    <input
      :id="id"
      class="field__input"
      :class="{ 'field__input--invalid': hasErrors }"
      :type="type"
      :value="modelValue"
      :aria-invalid="hasErrors ? 'true' : undefined"
      :aria-describedby="hasErrors ? errorId : undefined"
      v-bind="$attrs"
      @input="$emit('update:modelValue', $event.target.value)"
    />
    <!-- Un paragraphe par message d'erreur, juste sous le champ -->
    <div v-if="hasErrors" :id="errorId" class="field__errors" role="alert">
      <p v-for="message in errors" :key="message" class="field__error">{{ message }}</p>
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
  font-size: 16px; /* même taille que le label du Select dans le design */
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

.field__input--invalid,
.field__input--invalid:focus {
  border-color: var(--danger);
  outline-color: var(--danger);
}

.field__errors {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.field__error {
  color: var(--danger);
  font-size: 14px;
  line-height: 20px;
}
</style>
