import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import AuthView from './AuthView.vue'
import { useAuthStore } from '../stores/auth'
import { makeRouter } from '../test-utils'

// Monte la page de connexion ou d'inscription avec un vrai store Pinia et un routeur de test.
async function mountView(mode) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = makeRouter()
  router.push('/' + mode)
  await router.isReady()

  const wrapper = mount(AuthView, { props: { mode }, global: { plugins: [pinia, router] } })
  return { wrapper, router, auth: useAuthStore() }
}

// Remplit les champs dans l'ordre où ils apparaissent, puis envoie le formulaire.
async function fillAndSubmit(wrapper, values) {
  const inputs = wrapper.findAll('input')
  for (const [i, value] of values.entries()) await inputs[i].setValue(value)
  await wrapper.find('form').trigger('submit')
  await flushPromises() // attend la fin des appels asynchrones (login, redirection...)
}

// Les messages d'erreur affichés SOUS le champ qui porte ce label (liste vide s'il n'y en a pas).
function errorsUnder(wrapper, label) {
  const field = wrapper.findAll('.field').find((f) => f.find('label').text() === label)
  return field.findAll('.field__error').map((e) => e.text())
}

describe('AuthView', () => {
  beforeEach(() => localStorage.clear())

  describe('mode connexion', () => {
    it('affiche le titre, 2 champs et les 2 boutons', async () => {
      const { wrapper } = await mountView('login')

      expect(wrapper.find('h1').text()).toBe('Connexion')
      expect(wrapper.findAll('input').map((i) => i.attributes('type'))).toEqual(['email', 'password'])
      expect(wrapper.find('form a.btn').text()).toBe('Créer un compte')
      expect(wrapper.find('form a.btn').attributes('href')).toBe('/register')
      expect(wrapper.find('button[type="submit"]').text()).toBe('Connexion')
    })

    it('appelle login() avec l\'email et le mot de passe, puis redirige vers l\'accueil', async () => {
      const { wrapper, router, auth } = await mountView('login')
      const login = vi.spyOn(auth, 'login').mockResolvedValue()

      await fillAndSubmit(wrapper, ['alice@example.com', 'password123'])

      expect(login).toHaveBeenCalledWith('alice@example.com', 'password123')
      expect(router.currentRoute.value.name).toBe('home')
      expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    })

    it('affiche "Email ou mot de passe incorrect." sur une erreur 401', async () => {
      const { wrapper, router, auth } = await mountView('login')
      // Erreur réaliste : api() joint le code HTTP, et le texte vient du serveur ("Identifiants invalides.").
      vi.spyOn(auth, 'login').mockRejectedValue(Object.assign(new Error('Identifiants invalides.'), { status: 401 }))

      await fillAndSubmit(wrapper, ['alice@example.com', 'mauvais'])

      expect(wrapper.find('[role="alert"]').text()).toBe('Email ou mot de passe incorrect.')
      expect(router.currentRoute.value.name).toBe('login') // on reste sur la page
    })

    it('affiche le message renvoyé par le serveur pour les autres erreurs', async () => {
      const { wrapper, auth } = await mountView('login')
      vi.spyOn(auth, 'login').mockRejectedValue(new Error('Identifiants invalides.'))

      await fillAndSubmit(wrapper, ['alice@example.com', 'mauvais'])

      expect(wrapper.find('[role="alert"]').text()).toBe('Identifiants invalides.')
    })

    it('efface l\'ancienne erreur à la nouvelle soumission', async () => {
      const { wrapper, auth } = await mountView('login')
      const login = vi.spyOn(auth, 'login').mockRejectedValueOnce(new Error('Boum')).mockResolvedValueOnce()

      await fillAndSubmit(wrapper, ['alice@example.com', 'x'])
      expect(wrapper.find('[role="alert"]').exists()).toBe(true)

      await fillAndSubmit(wrapper, ['alice@example.com', 'password123'])
      expect(login).toHaveBeenCalledTimes(2)
      expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    })

    it('désactive le bouton pendant l\'appel, puis le réactive', async () => {
      const { wrapper, auth } = await mountView('login')
      let finish
      vi.spyOn(auth, 'login').mockReturnValue(new Promise((resolve) => (finish = resolve)))

      const inputs = wrapper.findAll('input')
      await inputs[0].setValue('alice@example.com')
      await inputs[1].setValue('password123')
      await wrapper.find('form').trigger('submit')

      expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()

      finish()
      await flushPromises()
      expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
    })
  })

  describe('mode inscription', () => {
    it('affiche le titre, 3 champs (avec confirmation) et les 2 boutons', async () => {
      const { wrapper } = await mountView('register')

      expect(wrapper.find('h1').text()).toBe('Créer un compte')
      expect(wrapper.findAll('input')).toHaveLength(3)
      expect(wrapper.text()).toContain('Verification du mot de passe')
      expect(wrapper.find('form a.btn').text()).toBe("J'ai déjà un compte")
      expect(wrapper.find('form a.btn').attributes('href')).toBe('/login')
      expect(wrapper.find('button[type="submit"]').text()).toBe('Créer mon compte')
    })

    it('appelle register() (sans la confirmation) puis redirige vers l\'accueil', async () => {
      const { wrapper, router, auth } = await mountView('register')
      const register = vi.spyOn(auth, 'register').mockResolvedValue()

      await fillAndSubmit(wrapper, ['alice@example.com', 'password123', 'password123'])

      expect(register).toHaveBeenCalledWith('alice@example.com', 'password123')
      expect(router.currentRoute.value.name).toBe('home')
    })

    it('refuse deux mots de passe différents, sans appeler le serveur', async () => {
      const { wrapper, auth } = await mountView('register')
      const register = vi.spyOn(auth, 'register').mockResolvedValue()

      await fillAndSubmit(wrapper, ['alice@example.com', 'password123', 'autre-chose'])

      expect(errorsUnder(wrapper, 'Verification du mot de passe')).toEqual(['Les deux mots de passe ne sont pas identiques.'])
      expect(register).not.toHaveBeenCalled()
    })

    it('affiche une erreur sans champ associé dans la zone générale', async () => {
      const { wrapper, router, auth } = await mountView('register')
      vi.spyOn(auth, 'register').mockRejectedValue(new Error('Erreur 502'))

      await fillAndSubmit(wrapper, ['alice@example.com', 'password123', 'password123'])

      expect(wrapper.find('.card__error').text()).toBe('Erreur 502')
      expect(router.currentRoute.value.name).toBe('register')
    })
  })

  describe('erreurs affichées sous le champ concerné', () => {
    // Fabrique l'erreur que lèverait api() : un message + la liste des violations.
    const apiError = (...violations) => Object.assign(new Error('violations'), { violations })

    it('place une erreur d\'email sous le champ Email, et rien sous les mots de passe', async () => {
      const { wrapper, auth } = await mountView('register')
      vi.spyOn(auth, 'register').mockRejectedValue(
        apiError({ propertyPath: 'email', title: "L'adresse email n'est pas valide (exemple : nom@domaine.fr)." }),
      )

      await fillAndSubmit(wrapper, ['pas-un-email', 'password123', 'password123'])

      expect(errorsUnder(wrapper, 'Email')).toEqual(["L'adresse email n'est pas valide (exemple : nom@domaine.fr)."])
      expect(errorsUnder(wrapper, 'Mot de passe')).toEqual([])
      expect(errorsUnder(wrapper, 'Verification du mot de passe')).toEqual([])
      expect(wrapper.find('.card__error').exists()).toBe(false)
    })

    it('place une erreur de mot de passe sous le champ Mot de passe, et rien sous l\'email', async () => {
      const { wrapper, auth } = await mountView('register')
      vi.spyOn(auth, 'register').mockRejectedValue(
        apiError({ propertyPath: 'password', title: 'Le mot de passe est trop court : il doit contenir au moins 8 caractères.' }),
      )

      await fillAndSubmit(wrapper, ['alice@example.com', 'abc', 'abc'])

      expect(errorsUnder(wrapper, 'Mot de passe')).toEqual([
        'Le mot de passe est trop court : il doit contenir au moins 8 caractères.',
      ])
      expect(errorsUnder(wrapper, 'Email')).toEqual([])
    })

    it('répartit plusieurs erreurs, chacune sous son champ', async () => {
      const { wrapper, auth } = await mountView('register')
      vi.spyOn(auth, 'register').mockRejectedValue(
        apiError(
          { propertyPath: 'email', title: 'Email KO' },
          { propertyPath: 'password', title: 'Mot de passe KO 1' },
          { propertyPath: 'password', title: 'Mot de passe KO 2' },
        ),
      )

      await fillAndSubmit(wrapper, ['x', 'y', 'y'])

      expect(errorsUnder(wrapper, 'Email')).toEqual(['Email KO'])
      expect(errorsUnder(wrapper, 'Mot de passe')).toEqual(['Mot de passe KO 1', 'Mot de passe KO 2'])
    })

    it('place l\'erreur "email déjà utilisé" sous le champ Email', async () => {
      const { wrapper, router, auth } = await mountView('register')
      vi.spyOn(auth, 'register').mockRejectedValue(
        apiError({ propertyPath: 'email', title: 'Cette adresse email est déjà utilisée.' }),
      )

      await fillAndSubmit(wrapper, ['alice@example.com', 'password123', 'password123'])

      expect(errorsUnder(wrapper, 'Email')).toEqual(['Cette adresse email est déjà utilisée.'])
      expect(router.currentRoute.value.name).toBe('register')
    })

    it('met le champ en erreur (rouge, aria-invalid) uniquement quand il a une erreur', async () => {
      const { wrapper, auth } = await mountView('register')
      vi.spyOn(auth, 'register').mockRejectedValue(apiError({ propertyPath: 'email', title: 'Email KO' }))

      await fillAndSubmit(wrapper, ['x', 'password123', 'password123'])

      const [emailInput, passwordInput] = wrapper.findAll('input')
      expect(emailInput.classes()).toContain('field__input--invalid')
      expect(emailInput.attributes('aria-invalid')).toBe('true')
      expect(passwordInput.classes()).not.toContain('field__input--invalid')
      expect(passwordInput.attributes('aria-invalid')).toBeUndefined()
    })

    it('affiche dans la zone générale une erreur portant sur un champ inconnu', async () => {
      const { wrapper, auth } = await mountView('register')
      vi.spyOn(auth, 'register').mockRejectedValue(apiError({ propertyPath: 'autreChose', title: 'Erreur inattendue.' }))

      await fillAndSubmit(wrapper, ['alice@example.com', 'password123', 'password123'])

      expect(wrapper.find('.card__error').text()).toBe('Erreur inattendue.')
      expect(wrapper.findAll('.field__error')).toHaveLength(0)
    })

    it('efface l\'erreur d\'un champ dès qu\'on le modifie, sans toucher aux autres', async () => {
      const { wrapper, auth } = await mountView('register')
      vi.spyOn(auth, 'register').mockRejectedValue(
        apiError({ propertyPath: 'email', title: 'Email KO' }, { propertyPath: 'password', title: 'Mot de passe KO' }),
      )
      await fillAndSubmit(wrapper, ['x', 'y', 'y'])

      await wrapper.findAll('input')[0].setValue('alice@example.com') // on corrige l'email

      expect(errorsUnder(wrapper, 'Email')).toEqual([])
      expect(errorsUnder(wrapper, 'Mot de passe')).toEqual(['Mot de passe KO'])
    })

    it('efface les anciennes erreurs à la nouvelle soumission', async () => {
      const { wrapper, auth } = await mountView('register')
      vi.spyOn(auth, 'register')
        .mockRejectedValueOnce(apiError({ propertyPath: 'email', title: 'Email KO' }))
        .mockResolvedValueOnce()
      await fillAndSubmit(wrapper, ['x', 'password123', 'password123'])
      expect(errorsUnder(wrapper, 'Email')).toHaveLength(1)

      await fillAndSubmit(wrapper, ['alice@example.com', 'password123', 'password123'])

      expect(wrapper.findAll('.field__error')).toHaveLength(0)
    })

    it('efface l\'erreur de confirmation quand on retape un des mots de passe', async () => {
      const { wrapper } = await mountView('register')
      await fillAndSubmit(wrapper, ['alice@example.com', 'password123', 'autre-chose'])
      expect(errorsUnder(wrapper, 'Verification du mot de passe')).toHaveLength(1)

      await wrapper.findAll('input')[2].setValue('password123')

      expect(errorsUnder(wrapper, 'Verification du mot de passe')).toEqual([])
    })
  })
})
