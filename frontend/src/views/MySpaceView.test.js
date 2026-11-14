import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import MySpaceView from './MySpaceView.vue'
import { useAuthStore } from '../stores/auth'
import { makeRouter } from '../test-utils'

async function mountView() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = makeRouter()
  router.push('/mon-espace')
  await router.isReady()

  const auth = useAuthStore()
  auth.token = 'jwt'
  auth.user = { id: 1, email: 'claire.marie@example.com', roles: ['ROLE_USER'] }

  const wrapper = mount(MySpaceView, { global: { plugins: [pinia, router] } })
  return { wrapper, router, auth }
}

const names = (wrapper) => wrapper.findAll('.row__name').map((n) => n.text())
const clickTab = (wrapper, label) => wrapper.findAll('[role="tab"]').find((t) => t.text() === label).trigger('click')

describe('MySpaceView', () => {
  beforeEach(() => localStorage.clear())

  describe('liste des fichiers', () => {
    it('affiche le titre et les 3 fichiers de démonstration', async () => {
      const { wrapper } = await mountView()

      expect(wrapper.find('h1').text()).toBe('Mes fichiers')
      expect(names(wrapper)).toEqual(['IMG_9210_123123131313213231.jpg', 'compo2.mp3', 'vacances_ardeche.mp4'])
    })

    it('filtre "Actifs" : masque le fichier expiré', async () => {
      const { wrapper } = await mountView()

      await clickTab(wrapper, 'Actifs')

      expect(names(wrapper)).toEqual(['IMG_9210_123123131313213231.jpg', 'compo2.mp3'])
    })

    it('filtre "Expiré" : ne garde que le fichier expiré', async () => {
      const { wrapper } = await mountView()

      await clickTab(wrapper, 'Expiré')

      expect(names(wrapper)).toEqual(['vacances_ardeche.mp4'])
    })

    it('filtre "Tous" : réaffiche tout', async () => {
      const { wrapper } = await mountView()
      await clickTab(wrapper, 'Expiré')

      await clickTab(wrapper, 'Tous')

      expect(names(wrapper)).toHaveLength(3)
    })

    it('retire un fichier de la liste quand on clique sur Supprimer', async () => {
      const { wrapper } = await mountView()

      await wrapper.find('.row__actions--desktop button').trigger('click') // Supprimer du 1er fichier

      expect(names(wrapper)).toEqual(['compo2.mp3', 'vacances_ardeche.mp4'])
    })

    it('affiche un message quand le filtre ne donne aucun résultat', async () => {
      const { wrapper } = await mountView()
      expect(wrapper.find('.content__empty').exists()).toBe(false)

      // On supprime les deux fichiers actifs, puis on regarde l'onglet "Actifs".
      await wrapper.find('.row__actions--desktop button').trigger('click')
      await wrapper.find('.row__actions--desktop button').trigger('click')
      await clickTab(wrapper, 'Actifs')

      expect(wrapper.find('.content__empty').text()).toBe('Aucun fichier à afficher.')
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

      // Le routeur de test a une page vide à /mon-espace : le lien est actif sur cette URL.
      const item = wrapper.find('.nav-item')
      expect(item.text()).toBe('Mes fichiers')
      expect(item.classes()).toContain('nav-item--active')
    })

    it('"Ajouter des fichiers" renvoie vers l\'accueil', async () => {
      const { wrapper } = await mountView()

      expect(wrapper.find('.topbar__actions a.btn').attributes('href')).toBe('/')
    })

    it('"Déconnexion" vide la session et renvoie sur /login', async () => {
      const { wrapper, router, auth } = await mountView()
      const logout = vi.spyOn(auth, 'logout')
      const button = wrapper.findAll('.topbar__actions button').find((b) => b.text().includes('Déconnexion'))

      await button.trigger('click')
      await flushPromises()

      expect(logout).toHaveBeenCalled()
      expect(auth.isAuthenticated).toBe(false)
      expect(router.currentRoute.value.name).toBe('login')
    })
  })
})
