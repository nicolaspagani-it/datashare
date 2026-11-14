<!--
  HomeView.vue = la page d'accueil "Tu veux partager un fichier ?".
  Publique : visible aussi sans être connecté (le bouton de l'en-tête s'adapte,
  voir SiteHeader.vue). Fond, en-tête et pied de page viennent de PublicLayout.
-->
<script setup>
import { ref } from 'vue'
import { UploadCloud } from 'lucide-vue-next'
import PublicLayout from '../components/PublicLayout.vue'

// Référence vers le <input type="file"> caché : le gros bouton rond clique dessus
// pour ouvrir la fenêtre de choix de fichier du navigateur.
const fileInput = ref(null)

function onFilesSelected(event) {
  // TODO : envoyer les fichiers à l'API Symfony (POST /api/files) quand elle existera.
  const names = Array.from(event.target.files).map((f) => f.name)
  console.info('Fichiers choisis :', names)
}
</script>

<template>
  <PublicLayout>
    <h1 class="title">Tu veux partager un fichier ?</h1>

    <!-- Deux cercles imbriqués : un halo translucide autour d'un disque foncé -->
    <button type="button" class="upload" aria-label="Choisir des fichiers à partager" @click="fileInput.click()">
      <span class="upload__disc"><UploadCloud :size="48" /></span>
    </button>
    <input ref="fileInput" type="file" multiple hidden @change="onFilesSelected" />
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
