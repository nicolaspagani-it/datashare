import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AppSidebar from './AppSidebar.vue'
import { makeRouter } from '../test-utils'

const mountSidebar = (props = {}) => mount(AppSidebar, { props, global: { plugins: [makeRouter()] } })

describe('AppSidebar', () => {
  it('affiche le logo, le lien "Mes fichiers" et le copyright', () => {
    const wrapper = mountSidebar()

    expect(wrapper.find('.sidebar__logo').text()).toBe('DataShare')
    expect(wrapper.find('.nav-item').text()).toBe('Mes fichiers')
    expect(wrapper.find('.nav-item').attributes('href')).toBe('/mon-espace')
    expect(wrapper.find('.sidebar__copyright').text()).toContain('Copyright DataShare')
  })

  it('est fermée par défaut (pas de fond sombre)', () => {
    const wrapper = mountSidebar()

    expect(wrapper.find('.sidebar').classes()).not.toContain('sidebar--open')
    expect(wrapper.find('.overlay').exists()).toBe(false)
  })

  it('s\'ouvre avec la prop "open" et affiche le fond sombre', () => {
    const wrapper = mountSidebar({ open: true })

    expect(wrapper.find('.sidebar').classes()).toContain('sidebar--open')
    expect(wrapper.find('.overlay').exists()).toBe(true)
  })

  it('demande sa fermeture avec la croix, le fond sombre ou un clic sur le lien', async () => {
    const wrapper = mountSidebar({ open: true })

    await wrapper.find('.sidebar__close').trigger('click')
    await wrapper.find('.overlay').trigger('click')
    await wrapper.find('.nav-item').trigger('click') // après un clic sur le lien, le tiroir doit se fermer

    expect(wrapper.emitted('close')).toHaveLength(3)
  })
})
