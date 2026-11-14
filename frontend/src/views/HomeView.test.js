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

describe('HomeView', () => {
  beforeEach(() => localStorage.clear())

  it('affiche la question d\'accueil et le gros bouton d\'envoi', () => {
    const wrapper = mountHome()

    expect(wrapper.find('h1').text()).toBe('Tu veux partager un fichier ?')
    expect(wrapper.find('button.upload').attributes('aria-label')).toBe('Choisir des fichiers à partager')
  })

  it('affiche l\'en-tête et le pied de page (PublicLayout)', () => {
    const wrapper = mountHome()

    expect(wrapper.find('.site-header').exists()).toBe(true)
    expect(wrapper.find('.public__footer').text()).toContain('Copyright DataShare')
  })

  it('accepte plusieurs fichiers dans le champ caché', () => {
    const input = mountHome().find('input[type="file"]')

    expect(input.attributes('multiple')).toBeDefined()
    expect(input.attributes('hidden')).toBeDefined()
  })

  it('ouvre le sélecteur de fichiers du navigateur quand on clique sur le bouton rond', async () => {
    const click = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => {})
    const wrapper = mountHome()

    await wrapper.find('button.upload').trigger('click')

    expect(click).toHaveBeenCalledTimes(1)
  })

  it('prend en compte les fichiers choisis', async () => {
    const info = vi.spyOn(console, 'info').mockImplementation(() => {})
    const wrapper = mountHome()
    const input = wrapper.find('input[type="file"]')
    // jsdom ne permet pas d'écrire input.files directement : on le simule.
    Object.defineProperty(input.element, 'files', { value: [{ name: 'photo.jpg' }, { name: 'musique.mp3' }] })

    await input.trigger('change')

    expect(info).toHaveBeenCalledWith('Fichiers choisis :', ['photo.jpg', 'musique.mp3'])
  })
})
