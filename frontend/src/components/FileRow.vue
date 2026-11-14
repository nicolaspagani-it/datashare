<!--
  FileRow.vue = une ligne de la liste "Mes fichiers" (icône, nom, expiration, actions).

  Le composant parent lui passe un fichier via la prop "file" et reçoit en retour
  les événements "delete" / "open" (comme un composant enfant qui remonte une action).
-->
<script setup>
import { computed, ref } from 'vue'
import { ArrowRight, Lock, MoreVertical, Trash2 } from 'lucide-vue-next'
import BaseButton from './BaseButton.vue'
import { expiryLabel, isExpired } from '../utils/expiry'
import { iconForMime } from '../utils/fileIcons'

const props = defineProps({
  // Un fichier tel que le renvoie l'API : { id, name, type (MIME : "image/png"...), expiresAt, protected }
  file: { type: Object, required: true },
})
const emit = defineEmits(['delete', 'open'])

// computed() : valeurs recalculées automatiquement si "file" change
const icon = computed(() => iconForMime(props.file.type))
const expired = computed(() => isExpired(props.file.expiresAt))
const label = computed(() => expiryLabel(props.file.expiresAt))

// Menu "⋮" affiché sur mobile à la place des deux boutons
const menuOpen = ref(false)

function run(action) {
  menuOpen.value = false
  emit(action, props.file)
}
</script>

<template>
  <li class="row">
    <img class="row__icon" :src="icon" alt="" width="24" height="24" />

    <div class="row__info">
      <p class="row__name">{{ file.name }}</p>
      <p class="row__expiry" :class="{ 'row__expiry--expired': expired }">{{ label }}</p>
    </div>

    <!-- Fichier expiré : plus aucune action possible, on affiche un message (desktop) -->
    <p v-if="expired" class="row__message">Ce fichier à expiré, il n’est plus stocké chez nous</p>

    <template v-else>
      <!-- Desktop : cadenas + boutons "Supprimer" et "Accéder" -->
      <div class="row__actions row__actions--desktop">
        <Lock v-if="file.protected" :size="16" aria-label="Protégé par mot de passe" />
        <BaseButton variant="outline" @click="run('delete')"><Trash2 :size="16" /> Supprimer</BaseButton>
        <BaseButton variant="outline" @click="run('open')">Accéder <ArrowRight :size="16" /></BaseButton>
      </div>

      <!-- Mobile : cadenas + bouton "⋮" qui ouvre un petit menu -->
      <div class="row__actions row__actions--mobile" @focusout="menuOpen = false" @keydown.esc="menuOpen = false">
        <Lock v-if="file.protected" :size="16" aria-label="Protégé par mot de passe" />
        <BaseButton variant="outline" size="icon" aria-label="Actions" @click="menuOpen = !menuOpen">
          <MoreVertical :size="16" />
        </BaseButton>

        <!-- @mousedown.prevent : évite que le clic ferme le menu (focusout) avant d'être pris en compte -->
        <ul v-if="menuOpen" class="menu" @mousedown.prevent>
          <li><button type="button" @click="run('open')">Accéder</button></li>
          <li><button type="button" @click="run('delete')">Supprimer</button></li>
        </ul>
      </div>
    </template>
  </li>
</template>

<style scoped>
.row {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 7px 15px; /* + 1px de bordure de chaque côté = 8px / 16px comme Figma */
  border: 1px solid var(--border-row);
  border-radius: 8px;
  background: var(--bg-row);
  list-style: none;
}

.row__icon {
  flex-shrink: 0;
}

.row__info {
  flex: 1;
  min-width: 0; /* permet à "text-overflow: ellipsis" de fonctionner dans un flex */
}

.row__name {
  overflow: hidden;
  font-weight: 600;
  font-size: 16px;
  line-height: 24px;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.row__expiry {
  font-size: 14px;
  line-height: 16px;
}

.row__expiry--expired {
  color: var(--danger);
}

.row__message {
  color: var(--text-muted);
  font-size: 14px;
  line-height: 16px;
}

.row__actions {
  position: relative;
  display: flex;
  align-items: center;
  flex-shrink: 0;
}

.row__actions--desktop {
  gap: 8px;
}

.row__actions--mobile {
  gap: 12px;
}

.menu {
  position: absolute;
  top: calc(100% + 4px);
  right: 0;
  z-index: 5;
  margin: 0;
  padding: 4px;
  min-width: 140px;
  border: 1px solid var(--border-row);
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
  list-style: none;
}

.menu button {
  width: 100%;
  padding: 8px 12px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--accent);
  text-align: left;
  cursor: pointer;
}

.menu button:hover {
  background: var(--bg-header);
}

/* Responsive : on affiche l'un ou l'autre selon la largeur de l'écran */
@media (max-width: 767px) {
  .row__actions--desktop,
  .row__message {
    display: none;
  }
}

@media (min-width: 768px) {
  .row__actions--mobile {
    display: none;
  }
}
</style>
