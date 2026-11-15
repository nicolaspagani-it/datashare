import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import router from './router'
import { useAuthStore } from './stores/auth'
import { setToken } from './api'

// Faux appels réseau : on contrôle ce que répond "/me".
vi.mock('./api', async (importOriginal) => ({ ...(await importOriginal()), api: vi.fn() }))
import { api } from './api'

const goTo = async (path) => {
  await router.push(path)
  await router.isReady()
  return router.currentRoute.value.name
}

describe('gardes de navigation', () => {
  beforeEach(async () => {
    localStorage.clear()
    setActivePinia(createPinia())
    // On repart toujours de l'accueil, sinon le test précédent influence le suivant.
    // (Naviguer vers la page où l'on est déjà ne déclenche pas les gardes : on évite ce piège.)
    await router.replace('/')
  })

  describe('visiteur non connecté', () => {
    it('peut voir l\'accueil (page publique)', async () => {
      await goTo('/register')

      expect(await goTo('/')).toBe('home')
    })

    it('peut voir connexion et inscription', async () => {
      expect(await goTo('/register')).toBe('register')
      expect(await goTo('/login')).toBe('login')
    })

    it('est renvoyé sur /login s\'il demande "Mon espace"', async () => {
      expect(await goTo('/mon-espace')).toBe('login')
    })
  })

  describe('lien de partage /<token>', () => {
    const goToWithParams = async (path) => {
      await router.push(path)
      return router.currentRoute.value
    }

    it('est une page publique : un visiteur peut l\'ouvrir', async () => {
      const route = await goToWithParams('/UhGyr4Kd')

      expect(route.name).toBe('download')
      expect(route.params.token).toBe('UhGyr4Kd')
    })

    it('est aussi accessible à un utilisateur connecté', async () => {
      setToken('jwt-valide')
      setActivePinia(createPinia())

      const route = await goToWithParams('/UhGyr4Kd')

      expect(route.name).toBe('download')
      expect(api).not.toHaveBeenCalled() // aucune vérification d'authentification
    })

    it('ne confond jamais /login, /register et /mon-espace avec un lien de partage', async () => {
      expect((await goToWithParams('/register')).name).toBe('register')
      expect((await goToWithParams('/login')).name).toBe('login')
      expect((await goToWithParams('/mon-espace')).name).toBe('login') // protégée : renvoie un visiteur sur /login
    })

    it('n\'accepte pas un token de plus de 16 caractères', async () => {
      const route = await goToWithParams('/' + 'a'.repeat(17))

      expect(route.name).not.toBe('download')
    })
  })

  describe('utilisateur connecté', () => {
    beforeEach(() => {
      setToken('jwt-valide')
      setActivePinia(createPinia()) // le store relit le token au démarrage
    })

    it('accède à "Mon espace" et son profil est chargé', async () => {
      api.mockResolvedValue({ id: 1, email: 'a@b.fr', roles: ['ROLE_USER'] })

      expect(await goTo('/mon-espace')).toBe('my-space')
      expect(useAuthStore().user.email).toBe('a@b.fr')
    })

    it('ne recharge pas le profil s\'il est déjà connu', async () => {
      useAuthStore().user = { id: 1, email: 'a@b.fr' }

      expect(await goTo('/mon-espace')).toBe('my-space')
      expect(api).not.toHaveBeenCalled()
    })

    it('est déconnecté et renvoyé sur /login si le token est refusé (expiré)', async () => {
      api.mockRejectedValue(new Error('Expired JWT Token'))

      expect(await goTo('/mon-espace')).toBe('login')
      expect(useAuthStore().isAuthenticated).toBe(false)
    })

    it('est renvoyé à l\'accueil s\'il va sur /login ou /register', async () => {
      useAuthStore().user = { id: 1, email: 'a@b.fr' }
      await goTo('/mon-espace') // on quitte l'accueil pour que la redirection soit observable

      expect(await goTo('/login')).toBe('home')

      await goTo('/mon-espace')
      expect(await goTo('/register')).toBe('home')
    })
  })
})
