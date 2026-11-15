<!--
  DownloadView.vue = la page "Télécharger un fichier", ouverte par le lien de partage (/<token>).
  Publique : aucun compte nécessaire.

  Les états de la page (ceux du design) :
    - chargement
    - prêt, avec mot de passe : fichier + bandeau d'expiration + champ mot de passe + bouton (grisé tant que
      le mot de passe est vide)
    - prêt, sans mot de passe : fichier + bandeau d'expiration + bouton
    - expiré (ou lien inconnu, ou autre erreur) : un bandeau rouge

  Les appels à l'API passent par "auth: false" : on n'envoie pas de token (voir api.js).
-->
<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { CloudDownload } from 'lucide-vue-next'
import AppCallout from '../components/AppCallout.vue'
import BaseButton from '../components/BaseButton.vue'
import BaseInput from '../components/BaseInput.vue'
import FileSummary from '../components/FileSummary.vue'
import PublicLayout from '../components/PublicLayout.vue'
import { api } from '../api'
import { startDownload } from '../utils/download'
import { expiryNotice, isExpired } from '../utils/expiry'

const EXPIRED_MESSAGE = "Ce fichier n'est plus disponible en téléchargement car il a expiré."

const route = useRoute()
const token = computed(() => route.params.token) // la partie aléatoire du lien : /UhGyr4Kd

// --- État de la page -----------------------------------------------------------
const status = ref('loading') // 'loading' | 'ready' | 'expired' | 'error'
const file = ref(null) // { token, name, size, type, protected, expiresAt }
const loadError = ref('') // message quand status === 'error' (lien inconnu, serveur injoignable...)

const password = ref('')
const passwordErrors = ref([]) // erreurs affichées sous le champ mot de passe
const generalError = ref('') // erreur d'envoi qui ne concerne pas le mot de passe
const downloading = ref(false)

// Le bouton est grisé tant qu'un fichier protégé n'a pas reçu son mot de passe.
const canDownload = computed(() => !downloading.value && (!file.value?.protected || password.value !== ''))
const notice = computed(() => (file.value ? expiryNotice(file.value.expiresAt) : null))

// Dès que l'utilisateur retape le mot de passe, l'erreur précédente disparaît.
watch(password, () => (passwordErrors.value = []))

// --- Chargement des informations du fichier -----------------------------------
async function load() {
  status.value = 'loading'
  file.value = null
  password.value = ''
  passwordErrors.value = []
  generalError.value = ''
  try {
    file.value = await api(`/shared/${token.value}`, { auth: false })
    status.value = isExpired(file.value.expiresAt) ? 'expired' : 'ready'
  } catch (e) {
    if (e.status === 410) {
      status.value = 'expired'
    } else {
      status.value = 'error' // 404 : "Ce lien n'existe pas.", ou panne réseau / serveur
      loadError.value = e.message
    }
  }
}

onMounted(load)
watch(token, load) // un autre lien ouvert sans quitter la page

// --- Téléchargement ----------------------------------------------------------------
// 1) POST /unlock : le serveur vérifie le mot de passe (s'il y en a un) et renvoie l'URL de téléchargement.
// 2) le navigateur ouvre cette URL : le fichier est enregistré (voir utils/download.js).
async function download() {
  if (!canDownload.value) return
  passwordErrors.value = []
  generalError.value = ''
  downloading.value = true
  try {
    const { downloadUrl } = await api(`/shared/${token.value}/unlock`, {
      method: 'POST',
      body: { password: password.value },
      auth: false,
    })
    startDownload(downloadUrl)
  } catch (e) {
    if (e.status === 410) {
      status.value = 'expired' // le fichier a expiré pendant que la page était ouverte
    } else if (e.violations?.length) {
      passwordErrors.value = e.violations.map((v) => v.title) // mot de passe incorrect, trop d'essais...
    } else {
      generalError.value = e.message
    }
  } finally {
    downloading.value = false
  }
}
</script>

<template>
  <PublicLayout>
    <section class="card">
      <h1 class="card__title">Télécharger un fichier</h1>

      <p v-if="status === 'loading'" class="card__loading">Chargement…</p>

      <!-- Fichier expiré -->
      <AppCallout v-else-if="status === 'expired'" variant="error">{{ EXPIRED_MESSAGE }}</AppCallout>

      <!-- Lien inconnu ou erreur de chargement -->
      <AppCallout v-else-if="status === 'error'" variant="error">{{ loadError }}</AppCallout>

      <form v-else class="card__form" novalidate @submit.prevent="download">
        <FileSummary :name="file.name" :size="file.size" :type="file.type" />

        <AppCallout :variant="notice.variant">{{ notice.text }}</AppCallout>

        <!-- Seulement si le fichier est protégé par un mot de passe -->
        <BaseInput
          v-if="file.protected"
          v-model="password"
          label="Mot de passe"
          type="password"
          placeholder="Saisissez le mot de passe..."
          autocomplete="off"
          :errors="passwordErrors"
        />

        <AppCallout v-if="generalError" variant="error">{{ generalError }}</AppCallout>

        <BaseButton variant="soft" size="lg" block type="submit" :disabled="!canDownload">
          <CloudDownload :size="16" /> Télécharger
        </BaseButton>
      </form>
    </section>
  </PublicLayout>
</template>

<style scoped>
.card {
  display: flex;
  flex-direction: column;
  gap: 24px;
  width: 100%;
  max-width: 640px;
  padding: 24px;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}

.card__title {
  font-weight: 700;
  font-size: 28px;
  line-height: 40px;
  text-align: center;
}

.card__form {
  display: flex;
  flex-direction: column;
  gap: 24px; /* 24px entre le fichier, le bandeau, le champ et le bouton, comme dans le design */
}

.card__loading {
  color: var(--text-muted);
  text-align: center;
}
</style>
