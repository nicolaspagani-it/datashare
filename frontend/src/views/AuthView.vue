<!--
  AuthView.vue = les pages CONNEXION et INSCRIPTION (même composant, selon le "mode").
  Une carte blanche centrée sur le fond dégradé, avec le formulaire.
-->

<!--
  <script setup> : la LOGIQUE du composant (le "contrôleur").
-->
<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import BaseButton from '../components/BaseButton.vue'
import BaseInput from '../components/BaseInput.vue'
import PublicLayout from '../components/PublicLayout.vue'
import { useAuthStore } from '../stores/auth'

// "mode" ('login' ou 'register') est fourni par la route, voir router.js.
const props = defineProps({ mode: { type: String, default: 'login' } })

const auth = useAuthStore()
const router = useRouter()

// ref() = variable réactive : l'affichage suit ses changements (on lit/écrit avec .value).
const email = ref('')
const password = ref('')
const passwordConfirm = ref('')
const loading = ref(false)

const isLogin = computed(() => props.mode === 'login')

// --- Gestion des erreurs ----------------------------------------------------
// Les erreurs sont rangées par champ : chacune s'affiche sous le champ concerné.
//   - fieldErrors.email           -> sous le champ Email
//   - fieldErrors.password        -> sous le champ Mot de passe
//   - fieldErrors.passwordConfirm -> sous le champ de vérification
// "error" garde les erreurs qui ne concernent aucun champ (mauvais identifiants, réseau...).
const emptyErrors = () => ({ email: [], password: [], passwordConfirm: [] })
const fieldErrors = ref(emptyErrors())
const error = ref('')

// Dès que l'utilisateur corrige un champ, on efface l'erreur qui lui était associée.
watch(email, () => (fieldErrors.value.email = []))
watch(password, () => (fieldErrors.value.password = []))
watch([password, passwordConfirm], () => (fieldErrors.value.passwordConfirm = []))

// Répartit les erreurs du serveur : "violations" = [{ propertyPath: 'email', title: '...' }].
// propertyPath est le nom du champ côté Symfony (même nom que dans RegisterRequest).
function dispatchViolations(violations) {
  const general = []
  for (const { propertyPath, title } of violations) {
    if (propertyPath in fieldErrors.value) fieldErrors.value[propertyPath].push(title)
    else general.push(title) // erreur sur un champ qu'on n'affiche pas : message global
  }
  error.value = general.join(' ')
}

// Appelée à la soumission du formulaire (= l'action POST du contrôleur).
async function submit() {
  error.value = ''
  fieldErrors.value = emptyErrors()

  // Inscription : on vérifie côté navigateur que les 2 mots de passe sont identiques.
  // (Le serveur, lui, ne reçoit que "password" et valide sa longueur.)
  if (!isLogin.value && password.value !== passwordConfirm.value) {
    fieldErrors.value.passwordConfirm = ['Les deux mots de passe ne sont pas identiques.']
    return
  }

  loading.value = true
  try {
    if (isLogin.value) await auth.login(email.value, password.value)
    else await auth.register(email.value, password.value)
    router.push({ name: 'home' }) // succès : retour à l'accueil, maintenant connecté
  } catch (e) {
    if (e.violations?.length) {
      dispatchViolations(e.violations) // erreurs de validation : chacune sous son champ
    } else {
      // Une 401 sur /login = identifiants incorrects : message clair pour l'utilisateur.
      error.value = e.message === 'Erreur 401' ? 'Email ou mot de passe incorrect.' : e.message
    }
  } finally {
    loading.value = false
  }
}
</script>

<!--
  <template> : le HTML. Rappel des directives Vue :
    v-model   lie un champ à une variable (dans les 2 sens)
    v-if      affiche seulement si la condition est vraie
    :attr     met une valeur JS dans un attribut HTML
    @submit.prevent  appelle la fonction à l'envoi du formulaire, sans recharger la page
-->
<template>
  <PublicLayout>
    <form class="card" novalidate @submit.prevent="submit">
      <h1 class="card__title">{{ isLogin ? 'Connexion' : 'Créer un compte' }}</h1>

      <div class="card__fields">
        <!-- :errors = les messages affichés juste sous ce champ -->
        <BaseInput
          v-model="email"
          label="Email"
          type="email"
          placeholder="Saisissez votre email..."
          autocomplete="email"
          :errors="fieldErrors.email"
          required
        />
        <BaseInput
          v-model="password"
          label="Mot de passe"
          type="password"
          placeholder="Saisissez votre mot de passe..."
          :autocomplete="isLogin ? 'current-password' : 'new-password'"
          :errors="fieldErrors.password"
          required
        />
        <!-- Champ de confirmation : uniquement à l'inscription -->
        <BaseInput
          v-if="!isLogin"
          v-model="passwordConfirm"
          label="Verification du mot de passe"
          type="password"
          placeholder="Saisissez le à nouveau"
          autocomplete="new-password"
          :errors="fieldErrors.passwordConfirm"
          required
        />
      </div>

      <!-- Erreur générale, qui ne concerne aucun champ en particulier -->
      <p v-if="error" class="card__error" role="alert">{{ error }}</p>

      <div class="card__actions">
        <!-- Lien vers l'autre page (connexion <-> inscription) -->
        <BaseButton variant="ghost" size="lg" block :to="{ name: isLogin ? 'register' : 'login' }">
          {{ isLogin ? 'Créer un compte' : "J'ai déjà un compte" }}
        </BaseButton>

        <!-- type="submit" : déclenche @submit du formulaire. :disabled pendant l'appel à l'API. -->
        <BaseButton variant="soft" size="lg" block type="submit" :disabled="loading">
          {{ isLogin ? 'Connexion' : 'Créer mon compte' }}
        </BaseButton>
      </div>
    </form>
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

.card__fields {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.card__error {
  color: var(--danger);
  font-size: 14px;
  line-height: 20px;
}

.card__actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
</style>
