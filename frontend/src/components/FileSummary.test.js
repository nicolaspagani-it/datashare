import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import FileSummary from './FileSummary.vue'
import iconImage from '../assets/icons/file-image.svg'
import iconAudio from '../assets/icons/file-audio.svg'
import iconVideo from '../assets/icons/file-video.svg'

const mountSummary = (props = {}, slots = {}) =>
  mount(FileSummary, { props: { name: 'photo.jpg', size: 2_600_000, type: 'image/jpeg', ...props }, slots })

describe('FileSummary', () => {
  it('affiche le nom et la taille formatée en français', () => {
    const wrapper = mountSummary({ name: 'IMG_9210.jpg', size: 2_600_000 })

    expect(wrapper.find('.file__name').text()).toBe('IMG_9210.jpg')
    expect(wrapper.find('.file__size').text()).toBe('2,6 Mo')
  })

  it.each([
    ['image/png', iconImage],
    ['audio/mpeg', iconAudio],
    ['video/mp4', iconVideo],
    ['application/pdf', iconImage],
    ['', iconImage],
  ])('choisit l\'icône selon le type MIME "%s"', (type, icon) => {
    expect(mountSummary({ type }).find('img.file__icon').attributes('src')).toBe(icon)
  })

  it('utilise l\'icône par défaut quand le type n\'est pas fourni', () => {
    const wrapper = mount(FileSummary, { props: { name: 'a', size: 1 } })

    expect(wrapper.find('img.file__icon').attributes('src')).toBe(iconImage)
  })

  it('l\'icône est décorative (texte alternatif vide)', () => {
    expect(mountSummary().find('img').attributes('alt')).toBe('')
  })

  it('affiche un contenu optionnel à droite (ex : le bouton "Changer")', () => {
    const wrapper = mountSummary({}, { default: '<button>Changer</button>' })

    expect(wrapper.find('button').text()).toBe('Changer')
    expect([...wrapper.find('.file').element.children].map((e) => e.tagName)).toEqual(['IMG', 'DIV', 'BUTTON'])
  })

  it('n\'affiche rien en plus sans contenu optionnel', () => {
    expect([...mountSummary().find('.file').element.children].map((e) => e.tagName)).toEqual(['IMG', 'DIV'])
  })
})
