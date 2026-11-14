import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { mount } from '@vue/test-utils'
import HomeView from './HomeView.vue'
import { makeRouter } from '../test-utils'

function mountHome() {
  const pinia = createPinia()
  setActivePinia(pinia)
  return mount(HomeView, { global: { plugins: [pinia, makeRouter()] } })
}

// Simule le choix d'un fichier dans le sélecteur du navigateur (jsdom ne permet pas
// d'écrire input.files directement : on redéfinit la propriété).
async function chooseFile(wrapper, file) {
  const input = wrapper.find('input[type="file"]')
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
  await input.trigger('change')
}
const makeFile = (name = 'photo.jpg', size = 2_600_000) => {
  const file = new File(['x'], name, { type: 'image/jpeg' })
  Object.defineProperty(file, 'size', { value: size })
  return file
}

describe('HomeView', () => {
  beforeEach(() => localStorage.clear())

  describe('avant le choix d\'un fichier', () => {
    it('affiche la question d\'accueil et le gros bouton d\'envoi', () => {
      const wrapper = mountHome()

      expect(wrapper.find('h1').text()).toBe('Tu veux partager un fichier ?')
      expect(wrapper.find('button.upload').attributes('aria-label')).toBe('Choisir un fichier à partager')
      expect(wrapper.find('.card').exists()).toBe(false)
    })

    it('affiche l\'en-tête et le pied de page (PublicLayout)', () => {
      const wrapper = mountHome()

      expect(wrapper.find('.site-header').exists()).toBe(true)
      expect(wrapper.find('.public__footer').text()).toContain('Copyright DataShare')
    })

    it('utilise un champ fichier caché, pour un seul fichier', () => {
      const input = mountHome().find('input[type="file"]')

      expect(input.attributes('hidden')).toBeDefined()
      expect(input.attributes('multiple')).toBeUndefined()
    })

    it('ouvre le sélecteur de fichiers du navigateur quand on clique sur le bouton rond', async () => {
      const click = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => {})
      const wrapper = mountHome()

      await wrapper.find('button.upload').trigger('click')

      expect(click).toHaveBeenCalledTimes(1)
    })
  })

  describe('après le choix d\'un fichier', () => {
    it('remplace l\'accueil par la carte "Ajouter un fichier"', async () => {
      const wrapper = mountHome()

      await chooseFile(wrapper, makeFile('IMG_9210.jpg'))

      expect(wrapper.find('button.upload').exists()).toBe(false)
      expect(wrapper.find('.card__title').text()).toBe('Ajouter un fichier')
      expect(wrapper.find('.file__name').text()).toBe('IMG_9210.jpg')
    })

    it('ignore une sélection vide (fenêtre fermée sans choisir)', async () => {
      const wrapper = mountHome()
      const input = wrapper.find('input[type="file"]')
      Object.defineProperty(input.element, 'files', { value: [], configurable: true })

      await input.trigger('change')

      expect(wrapper.find('button.upload').exists()).toBe(true)
    })

    it('"Changer" rouvre le sélecteur de fichiers', async () => {
      const click = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => {})
      const wrapper = mountHome()
      await chooseFile(wrapper, makeFile())

      const changer = wrapper.findAll('button').find((b) => b.text() === 'Changer')
      await changer.trigger('click')

      expect(click).toHaveBeenCalledTimes(1)
    })

    it('un nouveau fichier remplace le précédent', async () => {
      const wrapper = mountHome()
      await chooseFile(wrapper, makeFile('premier.jpg'))

      await chooseFile(wrapper, makeFile('second.jpg'))

      expect(wrapper.find('.file__name').text()).toBe('second.jpg')
    })
  })
})
