<!--
  FileSummary.vue = la ligne "fichier" des cartes : icône du type, nom (coupé avec … s'il est
  trop long) et taille. Un contenu optionnel peut être ajouté à droite (ex : le bouton "Changer").

  Utilisée par la carte de téléversement (UploadForm) et par la page de téléchargement.

  Props : name = nom du fichier, size = taille en octets, type = type MIME ("image/png"...)
-->
<script setup>
import { computed } from 'vue'
import { formatSize } from '../utils/format'
import { iconForMime } from '../utils/fileIcons'

const props = defineProps({
  name: { type: String, required: true },
  size: { type: Number, required: true },
  type: { type: String, default: '' },
})

const icon = computed(() => iconForMime(props.type))
const formattedSize = computed(() => formatSize(props.size))
</script>

<template>
  <div class="file">
    <img class="file__icon" :src="icon" alt="" width="24" height="24" />
    <div class="file__info">
      <p class="file__name">{{ name }}</p>
      <p class="file__size">{{ formattedSize }}</p>
    </div>
    <!-- Emplacement pour une action à droite du fichier -->
    <slot />
  </div>
</template>

<style scoped>
.file {
  display: flex;
  align-items: center;
  gap: 16px;
}

.file__icon {
  flex-shrink: 0;
}

.file__info {
  flex: 1;
  min-width: 0; /* permet la coupure avec "…" du nom trop long */
}

.file__name {
  overflow: hidden;
  font-size: 16px;
  line-height: 24px;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.file__size {
  font-size: 14px;
  line-height: 16px;
}
</style>
