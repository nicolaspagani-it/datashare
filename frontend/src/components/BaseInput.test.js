import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseInput from './BaseInput.vue'

describe('BaseInput', () => {
  it('affiche le label et le relie au champ (cliquer sur le label donne le focus)', () => {
    const wrapper = mount(BaseInput, { props: { label: 'Email' } })

    const label = wrapper.find('label')
    const input = wrapper.find('input')

    expect(label.text()).toBe('Email')
    expect(input.attributes('id')).toBeTruthy()
    expect(label.attributes('for')).toBe(input.attributes('id'))
  })

  it('donne un identifiant différent à chaque champ d\'une même page', () => {
    // Deux champs dans le même parent, comme sur la page d'inscription.
    const Form = { components: { BaseInput }, template: '<div><BaseInput label="A" /><BaseInput label="B" /></div>' }

    const ids = mount(Form).findAll('input').map((i) => i.attributes('id'))

    expect(ids[0]).not.toBe(ids[1])
  })

  it('est de type "text" par défaut, ou du type demandé', () => {
    expect(mount(BaseInput, { props: { label: 'x' } }).find('input').attributes('type')).toBe('text')
    expect(mount(BaseInput, { props: { label: 'x', type: 'password' } }).find('input').attributes('type')).toBe('password')
  })

  it('affiche la valeur reçue par v-model', () => {
    const wrapper = mount(BaseInput, { props: { label: 'Email', modelValue: 'a@b.fr' } })

    expect(wrapper.find('input').element.value).toBe('a@b.fr')
  })

  it('émet update:modelValue quand on tape (c\'est ce qui fait marcher v-model)', async () => {
    const wrapper = mount(BaseInput, { props: { label: 'Email' } })

    await wrapper.find('input').setValue('nouveau@mail.fr')

    expect(wrapper.emitted('update:modelValue')[0]).toEqual(['nouveau@mail.fr'])
  })

  it('transmet les attributs (placeholder, required...) à l\'<input> et non à la <div>', () => {
    const wrapper = mount(BaseInput, {
      props: { label: 'Email' },
      attrs: { placeholder: 'Saisissez votre email...', required: true, autocomplete: 'email' },
    })

    const input = wrapper.find('input')
    expect(input.attributes('placeholder')).toBe('Saisissez votre email...')
    expect(input.attributes('required')).toBeDefined()
    expect(input.attributes('autocomplete')).toBe('email')
    expect(wrapper.element.getAttribute('placeholder')).toBeNull()
  })
})
