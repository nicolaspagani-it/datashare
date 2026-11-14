import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { mount } from '@vue/test-utils'
import SiteHeader from './SiteHeader.vue'
import { useAuthStore } from '../stores/auth'
import { makeRouter } from '../test-utils'

function mountHeader() {
  const pinia = createPinia()
  setActivePinia(pinia)
  return { wrapper: mount(SiteHeader, { global: { plugins: [pinia, makeRouter()] } }), auth: useAuthStore() }
}

describe('SiteHeader', () => {
  beforeEach(() => localStorage.clear())

  it('affiche le logo qui renvoie à l\'accueil', () => {
    const { wrapper } = mountHeader()

    const logo = wrapper.find('.site-header__logo')
    expect(logo.text()).toBe('DataShare')
    expect(logo.attributes('href')).toBe('/')
  })

  it('propose "Se connecter" à un visiteur', () => {
    const { wrapper } = mountHeader()

    const button = wrapper.find('a.btn')
    expect(button.text()).toBe('Se connecter')
    expect(button.attributes('href')).toBe('/login')
  })

  it('propose "Mon espace" à un utilisateur connecté', async () => {
    const { wrapper, auth } = mountHeader()

    auth.token = 'un-jwt' // l'affichage se met à jour tout seul (réactivité)
    await wrapper.vm.$nextTick()

    const button = wrapper.find('a.btn')
    expect(button.text()).toBe('Mon espace')
    expect(button.attributes('href')).toBe('/mon-espace')
  })
})
