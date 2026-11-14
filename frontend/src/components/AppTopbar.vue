<!--
  AppTopbar.vue = la barre du haut de "Mon espace".
    - Desktop : à droite, boutons "Ajouter des fichiers" et "Déconnexion".
    - Mobile  : à gauche l'icône menu (☰), à droite l'avatar et le nom de l'utilisateur.
-->
<script setup>
import { computed } from 'vue'
import { LogOut, Menu } from 'lucide-vue-next'
import BaseButton from './BaseButton.vue'
import { useAuthStore } from '../stores/auth'

defineEmits(['menu', 'logout'])

const auth = useAuthStore()

// Nom affiché : la partie de l'email avant le "@" (on n'a pas encore de champ "nom").
const name = computed(() => auth.user?.email?.split('@')[0] ?? '')
const initial = computed(() => name.value.charAt(0).toUpperCase())
</script>

<template>
  <header class="topbar">
    <!-- Mobile : bouton qui ouvre le tiroir de la sidebar -->
    <button type="button" class="topbar__menu" aria-label="Ouvrir le menu" @click="$emit('menu')">
      <Menu :size="24" />
    </button>

    <!-- Mobile : avatar + nom -->
    <div class="topbar__user">
      <span class="avatar" aria-hidden="true">{{ initial }}</span>
      <span class="topbar__name">{{ name }}</span>
    </div>

    <!-- Desktop : actions -->
    <div class="topbar__actions">
      <BaseButton variant="dark" :to="{ name: 'home' }">Ajouter des fichiers</BaseButton>
      <BaseButton variant="ghost" @click="$emit('logout')"><LogOut :size="16" /> Déconnexion</BaseButton>
    </div>
  </header>
</template>

<style scoped>
.topbar {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--bg-header);
  border-bottom: 1px solid var(--border-header);
}

.topbar__menu {
  display: none;
  padding: 0;
  border: 0;
  background: transparent;
  cursor: pointer;
}

.topbar__user {
  display: none;
  align-items: center;
  gap: 16px;
  margin-left: auto;
}

.avatar {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: var(--gradient);
  color: #fff;
  font-weight: 700;
}

.topbar__name {
  font-weight: 600;
  line-height: 24px;
}

.topbar__actions {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-left: auto;
}

@media (min-width: 768px) {
  .topbar {
    padding: 16px 32px 15px; /* 15px en bas + 1px de bordure = 64px de haut comme Figma */
  }
}

@media (max-width: 767px) {
  .topbar {
    padding: 16px 24px 15px;
  }

  .topbar__menu,
  .topbar__user {
    display: flex;
  }

  .topbar__actions {
    display: none;
  }
}
</style>
