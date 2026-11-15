<!--
  UploadForm.vue = la carte "Ajouter un fichier" : elle s'affiche après le choix d'un fichier.

  Elle gère les 3 états du parcours de téléversement du design :
    1. configuration : le fichier choisi, un mot de passe optionnel, la durée de conservation
    2. fichier trop gros : message rouge et bouton "Téléverser" désactivé (limite : 1 Go)
    3. succès : message de félicitations et lien de partage à copier

  Props :  file = le fichier choisi (objet File du navigateur)
  Événement : "change-file" (le bouton "Changer" : demander un autre fichier)
-->
<script setup>
import { computed, ref } from 'vue'
import { Copy, UploadCloud } from 'lucide-vue-next'
import BaseButton from './BaseButton.vue'
import BaseInput from './BaseInput.vue'
import BaseSelect from './BaseSelect.vue'
import FileSummary from './FileSummary.vue'
import { api } from '../api'
import { MAX_FILE_SIZE } from '../utils/format'

const props = defineProps({ file: { type: File, required: true } })
const emit = defineEmits(['change-file'])

// Durées de conservation proposées : de 1 jour à 1 semaine.
// La valeur est le NOMBRE DE JOURS, envoyé tel quel à l'API (champ "expiration" de POST /api/files).
const expirations = [
  { value: '1', label: 'Une journée' },
  { value: '2', label: '2 jours' },
  { value: '3', label: '3 jours' },
  { value: '4', label: '4 jours' },
  { value: '5', label: '5 jours' },
  { value: '6', label: '6 jours' },
  { value: '7', label: 'Une semaine' },
]

const password = ref('')
const expiration = ref('1') // "Une journée" par défaut, comme dans le design
const uploading = ref(false)
const error = ref('')
const result = ref(null) // { url } quand le téléversement a réussi

// --- Valeurs calculées à partir du fichier ---------------------------------
const tooBig = computed(() => props.file.size > MAX_FILE_SIZE)
const durationLabel = computed(() => expirations.find((e) => e.value === expiration.value).label.toLowerCase())

// --- Actions -----------------------------------------------------------------
async function upload() {
  if (tooBig.value) return
  error.value = ''
  uploading.value = true
  try {
    // multipart/form-data : c'est ainsi qu'on envoie un fichier à Symfony.
    const form = new FormData()
    form.append('file', props.file)
    form.append('expiration', expiration.value)
    if (password.value) form.append('password', password.value)

    // POST /api/files (Symfony). Réponse : { id, token, name, size, url, protected, expiresAt, ... }
    // En cas d'erreur (fichier trop gros, durée invalide...), api() lève une exception dont le
    // message est celui du serveur : il s'affiche sous le formulaire.
    result.value = await api('/files', { method: 'POST', body: form })
  } catch (e) {
    error.value = e.message
  } finally {
    uploading.value = false
  }
}

const copied = ref(false)
async function copyLink() {
  try {
    await navigator.clipboard.writeText(result.value.url)
    copied.value = true
  } catch {
    copied.value = false // presse-papiers indisponible : le lien reste sélectionnable à la main
  }
}
</script>

<template>
  <!-- Après le succès, la carte est plus large (480px de contenu dans le design) -->
  <section class="card" :class="{ 'card--wide': result }">
    <h1 class="card__title">Ajouter un fichier</h1>

    <!-- ÉTAT 3 : succès -->
    <template v-if="result">
      <FileSummary :name="file.name" :size="file.size" :type="file.type" />

      <!-- Message + lien de partage dans son encadré gris -->
      <div class="share">
        <p class="card__success">Félicitations, ton fichier sera conservé chez nous pendant {{ durationLabel }} !</p>
        <p class="share__link"><a :href="result.url">{{ result.url }}</a></p>
      </div>

      <BaseButton variant="soft" size="lg" class="card__copy" @click="copyLink">
        <Copy :size="16" /> {{ copied ? 'Lien copié !' : 'Copier le lien' }}
      </BaseButton>
    </template>

    <!-- ÉTATS 1 et 2 : configuration (avec ou sans erreur de taille) -->
    <form v-else class="card__form" novalidate @submit.prevent="upload">
      <FileSummary :name="file.name" :size="file.size" :type="file.type">
        <BaseButton variant="outline" @click="emit('change-file')">Changer</BaseButton>
      </FileSummary>

      <!-- Trop gros : message rouge directement sous le fichier -->
      <p v-if="tooBig" class="card__error" role="alert">La taille des fichiers est limitée à 1 Go</p>

      <BaseInput v-model="password" label="Mot de passe" type="password" placeholder="Optionnel" autocomplete="new-password" />
      <BaseSelect v-model="expiration" label="Expiration" :options="expirations" />

      <p v-if="error" class="card__error" role="alert">{{ error }}</p>

      <BaseButton variant="soft" size="lg" type="submit" class="card__submit" :disabled="tooBig || uploading">
        <UploadCloud :size="16" /> Téléverser
      </BaseButton>
    </form>
  </section>
</template>

<style scoped>
.card {
  display: flex;
  flex-direction: column;
  gap: 24px;
  width: 100%;
  max-width: 416px;
  padding: 32px;
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
  gap: 16px;
}

.card__submit {
  align-self: center;
  margin-top: 8px;
}

.card__error {
  color: var(--danger);
  font-size: 14px;
  line-height: 20px;
}

/* Après le succès : carte plus large (480px de contenu + 2 × 32px de marge) */
.card--wide {
  max-width: 544px;
}

/* Message de félicitations + encadré gris du lien, alignés à gauche */
.share {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.card__success {
  font-size: 16px;
  line-height: 24px;
}

.share__link {
  padding: 8px 12px;
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.05);
  font-size: 16px;
  line-height: 24px;
}

.share__link a {
  display: block;
  overflow: hidden;
  color: var(--accent);
  text-decoration: underline;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.card__copy {
  align-self: center;
}
</style>
