import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import FilterTabs from './FilterTabs.vue'

const options = [
  { value: 'all', label: 'Tous' },
  { value: 'active', label: 'Actifs' },
  { value: 'expired', label: 'Expiré' },
]

describe('FilterTabs', () => {
  it('affiche un onglet par option', () => {
    const wrapper = mount(FilterTabs, { props: { modelValue: 'all', options } })

    expect(wrapper.findAll('[role="tab"]').map((t) => t.text())).toEqual(['Tous', 'Actifs', 'Expiré'])
  })

  it('met en avant uniquement l\'onglet sélectionné', () => {
    const wrapper = mount(FilterTabs, { props: { modelValue: 'active', options } })

    const tabs = wrapper.findAll('[role="tab"]')
    expect(tabs.map((t) => t.classes('tabs__item--active'))).toEqual([false, true, false])
    expect(tabs.map((t) => t.attributes('aria-selected'))).toEqual(['false', 'true', 'false'])
  })

  it('émet update:modelValue avec la valeur de l\'onglet cliqué', async () => {
    const wrapper = mount(FilterTabs, { props: { modelValue: 'all', options } })

    await wrapper.findAll('[role="tab"]')[2].trigger('click')

    expect(wrapper.emitted('update:modelValue')[0]).toEqual(['expired'])
  })

  it('met à jour l\'onglet actif quand la valeur change', async () => {
    const wrapper = mount(FilterTabs, { props: { modelValue: 'all', options } })

    await wrapper.setProps({ modelValue: 'expired' })

    expect(wrapper.findAll('[role="tab"]')[2].classes('tabs__item--active')).toBe(true)
  })
})
