<!--
  SiteHeader.vue = l'en-tête des pages publiques (le "Header Component" du design).
  Le bouton à droite change selon l'état :
    - visiteur non connecté -> "Se connecter"
    - utilisateur connecté   -> "Mon espace"
  (les deux variantes "Anonymous" / "Logged" du composant Figma)
-->
<script setup>
import BaseButton from './BaseButton.vue'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
</script>

<template>
  <header class="site-header">
    <RouterLink :to="{ name: 'home' }" class="site-header__logo">DataShare</RouterLink>

    <!-- v-if / v-else : un seul des deux boutons est affiché (comme {% if %}{% else %} Twig) -->
    <BaseButton v-if="auth.isAuthenticated" variant="dark" size="lg" :to="{ name: 'my-space' }">Mon espace</BaseButton>
    <BaseButton v-else variant="dark" size="lg" :to="{ name: 'login' }">Se connecter</BaseButton>
  </header>
</template>

<style scoped>
.site-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
  padding: 16px;
}

.site-header__logo {
  color: var(--text);
  font-weight: 700;
  font-size: 32px;
  line-height: 40px;
  text-decoration: none;
}
</style>
