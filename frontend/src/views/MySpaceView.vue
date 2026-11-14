<!--
  MySpaceView.vue = la page "Mon espace" : la liste des fichiers de l'utilisateur connecté.
  Elle assemble les composants : sidebar + barre du haut + onglets + lignes de fichiers.
  Les fichiers viennent de l'API (GET /api/files) via le store "files".
-->
<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppSidebar from '../components/AppSidebar.vue'
import AppTopbar from '../components/AppTopbar.vue'
import BaseButton from '../components/BaseButton.vue'
import FilterTabs from '../components/FilterTabs.vue'
import FileRow from '../components/FileRow.vue'
import { useAuthStore } from '../stores/auth'
import { useFilesStore } from '../stores/files'
import { isExpired } from '../utils/expiry'

const auth = useAuthStore()
const filesStore = useFilesStore()
const router = useRouter()

// --- État de la page --------------------------------------------------------
const menuOpen = ref(false) // tiroir de la sidebar (mobile)
const filter = ref('all') // onglet sélectionné : 'all' | 'active' | 'expired'
const actionError = ref('') // erreur d'une action (ex : suppression qui échoue)

const tabs = [
  { value: 'all', label: 'Tous' },
  { value: 'active', label: 'Actifs' },
  { value: 'expired', label: 'Expiré' },
]

// À l'ouverture de la page, on (re)charge la liste depuis le serveur.
onMounted(() => filesStore.fetch())

// Liste filtrée selon l'onglet : recalculée automatiquement quand "filter" ou la liste change.
const visibleFiles = computed(() => {
  const files = filesStore.items
  if (filter.value === 'active') return files.filter((f) => !isExpired(f.expiresAt))
  if (filter.value === 'expired') return files.filter((f) => isExpired(f.expiresAt))
  return files
})

// --- Actions ----------------------------------------------------------------
async function removeFile(file) {
  // Une suppression est définitive (le lien de partage ne marche plus) : on demande confirmation.
  if (!window.confirm(`Supprimer « ${file.name} » ? Le lien de partage ne fonctionnera plus.`)) return

  actionError.value = ''
  try {
    await filesStore.remove(file)
  } catch (e) {
    actionError.value = e.message
  }
}

function openFile(file) {
  // Ouvre le lien de partage dans un nouvel onglet ("noopener" : le nouvel onglet
  // n'a aucun accès à cette page, c'est une bonne pratique de sécurité).
  window.open(file.url, '_blank', 'noopener')
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

        <!-- Erreur de chargement de la liste, avec un bouton pour réessayer -->
        <div v-if="filesStore.error" class="content__error" role="alert">
          <p>{{ filesStore.error }}</p>
          <BaseButton variant="outline" @click="filesStore.fetch()">Réessayer</BaseButton>
        </div>

        <!-- Premier chargement : rien à afficher encore -->
        <p v-else-if="!filesStore.loaded" class="content__info">Chargement de tes fichiers…</p>

        <!-- Aucun fichier du tout : on invite à en ajouter un -->
        <div v-else-if="!filesStore.items.length" class="content__info content__empty-all">
          <p>Tu n'as pas encore partagé de fichier.</p>
          <BaseButton variant="soft" size="lg" :to="{ name: 'home' }">Ajouter un fichier</BaseButton>
        </div>

        <template v-else>
          <FilterTabs v-model="filter" :options="tabs" />

          <!-- Erreur d'une action (suppression refusée par le serveur...) -->
          <p v-if="actionError" class="content__action-error" role="alert">{{ actionError }}</p>

          <ul class="files">
            <!-- v-for = la boucle {% for %} de Twig. :key aide Vue à suivre chaque ligne. -->
            <FileRow v-for="file in visibleFiles" :key="file.id" :file="file" @delete="removeFile" @open="openFile" />
          </ul>

          <p v-if="!visibleFiles.length" class="content__info">Aucun fichier à afficher.</p>
        </template>
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

.content__info {
  color: var(--text-muted);
}

.content__empty-all {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 16px;
}

.content__error {
  display: flex;
  align-items: center;
  gap: 16px;
  color: var(--danger);
}

.content__action-error {
  color: var(--danger);
  font-size: 14px;
  line-height: 20px;
}
</style>
