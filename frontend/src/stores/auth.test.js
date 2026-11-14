import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from './auth'
import { getToken, setToken } from '../api'

// On remplace SEULEMENT la fonction api() (les appels réseau) par un faux ;
// getToken / setToken restent les vrais, branchés sur le localStorage de jsdom.
vi.mock('../api', async (importOriginal) => ({ ...(await importOriginal()), api: vi.fn() }))
import { api } from '../api'

describe('store auth', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia()) // un store tout neuf pour chaque test
  })

  it('démarre déconnecté quand il n\'y a pas de token', () => {
    const auth = useAuthStore()

    expect(auth.token).toBeNull()
    expect(auth.user).toBeNull()
    expect(auth.isAuthenticated).toBe(false)
  })

  it('reste connecté après un rechargement : relit le token du localStorage', () => {
    setToken('jwt-existant')

    const auth = useAuthStore()

    expect(auth.token).toBe('jwt-existant')
    expect(auth.isAuthenticated).toBe(true)
  })

  describe('login()', () => {
    it('appelle POST /login, garde le token puis charge l\'utilisateur', async () => {
      api.mockImplementation(async (path) => {
        if (path === '/login') return { token: 'nouveau-jwt' }
        if (path === '/me') return { id: 1, email: 'a@b.fr', roles: ['ROLE_USER'] }
      })
      const auth = useAuthStore()

      await auth.login('a@b.fr', 'secret123')

      expect(api).toHaveBeenNthCalledWith(1, '/login', { method: 'POST', body: { email: 'a@b.fr', password: 'secret123' } })
      expect(api).toHaveBeenNthCalledWith(2, '/me')
      expect(auth.token).toBe('nouveau-jwt')
      expect(getToken()).toBe('nouveau-jwt') // aussi sauvegardé dans le localStorage
      expect(auth.user.email).toBe('a@b.fr')
      expect(auth.isAuthenticated).toBe(true)
    })

    it('ne garde rien si les identifiants sont refusés', async () => {
      api.mockRejectedValue(new Error('Identifiants invalides.'))
      const auth = useAuthStore()

      await expect(auth.login('a@b.fr', 'mauvais')).rejects.toThrow('Identifiants invalides.')

      expect(auth.isAuthenticated).toBe(false)
      expect(getToken()).toBeNull()
    })
  })

  describe('register()', () => {
    it('crée le compte puis connecte directement l\'utilisateur', async () => {
      api.mockImplementation(async (path) => {
        if (path === '/register') return { id: 1, email: 'a@b.fr' }
        if (path === '/login') return { token: 'jwt' }
        if (path === '/me') return { id: 1, email: 'a@b.fr', roles: ['ROLE_USER'] }
      })
      const auth = useAuthStore()

      await auth.register('a@b.fr', 'secret123')

      expect(api.mock.calls.map(([path]) => path)).toEqual(['/register', '/login', '/me'])
      expect(auth.isAuthenticated).toBe(true)
      expect(auth.user.email).toBe('a@b.fr')
    })

    it('ne tente pas de connexion si l\'inscription échoue', async () => {
      api.mockRejectedValue(new Error('Cet email est déjà utilisé.'))
      const auth = useAuthStore()

      await expect(auth.register('a@b.fr', 'secret123')).rejects.toThrow('déjà utilisé')

      expect(api).toHaveBeenCalledTimes(1)
      expect(auth.isAuthenticated).toBe(false)
    })
  })

  describe('fetchUser()', () => {
    it('charge l\'utilisateur courant via GET /me', async () => {
      api.mockResolvedValue({ id: 7, email: 'x@y.fr', roles: ['ROLE_USER'] })
      const auth = useAuthStore()

      await auth.fetchUser()

      expect(api).toHaveBeenCalledWith('/me')
      expect(auth.user.id).toBe(7)
    })
  })

  describe('logout()', () => {
    it('oublie le token et l\'utilisateur, aussi dans le localStorage', async () => {
      setToken('jwt')
      const auth = useAuthStore()
      auth.user = { id: 1, email: 'a@b.fr' }

      auth.logout()

      expect(auth.token).toBeNull()
      expect(auth.user).toBeNull()
      expect(auth.isAuthenticated).toBe(false)
      expect(getToken()).toBeNull()
    })
  })
})
