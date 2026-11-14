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
      vi.spyOn(auth, 'login').mockRejectedValue(new Error('Erreur 401'))

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

      expect(wrapper.find('[role="alert"]').text()).toBe('Les mots de passe ne correspondent pas.')
      expect(register).not.toHaveBeenCalled()
    })

    it('affiche l\'erreur du serveur (ex : email déjà utilisé)', async () => {
      const { wrapper, router, auth } = await mountView('register')
      vi.spyOn(auth, 'register').mockRejectedValue(new Error('Cet email est déjà utilisé.'))

      await fillAndSubmit(wrapper, ['alice@example.com', 'password123', 'password123'])

      expect(wrapper.find('[role="alert"]').text()).toBe('Cet email est déjà utilisé.')
      expect(router.currentRoute.value.name).toBe('register')
    })
  })
})
