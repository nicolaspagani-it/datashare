import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseSelect from './BaseSelect.vue'

const options = [
  { value: '1d', label: 'Une journée' },
  { value: '7d', label: 'Une semaine' },
]
const mountSelect = (props = {}) => mount(BaseSelect, { props: { label: 'Expiration', options, modelValue: '1d', ...props } })

describe('BaseSelect', () => {
  it('affiche le label et le relie à la liste', () => {
    const wrapper = mountSelect()

    expect(wrapper.find('label').text()).toBe('Expiration')
    expect(wrapper.find('label').attributes('for')).toBe(wrapper.find('select').attributes('id'))
  })

  it('affiche une option par choix', () => {
    const wrapper = mountSelect()

    expect(wrapper.findAll('option').map((o) => [o.attributes('value'), o.text()])).toEqual([
      ['1d', 'Une journée'],
      ['7d', 'Une semaine'],
    ])
  })

  it('sélectionne la valeur reçue par v-model', () => {
    expect(mountSelect({ modelValue: '7d' }).find('select').element.value).toBe('7d')
  })

  it('émet update:modelValue quand on change de choix', async () => {
    const wrapper = mountSelect()

    await wrapper.find('select').setValue('7d')

    expect(wrapper.emitted('update:modelValue')[0]).toEqual(['7d'])
  })

  it('affiche le chevron du design', () => {
    expect(mountSelect().find('.field__chevron').exists()).toBe(true)
  })
})
