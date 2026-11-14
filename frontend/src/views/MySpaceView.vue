<!--
  MySpaceView.vue = la page "Mon espace" : la liste des fichiers de l'utilisateur.
  Elle assemble les composants : sidebar + barre du haut + onglets + lignes de fichiers.
-->
<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppSidebar from '../components/AppSidebar.vue'
import AppTopbar from '../components/AppTopbar.vue'
import FilterTabs from '../components/FilterTabs.vue'
import FileRow from '../components/FileRow.vue'
import { useAuthStore } from '../stores/auth'
import { isExpired } from '../utils/expiry'

const auth = useAuthStore()
const router = useRouter()

// --- État de la page --------------------------------------------------------
const menuOpen = ref(false) // tiroir de la sidebar (mobile)
const filter = ref('all') // onglet sélectionné : 'all' | 'active' | 'expired'

const tabs = [
  { value: 'all', label: 'Tous' },
  { value: 'active', label: 'Actifs' },
  { value: 'expired', label: 'Expiré' },
]

// TODO : données de démonstration reprises du design Figma.
// À remplacer par un appel à l'API Symfony (GET /api/files) quand elle existera.
const DAY = 24 * 60 * 60 * 1000
const files = ref([
  { id: 1, name: 'IMG_9210_123123131313213231.jpg', type: 'image', expiresAt: Date.now() + 2 * DAY - 1000, protected: true },
  { id: 2, name: 'compo2.mp3', type: 'audio', expiresAt: Date.now() + DAY - 1000, protected: false },
  { id: 3, name: 'vacances_ardeche.mp4', type: 'video', expiresAt: Date.now() - DAY, protected: false },
])

// Liste filtrée selon l'onglet : recalculée automatiquement quand "filter" ou "files" change.
const visibleFiles = computed(() => {
  if (filter.value === 'active') return files.value.filter((f) => !isExpired(f.expiresAt))
  if (filter.value === 'expired') return files.value.filter((f) => isExpired(f.expiresAt))
  return files.value
})

// --- Actions ----------------------------------------------------------------
function removeFile(file) {
  // TODO : appeler DELETE /api/files/{id} quand l'API existera.
  files.value = files.value.filter((f) => f.id !== file.id)
}

function openFile(file) {
  // TODO : ouvrir/télécharger le fichier quand l'API existera.
  console.info('Accéder au fichier', file.name)
}

function logout() {
  auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="layout">
    <AppSidebar :open="menuOpen" @close="menuOpen = false" />

    <div class="layout__main">
      <AppTopbar @menu="menuOpen = true" @logout="logout" />

      <main class="content">
        <h1 class="content__title">Mes fichiers</h1>

        <FilterTabs v-model="filter" :options="tabs" />

        <ul class="files">
          <!-- v-for = la boucle {% for %} de Twig. :key aide Vue à suivre chaque ligne. -->
          <FileRow v-for="file in visibleFiles" :key="file.id" :file="file" @delete="removeFile" @open="openFile" />
        </ul>

        <p v-if="!visibleFiles.length" class="content__empty">Aucun fichier à afficher.</p>
      </main>
    </div>
  </div>
</template>

<style scoped>
.layout {
  display: flex;
  min-height: 100vh;
}

.layout__main {
  flex: 1;
  min-width: 0;
}

.content {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 24px;
  padding: 24px;
}

.content__title {
  font-weight: 700;
  font-size: 28px;
  line-height: 40px;
}

.files {
  display: flex;
  flex-direction: column;
  gap: 16px;
  width: 100%;
  margin: 0;
  padding: 0;
}

.content__empty {
  color: var(--text-muted);
}
</style>
