import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseButton from './BaseButton.vue'
import { makeRouter } from '../test-utils'

describe('BaseButton', () => {
  it('affiche son contenu dans un <button type="button"> par défaut', () => {
    const wrapper = mount(BaseButton, { slots: { default: 'Cliquer' } })

    expect(wrapper.element.tagName).toBe('BUTTON')
    expect(wrapper.text()).toBe('Cliquer')
    expect(wrapper.attributes('type')).toBe('button') // évite d'envoyer un formulaire par erreur
  })

  it('peut être un bouton de validation (type="submit")', () => {
    const wrapper = mount(BaseButton, { props: { type: 'submit' } })

    expect(wrapper.attributes('type')).toBe('submit')
  })

  it('applique la variante et la taille demandées', () => {
    const wrapper = mount(BaseButton, { props: { variant: 'dark', size: 'lg' } })

    expect(wrapper.classes()).toContain('btn--dark')
    expect(wrapper.classes()).toContain('btn--lg')
  })

  it('a la variante "outline" et la taille "md" par défaut', () => {
    const wrapper = mount(BaseButton)

    expect(wrapper.classes()).toContain('btn--outline')
    expect(wrapper.classes()).toContain('btn--md')
    expect(wrapper.classes()).not.toContain('btn--block')
  })

  it('prend toute la largeur avec "block"', () => {
    expect(mount(BaseButton, { props: { block: true } }).classes()).toContain('btn--block')
  })

  it('devient un lien quand on lui donne une destination "to"', () => {
    const wrapper = mount(BaseButton, {
      props: { to: { name: 'login' } },
      slots: { default: 'Se connecter' },
      global: { plugins: [makeRouter()] },
    })

    expect(wrapper.element.tagName).toBe('A')
    expect(wrapper.attributes('href')).toBe('/login')
    expect(wrapper.attributes('type')).toBeUndefined()
  })

  it('transmet le clic au parent (@click)', async () => {
    const onClick = vi.fn()
    const wrapper = mount(BaseButton, { attrs: { onClick } })

    await wrapper.trigger('click')

    expect(onClick).toHaveBeenCalledTimes(1)
  })

  it('peut être désactivé (attribut disabled transmis au <button>)', () => {
    const wrapper = mount(BaseButton, { attrs: { disabled: true } })

    expect(wrapper.attributes('disabled')).toBeDefined()
  })
})
