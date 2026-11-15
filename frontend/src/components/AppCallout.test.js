import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AppCallout from './AppCallout.vue'

const mountCallout = (variant, text = 'Mon message') => mount(AppCallout, { props: { variant }, slots: { default: text } })

describe('AppCallout', () => {
  it('affiche son texte', () => {
    expect(mountCallout('info', 'Ce fichier expirera dans 3 jours.').text()).toBe('Ce fichier expirera dans 3 jours.')
  })

  it.each(['info', 'warning', 'error'])('applique la couleur de la variante %s', (variant) => {
    expect(mountCallout(variant).classes()).toContain(`callout--${variant}`)
  })

  it('est un bandeau "info" par défaut', () => {
    expect(mount(AppCallout, { slots: { default: 'x' } }).classes()).toContain('callout--info')
  })

  it('affiche une icône, différente pour chaque variante', () => {
    const icons = ['info', 'warning', 'error'].map((v) => mountCallout(v).find('svg').html())

    icons.forEach((icon) => expect(icon).toContain('<svg'))
    expect(new Set(icons).size).toBe(3)
  })

  it('cache l\'icône aux lecteurs d\'écran (décorative)', () => {
    expect(mountCallout('info').find('svg').attributes('aria-hidden')).toBe('true')
  })

  it('annonce immédiatement une erreur aux lecteurs d\'écran, et les autres de façon discrète', () => {
    expect(mountCallout('error').attributes('role')).toBe('alert')
    expect(mountCallout('info').attributes('role')).toBe('status')
    expect(mountCallout('warning').attributes('role')).toBe('status')
  })
})
