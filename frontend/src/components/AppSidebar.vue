<!--
  AppSidebar.vue = le menu latéral orange.
    - Desktop : colonne fixe de 259px à gauche.
    - Mobile  : tiroir qui glisse depuis la gauche, avec un fond sombre derrière.

  Prop "open" : le tiroir est-il ouvert ? (utile seulement sur mobile)
  Événement "close" : demandé par le composant quand on clique sur la croix ou le fond.
-->
<script setup>
import { X } from 'lucide-vue-next'

defineProps({ open: { type: Boolean, default: false } })
defineEmits(['close'])
</script>

<template>
  <!-- Fond sombre derrière le tiroir (mobile uniquement) -->
  <div v-if="open" class="overlay" @click="$emit('close')" />

  <aside class="sidebar" :class="{ 'sidebar--open': open }">
    <div class="sidebar__brand">
      <button type="button" class="sidebar__close" aria-label="Fermer le menu" @click="$emit('close')">
        <X :size="24" />
      </button>
      <span class="sidebar__logo">DataShare</span>
    </div>

    <nav class="sidebar__nav">
      <!-- active-class : classe ajoutée automatiquement quand l'URL correspond à ce lien -->
      <RouterLink :to="{ name: 'my-space' }" class="nav-item" active-class="nav-item--active" @click="$emit('close')">
        Mes fichiers
      </RouterLink>
    </nav>

    <p class="sidebar__copyright">Copyright DataShare<small>©</small> 2025</p>
  </aside>
</template>

<style scoped>
.sidebar {
  display: flex;
  flex-direction: column;
  width: 259px;
  flex-shrink: 0;
  background: var(--gradient);
  border-right: 2px solid rgba(98, 54, 26, 0.15);
  color: #fff;
}

.sidebar__brand {
  display: flex;
  align-items: center;
  gap: 24px;
  height: 72px;
  padding: 0 32px;
}

.sidebar__logo {
  font-weight: 700;
  font-size: 32px;
  line-height: 40px;
}

.sidebar__close {
  display: none;
  padding: 0;
  border: 0;
  background: transparent;
  color: #fff;
  cursor: pointer;
}

.sidebar__nav {
  flex: 1;
  padding: 24px;
}

.nav-item {
  display: flex;
  align-items: center;
  padding: 8px 16px;
  border-radius: 12px;
  color: var(--nav-text);
  font-weight: 600;
  line-height: 24px;
  text-decoration: none;
}

.nav-item--active {
  background: rgba(255, 255, 255, 0.4);
}

.sidebar__copyright {
  padding: 16px 24px;
  color: #f1e9e2;
  font-family: var(--alt-font);
  line-height: 24px;
}

.sidebar__copyright small {
  font-size: 10.32px;
}

/* Desktop : la sidebar reste visible à gauche pendant le défilement */
@media (min-width: 768px) {
  .sidebar {
    position: sticky;
    top: 0;
    height: 100vh;
  }
}

/* Mobile : tiroir hors écran, qui glisse quand on ajoute la classe "sidebar--open" */
@media (max-width: 767px) {
  .sidebar {
    position: fixed;
    top: 0;
    left: 0;
    z-index: 20;
    width: 295px;
    height: 100dvh;
    transform: translateX(-100%);
    transition: transform 0.2s ease;
    filter: drop-shadow(0 4px 16px rgba(0, 0, 0, 0.25));
  }

  .sidebar--open {
    transform: translateX(0);
  }

  .sidebar__brand {
    padding: 0 24px;
  }

  .sidebar__close {
    display: block;
  }

  .sidebar__copyright {
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
    text-align: center;
  }

  .overlay {
    position: fixed;
    inset: 0;
    z-index: 10;
    background: rgba(0, 0, 0, 0.4);
  }
}
</style>
