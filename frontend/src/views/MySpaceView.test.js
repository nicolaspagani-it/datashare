import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import MySpaceView from './MySpaceView.vue'
import { useAuthStore } from '../stores/auth'
import { useFilesStore } from '../stores/files'
import { makeRouter } from '../test-utils'

// Faux appels réseau : on contrôle ce que répondent GET /api/files et DELETE /api/files/{id}.
vi.mock('../api', async (importOriginal) => ({ ...(await importOriginal()), api: vi.fn() }))
import { api } from '../api'

const DAY = 24 * 60 * 60 * 1000
const iso = (offsetMs) => new Date(Date.now() + offsetMs).toISOString()

// Les fichiers de l'utilisateur, tels que les renvoie l'API.
const API_FILES = [
  { id: 3, name: 'photo.jpg', type: 'image/jpeg', url: 'http://localhost:5173/AAAA1111', protected: true, expiresAt: iso(2 * DAY - 1000) },
  { id: 2, name: 'musique.mp3', type: 'audio/mpeg', url: 'http://localhost:5173/BBBB2222', protected: false, expiresAt: iso(DAY - 1000) },
  { id: 1, name: 'vacances.mp4', type: 'video/mp4', url: 'http://localhost:5173/CCCC3333', protected: false, expiresAt: iso(-DAY) },
]

// Monte la page : par défaut l'API renvoie API_FILES, et on attend la fin du chargement.
async function mountView({ files = API_FILES, waitForLoad = true } = {}) {
  api.mockReset()
  api.mockImplementation(async (path, options) => {
    if (path === '/files' && !options) return files
    if (path.startsWith('/files/') && options?.method === 'DELETE') return null
  })

  const pinia = createPinia()
  setActivePinia(pinia)
  const router = makeRouter()
  router.push('/mon-espace')
  await router.isReady()

  const auth = useAuthStore()
  auth.token = 'jwt'
  auth.user = { id: 1, email: 'claire.marie@example.com', roles: ['ROLE_USER'] }

  const wrapper = mount(MySpaceView, { global: { plugins: [pinia, router] } })
  if (waitForLoad) await flushPromises()
  return { wrapper, router, auth, files: useFilesStore() }
}

const names = (wrapper) => wrapper.findAll('.row__name').map((n) => n.text())
const clickTab = (wrapper, label) => wrapper.findAll('[role="tab"]').find((t) => t.text() === label).trigger('click')
const deleteButton = (wrapper, rowIndex = 0) => wrapper.findAll('.row')[rowIndex].find('.row__actions--desktop button')

describe('MySpaceView', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.stubGlobal('confirm', vi.fn(() => true)) // par défaut, l'utilisateur confirme les suppressions
  })

  describe('chargement des fichiers', () => {
    it('demande la liste à l\'API (GET /files) à l\'ouverture de la page', async () => {
      await mountView()

      expect(api).toHaveBeenCalledWith('/files')
    })

    it('affiche "Chargement" tant que la liste n\'est pas arrivée', async () => {
      const { wrapper } = await mountView({ waitForLoad: false })

      expect(wrapper.find('.content__info').text()).toBe('Chargement de tes fichiers…')
      expect(wrapper.find('.row').exists()).toBe(false)
      expect(wrapper.find('[role="tablist"]').exists()).toBe(false)
    })

    it('affiche les VRAIS fichiers de l\'utilisateur, dans l\'ordre de l\'API', async () => {
      const { wrapper } = await mountView()

      expect(wrapper.find('h1').text()).toBe('Mes fichiers')
      expect(names(wrapper)).toEqual(['photo.jpg', 'musique.mp3', 'vacances.mp4'])
      expect(wrapper.text()).not.toContain('IMG_9210') // plus de données de démonstration
    })

    it('affiche pour chaque fichier son expiration', async () => {
      const { wrapper } = await mountView()

      expect(wrapper.findAll('.row__expiry').map((e) => e.text())).toEqual(['Expire dans 2 jours', 'Expire demain', 'Expiré'])
    })

    it('affiche un cadenas seulement sur les fichiers protégés par mot de passe', async () => {
      const { wrapper } = await mountView()

      const locks = wrapper.findAll('.row').map((r) => r.find('.row__actions--desktop svg.lucide-lock').exists())
      expect(locks).toEqual([true, false, false])
    })

    it('un fichier expiré n\'offre plus d\'action', async () => {
      const { wrapper } = await mountView()

      expect(wrapper.findAll('.row')[2].findAll('button')).toHaveLength(0)
      expect(wrapper.findAll('.row')[2].find('.row__message').exists()).toBe(true)
    })

    it('affiche l\'erreur du serveur avec un bouton "Réessayer"', async () => {
      const { wrapper, files } = await mountView({ waitForLoad: false })
      api.mockReset()
      api.mockRejectedValueOnce(new Error('Le serveur est indisponible.')).mockResolvedValueOnce(API_FILES)
      await files.fetch()
      await flushPromises()

      expect(wrapper.find('.content__error').text()).toContain('Le serveur est indisponible.')
      expect(wrapper.find('.row').exists()).toBe(false)

      await wrapper.find('.content__error button').trigger('click')
      await flushPromises()

      expect(wrapper.find('.content__error').exists()).toBe(false)
      expect(names(wrapper)).toHaveLength(3)
    })

    it('invite à ajouter un fichier quand l\'utilisateur n\'en a aucun', async () => {
      const { wrapper } = await mountView({ files: [] })

      expect(wrapper.find('.content__empty-all').text()).toContain('Tu n\'as pas encore partagé de fichier.')
      const link = wrapper.find('.content__empty-all a')
      expect(link.text()).toBe('Ajouter un fichier')
      expect(link.attributes('href')).toBe('/')
      expect(wrapper.find('[role="tablist"]').exists()).toBe(false)
    })
  })

  describe('filtres', () => {
    it('"Actifs" masque le fichier expiré', async () => {
      const { wrapper } = await mountView()

      await clickTab(wrapper, 'Actifs')

      expect(names(wrapper)).toEqual(['photo.jpg', 'musique.mp3'])
    })

    it('"Expiré" ne garde que le fichier expiré', async () => {
      const { wrapper } = await mountView()

      await clickTab(wrapper, 'Expiré')

      expect(names(wrapper)).toEqual(['vacances.mp4'])
    })

    it('"Tous" réaffiche tout', async () => {
      const { wrapper } = await mountView()
      await clickTab(wrapper, 'Expiré')

      await clickTab(wrapper, 'Tous')

      expect(names(wrapper)).toHaveLength(3)
    })

    it('affiche un message quand le filtre ne donne aucun résultat', async () => {
      const { wrapper } = await mountView({ files: [API_FILES[2]] }) // un seul fichier, expiré
      expect(wrapper.find('.files + .content__info').exists()).toBe(false)

      await clickTab(wrapper, 'Actifs')

      expect(wrapper.find('.files + .content__info').text()).toBe('Aucun fichier à afficher.')
    })
  })

  describe('actions sur un fichier', () => {
    it('demande confirmation puis supprime le fichier via DELETE /files/{id}', async () => {
      const { wrapper } = await mountView()

      await deleteButton(wrapper, 0).trigger('click') // Supprimer "photo.jpg"
      await flushPromises()

      expect(window.confirm).toHaveBeenCalledWith('Supprimer « photo.jpg » ? Le lien de partage ne fonctionnera plus.')
      expect(api).toHaveBeenCalledWith('/files/3', { method: 'DELETE' })
      expect(names(wrapper)).toEqual(['musique.mp3', 'vacances.mp4'])
    })

    it('ne supprime rien si l\'utilisateur annule', async () => {
      const { wrapper } = await mountView()
      window.confirm.mockReturnValue(false)

      await deleteButton(wrapper, 0).trigger('click')
      await flushPromises()

      expect(api).not.toHaveBeenCalledWith('/files/3', expect.anything())
      expect(names(wrapper)).toHaveLength(3)
    })

    it('garde le fichier et affiche l\'erreur si le serveur refuse la suppression', async () => {
      const { wrapper } = await mountView()
      api.mockRejectedValueOnce(new Error('Fichier introuvable.'))

      await deleteButton(wrapper, 0).trigger('click')
      await flushPromises()

      expect(wrapper.find('.content__action-error').text()).toBe('Fichier introuvable.')
      expect(names(wrapper)).toHaveLength(3)
    })

    it('efface l\'erreur de suppression à l\'action suivante', async () => {
      const { wrapper } = await mountView()
      api.mockRejectedValueOnce(new Error('Fichier introuvable.'))
      await deleteButton(wrapper, 0).trigger('click')
      await flushPromises()
      expect(wrapper.find('.content__action-error').exists()).toBe(true)

      await deleteButton(wrapper, 0).trigger('click') // second essai : réussit
      await flushPromises()

      expect(wrapper.find('.content__action-error').exists()).toBe(false)
    })

    it('"Accéder" ouvre le lien de partage dans un nouvel onglet', async () => {
      const open = vi.fn()
      vi.stubGlobal('open', open)
      const { wrapper } = await mountView()

      await wrapper.findAll('.row')[1].findAll('.row__actions--desktop button')[1].trigger('click') // Accéder "musique.mp3"

      expect(open).toHaveBeenCalledWith('http://localhost:5173/BBBB2222', '_blank', 'noopener')
    })
  })

  describe('barre du haut et menu', () => {
    it('affiche le nom de l\'utilisateur (partie avant le @) et son initiale', async () => {
      const { wrapper } = await mountView()

      expect(wrapper.find('.topbar__name').text()).toBe('claire.marie')
      expect(wrapper.find('.avatar').text()).toBe('C')
    })

    it('ouvre le tiroir de la sidebar avec le bouton ☰, et le ferme avec la croix', async () => {
      const { wrapper } = await mountView()
      expect(wrapper.find('.sidebar').classes()).not.toContain('sidebar--open')

      await wrapper.find('.topbar__menu').trigger('click')
      expect(wrapper.find('.sidebar').classes()).toContain('sidebar--open')
      expect(wrapper.find('.overlay').exists()).toBe(true)

      await wrapper.find('.sidebar__close').trigger('click')
      expect(wrapper.find('.sidebar').classes()).not.toContain('sidebar--open')
      expect(wrapper.find('.overlay').exists()).toBe(false)
    })

    it('ferme le tiroir quand on clique sur le fond sombre', async () => {
      const { wrapper } = await mountView()
      await wrapper.find('.topbar__menu').trigger('click')

      await wrapper.find('.overlay').trigger('click')

      expect(wrapper.find('.sidebar').classes()).not.toContain('sidebar--open')
    })

    it('met en avant "Mes fichiers" dans la navigation', async () => {
      const { wrapper } = await mountView()

      const item = wrapper.find('.nav-item')
      expect(item.text()).toBe('Mes fichiers')
      expect(item.classes()).toContain('nav-item--active')
    })

    it('"Ajouter des fichiers" renvoie vers l\'accueil', async () => {
      const { wrapper } = await mountView()

      expect(wrapper.find('.topbar__actions a.btn').attributes('href')).toBe('/')
    })

    it('"Déconnexion" vide la session ET la liste des fichiers, puis renvoie sur /login', async () => {
      const { wrapper, router, auth, files } = await mountView()
      expect(files.items).toHaveLength(3)
      const button = wrapper.findAll('.topbar__actions button').find((b) => b.text().includes('Déconnexion'))

      await button.trigger('click')
      await flushPromises()

      expect(auth.isAuthenticated).toBe(false)
      expect(files.items).toEqual([]) // aucune fuite vers l'utilisateur suivant
      expect(router.currentRoute.value.name).toBe('login')
    })
  })
})
