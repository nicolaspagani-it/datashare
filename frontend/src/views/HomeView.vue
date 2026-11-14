<!--
  HomeView.vue = la page d'accueil, qui est aussi le début du parcours de téléversement.
  Publique : visible aussi sans être connecté (le bouton de l'en-tête s'adapte,
  voir SiteHeader.vue). Fond, en-tête et pied de page viennent de PublicLayout.

  Deux états :
    - aucun fichier choisi : "Tu veux partager un fichier ?" et le gros bouton rond
    - un fichier choisi    : la carte "Ajouter un fichier" (UploadForm)
-->
<script setup>
import { ref } from 'vue'
import { UploadCloud } from 'lucide-vue-next'
import PublicLayout from '../components/PublicLayout.vue'
import UploadForm from '../components/UploadForm.vue'

// Le fichier choisi par l'utilisateur (objet File du navigateur), ou null.
const file = ref(null)

// Référence vers le <input type="file"> caché : le bouton clique dessus pour ouvrir
// la fenêtre de choix de fichier du navigateur.
const fileInput = ref(null)

function openPicker() {
  fileInput.value.click()
}

function onFileSelected(event) {
  const chosen = event.target.files?.[0]
  if (chosen) file.value = chosen
  event.target.value = '' // permet de re-choisir le même fichier ensuite
}
</script>

<template>
  <PublicLayout>
    <UploadForm v-if="file" :file="file" @change-file="openPicker" />

    <template v-else>
      <h1 class="title">Tu veux partager un fichier ?</h1>

      <!-- Deux cercles imbriqués : un halo translucide autour d'un disque foncé -->
      <button type="button" class="upload" aria-label="Choisir un fichier à partager" @click="openPicker">
        <span class="upload__disc"><UploadCloud :size="48" /></span>
      </button>
    </template>

    <input ref="fileInput" type="file" hidden @change="onFileSelected" />
  </PublicLayout>
</template>

<style scoped>
.title {
  font-weight: 300;
  font-size: 30px;
  line-height: 40px;
  text-align: center;
}

.upload {
  padding: 24px;
  border: 0;
  border-radius: 240px;
  background: rgba(47, 25, 13, 0.15);
  cursor: pointer;
}

.upload__disc {
  display: grid;
  place-items: center;
  padding: 24px;
  border-radius: 240px;
  background: #100218;
  color: #fff;
}

.upload:hover .upload__disc {
  filter: brightness(1.3);
}
</style>
