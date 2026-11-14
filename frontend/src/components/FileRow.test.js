import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import FileRow from './FileRow.vue'
import iconImage from '../assets/icons/file-image.svg'
import iconAudio from '../assets/icons/file-audio.svg'
import iconVideo from '../assets/icons/file-video.svg'

const DAY = 24 * 60 * 60 * 1000

// Fabrique un fichier de test ; on surcharge seulement ce qui nous intéresse.
const makeFile = (overrides = {}) => ({
  id: 1,
  name: 'photo.jpg',
  type: 'image/jpeg', // type MIME, tel que le renvoie l'API
  expiresAt: Date.now() + 2 * DAY - 1000,
  protected: false,
  ...overrides,
})

const mountRow = (file) => mount(FileRow, { props: { file } })

describe('FileRow', () => {
  describe('contenu', () => {
    it('affiche le nom et la date d\'expiration', () => {
      const wrapper = mountRow(makeFile({ name: 'compo2.mp3' }))

      expect(wrapper.find('.row__name').text()).toBe('compo2.mp3')
      expect(wrapper.find('.row__expiry').text()).toBe('Expire dans 2 jours')
    })

    // On compare avec les vrais fichiers importés (Vite les remplace par une URL ou un data-URI).
    it.each([
      ['image/png', iconImage],
      ['audio/mpeg', iconAudio],
      ['video/mp4', iconVideo],
    ])('affiche l\'icône du type %s', (type, icon) => {
      const wrapper = mountRow(makeFile({ type }))

      expect(wrapper.find('img.row__icon').attributes('src')).toBe(icon)
    })

    it('les trois icônes sont bien différentes', () => {
      expect(new Set([iconImage, iconAudio, iconVideo]).size).toBe(3)
    })

    it('utilise l\'icône image pour un type inconnu', () => {
      const wrapper = mountRow(makeFile({ type: 'application/pdf' }))

      expect(wrapper.find('img.row__icon').attributes('src')).toBe(iconImage)
    })

    it('affiche un cadenas seulement si le fichier est protégé', () => {
      expect(mountRow(makeFile({ protected: true })).find('.row__actions--desktop svg').exists()).toBe(true)
      expect(mountRow(makeFile({ protected: false })).find('.row__actions--desktop svg.lucide-lock').exists()).toBe(false)
    })
  })

  describe('fichier actif', () => {
    it('propose "Supprimer" et "Accéder"', () => {
      const wrapper = mountRow(makeFile())

      const labels = wrapper.findAll('.row__actions--desktop button').map((b) => b.text())
      expect(labels).toEqual(['Supprimer', 'Accéder'])
      expect(wrapper.find('.row__message').exists()).toBe(false)
      expect(wrapper.find('.row__expiry--expired').exists()).toBe(false)
    })

    it('émet "delete" avec le fichier quand on clique sur Supprimer', async () => {
      const file = makeFile()
      const wrapper = mountRow(file)

      await wrapper.findAll('.row__actions--desktop button')[0].trigger('click')

      expect(wrapper.emitted('delete')[0]).toEqual([file])
    })

    it('émet "open" avec le fichier quand on clique sur Accéder', async () => {
      const file = makeFile()
      const wrapper = mountRow(file)

      await wrapper.findAll('.row__actions--desktop button')[1].trigger('click')

      expect(wrapper.emitted('open')[0]).toEqual([file])
    })
  })

  describe('menu mobile (⋮)', () => {
    it('est fermé au départ, puis s\'ouvre et se ferme au clic sur le bouton ⋮', async () => {
      const wrapper = mountRow(makeFile())
      const toggle = wrapper.find('.row__actions--mobile button')

      expect(wrapper.find('.menu').exists()).toBe(false)

      await toggle.trigger('click')
      expect(wrapper.find('.menu').exists()).toBe(true)
      expect(wrapper.findAll('.menu button').map((b) => b.text())).toEqual(['Accéder', 'Supprimer'])

      await toggle.trigger('click')
      expect(wrapper.find('.menu').exists()).toBe(false)
    })

    it('émet l\'action choisie puis se referme', async () => {
      const file = makeFile()
      const wrapper = mountRow(file)
      await wrapper.find('.row__actions--mobile button').trigger('click')

      await wrapper.findAll('.menu button')[1].trigger('click') // Supprimer

      expect(wrapper.emitted('delete')[0]).toEqual([file])
      expect(wrapper.find('.menu').exists()).toBe(false)
    })

    it('émet "open" depuis le menu quand on choisit Accéder', async () => {
      const file = makeFile()
      const wrapper = mountRow(file)
      await wrapper.find('.row__actions--mobile button').trigger('click')

      await wrapper.findAll('.menu button')[0].trigger('click') // Accéder

      expect(wrapper.emitted('open')[0]).toEqual([file])
    })

    it('se ferme avec la touche Échap', async () => {
      const wrapper = mountRow(makeFile())
      await wrapper.find('.row__actions--mobile button').trigger('click')

      await wrapper.find('.row__actions--mobile').trigger('keydown.esc')

      expect(wrapper.find('.menu').exists()).toBe(false)
    })
  })

  describe('fichier expiré', () => {
    const expired = () => makeFile({ expiresAt: Date.now() - DAY })

    it('affiche "Expiré" en rouge et le message explicatif', () => {
      const wrapper = mountRow(expired())

      expect(wrapper.find('.row__expiry').text()).toBe('Expiré')
      expect(wrapper.find('.row__expiry--expired').exists()).toBe(true)
      expect(wrapper.find('.row__message').text()).toContain('n’est plus stocké chez nous')
    })

    it('n\'offre plus aucune action', () => {
      const wrapper = mountRow(expired())

      expect(wrapper.findAll('button')).toHaveLength(0)
    })
  })
})
