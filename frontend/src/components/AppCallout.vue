<!--
  AppCallout.vue = un bandeau d'information avec une icône (le "Callout Component" du design).

  Trois variantes :
    info    bleu    (ex : "Ce fichier expirera dans 3 jours.")
    warning orange  (ex : "Ce fichier expirera demain.")
    error   rouge   (ex : "Ce fichier n'est plus disponible en téléchargement car il a expiré.")

  Le texte est le contenu placé entre les balises : <AppCallout variant="info">Mon texte</AppCallout>
  (une erreur est annoncée tout de suite aux lecteurs d'écran : role="alert").
-->
<script setup>
import { computed } from 'vue'
import { CircleAlert, Info, TriangleAlert } from 'lucide-vue-next'

const props = defineProps({
  variant: { type: String, default: 'info', validator: (v) => ['info', 'warning', 'error'].includes(v) },
})

const icons = { info: Info, warning: TriangleAlert, error: CircleAlert }
const icon = computed(() => icons[props.variant])
</script>

<template>
  <div class="callout" :class="`callout--${variant}`" :role="variant === 'error' ? 'alert' : 'status'">
    <component :is="icon" class="callout__icon" :size="16" aria-hidden="true" />
    <p class="callout__text"><slot /></p>
  </div>
</template>

<style scoped>
.callout {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  min-height: 32px;
  padding: 5px 8px;
  border: 1px solid;
  border-radius: 8px;
  font-size: 14px;
  line-height: 20px;
}

.callout__icon {
  flex-shrink: 0;
  margin-top: 2px; /* centre l'icône (16px) sur la première ligne de texte (20px) */
}

.callout--info {
  border-color: var(--info-border);
  background: var(--info-bg);
  color: var(--info-text);
}

.callout--warning {
  border-color: var(--warning-border);
  background: var(--warning-bg);
  color: var(--warning-text);
}

.callout--error {
  border-color: var(--error-border);
  background: var(--error-bg);
  color: var(--error-text);
}
</style>
