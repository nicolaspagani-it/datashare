import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import UploadForm from './UploadForm.vue'
import iconImage from '../assets/icons/file-image.svg'
import iconAudio from '../assets/icons/file-audio.svg'
import iconVideo from '../assets/icons/file-video.svg'

// Faux appels réseau : on contrôle la réponse de POST /api/files.
vi.mock('../api', async (importOriginal) => ({ ...(await importOriginal()), api: vi.fn() }))
import { api } from '../api'

// Fabrique un fichier de test avec la taille voulue (File.size est en lecture seule : on la redéfinit).
function makeFile({ name = 'photo.jpg', type = 'image/jpeg', size = 2_600_000 } = {}) {
  const file = new File(['contenu'], name, { type })
  Object.defineProperty(file, 'size', { value: size })
  return file
}

const mountForm = (file = makeFile()) => mount(UploadForm, { props: { file } })
const submit = async (wrapper) => {
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

describe('UploadForm', () => {
  // Les accolades sont importantes : sans elles, la fonction renverrait le mock, et Vitest
  // l'appellerait comme "nettoyage" après chaque test.
  beforeEach(() => {
    api.mockReset()
  })

  describe('configuration', () => {
    it('affiche le titre, le nom et la taille du fichier', () => {
      const wrapper = mountForm(makeFile({ name: 'IMG_9210.jpg', size: 2_600_000 }))

      expect(wrapper.find('h1').text()).toBe('Ajouter un fichier')
      expect(wrapper.find('.file__name').text()).toBe('IMG_9210.jpg')
      expect(wrapper.find('.file__size').text()).toBe('2,6 Mo')
    })

    it.each([
      ['image/png', iconImage],
      ['audio/mpeg', iconAudio],
      ['video/mp4', iconVideo],
      ['application/pdf', iconImage], // type sans icône dédiée : icône par défaut
    ])('choisit l\'icône selon le type du fichier (%s)', (type, icon) => {
      const wrapper = mountForm(makeFile({ type }))

      expect(wrapper.find('img.file__icon').attributes('src')).toBe(icon)
    })

    it('propose un mot de passe optionnel et une durée de conservation', () => {
      const wrapper = mountForm()

      expect(wrapper.find('input[type="password"]').attributes('placeholder')).toBe('Optionnel')
      expect(wrapper.find('select').element.value).toBe('1') // "Une journée" par défaut, comme dans le design
      expect(wrapper.find('button[type="submit"]').text()).toContain('Téléverser')
    })

    it('propose les durées de 1 jour à 1 semaine', () => {
      const options = mountForm().findAll('option')

      expect(options.map((o) => o.text())).toEqual([
        'Une journée',
        '2 jours',
        '3 jours',
        '4 jours',
        '5 jours',
        '6 jours',
        'Une semaine',
      ])
      expect(options.map((o) => o.attributes('value'))).toEqual(['1', '2', '3', '4', '5', '6', '7'])
    })

    it('demande un autre fichier avec le bouton "Changer"', async () => {
      const wrapper = mountForm()

      const changer = wrapper.findAll('button').find((b) => b.text() === 'Changer')
      await changer.trigger('click')

      expect(wrapper.emitted('change-file')).toHaveLength(1)
    })
  })

  describe('fichier trop gros (> 1 Go)', () => {
    const big = () => makeFile({ name: 'IMG_9210.jpg', size: 3_100_000_000 })

    it('affiche la taille et le message de limite sous le fichier', () => {
      const wrapper = mountForm(big())

      expect(wrapper.find('.file__size').text()).toBe('3,1 Go')
      expect(wrapper.find('.card__error').text()).toBe('La taille des fichiers est limitée à 1 Go')
    })

    it('désactive le bouton Téléverser et n\'envoie rien', async () => {
      const wrapper = mountForm(big())

      expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
      await submit(wrapper)

      expect(api).not.toHaveBeenCalled()
    })

    it('accepte un fichier d\'exactement 1 Go', () => {
      const wrapper = mountForm(makeFile({ size: 1_000_000_000 }))

      expect(wrapper.find('.card__error').exists()).toBe(false)
      expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
    })
  })

  describe('envoi', () => {
    it('envoie le fichier et la durée en multipart/form-data vers POST /api/files', async () => {
      api.mockResolvedValue({ url: 'https://datashare.fr/UhGyr' })
      const file = makeFile()
      const wrapper = mountForm(file)

      await submit(wrapper)

      expect(api).toHaveBeenCalledTimes(1)
      const [path, options] = api.mock.calls[0]
      expect(path).toBe('/files')
      expect(options.method).toBe('POST')
      expect(options.body).toBeInstanceOf(FormData)
      expect(options.body.get('file')).toBe(file)
      expect(options.body.get('expiration')).toBe('1') // nombre de jours
      expect(options.body.has('password')).toBe(false) // le mot de passe est optionnel
    })

    it('joint le mot de passe et la durée choisis', async () => {
      api.mockResolvedValue({ url: 'https://datashare.fr/UhGyr' })
      const wrapper = mountForm()
      await wrapper.find('input[type="password"]').setValue('secret123')
      await wrapper.find('select').setValue('7')

      await submit(wrapper)

      const form = api.mock.calls[0][1].body
      expect(form.get('password')).toBe('secret123')
      expect(form.get('expiration')).toBe('7')
    })

    it('désactive le bouton pendant l\'envoi, puis le réactive en cas d\'échec', async () => {
      let fail
      api.mockReturnValue(new Promise((_, reject) => (fail = reject)))
      const wrapper = mountForm()

      await wrapper.find('form').trigger('submit')
      expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()

      fail(new Error('Erreur 404'))
      await flushPromises()
      expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
    })

    it('affiche l\'erreur et reste sur le formulaire si l\'envoi échoue', async () => {
      api.mockRejectedValue(new Error('Le serveur est indisponible.'))
      const wrapper = mountForm()

      await submit(wrapper)

      expect(wrapper.find('.card__error').text()).toBe('Le serveur est indisponible.')
      expect(wrapper.find('form').exists()).toBe(true)
    })
  })

  describe('succès', () => {
    const uploaded = async (expiration = '1') => {
      api.mockResolvedValue({ url: 'https://datashare.fr/UhGyr' })
      const wrapper = mountForm()
      await wrapper.find('select').setValue(expiration)
      await submit(wrapper)
      return wrapper
    }

    it('remplace le formulaire par le message de félicitations et le lien', async () => {
      const wrapper = await uploaded()

      expect(wrapper.find('form').exists()).toBe(false)
      expect(wrapper.find('.card__success').text()).toBe(
        'Félicitations, ton fichier sera conservé chez nous pendant une journée !',
      )
      expect(wrapper.find('.share__link a').text()).toBe('https://datashare.fr/UhGyr')
      expect(wrapper.find('.share__link a').attributes('href')).toBe('https://datashare.fr/UhGyr')
    })

    it('garde le fichier affiché, sans le bouton "Changer"', async () => {
      const wrapper = await uploaded()

      expect(wrapper.find('.file__name').text()).toBe('photo.jpg')
      expect(wrapper.find('.file__size').text()).toBe('2,6 Mo')
      expect(wrapper.findAll('button').map((b) => b.text())).toEqual(['Copier le lien'])
    })

    it('élargit la carte uniquement après le succès', async () => {
      const wrapper = mountForm()
      expect(wrapper.find('.card').classes()).not.toContain('card--wide')

      api.mockResolvedValue({ url: 'https://datashare.fr/UhGyr' })
      await submit(wrapper)

      expect(wrapper.find('.card').classes()).toContain('card--wide')
    })

    it('affiche le lien dans l\'encadré, sous le message, puis le bouton sous le lien', async () => {
      const wrapper = await uploaded()

      const order = [...wrapper.find('.card').element.children].map((el) => el.className.split(' ')[0] || el.tagName)
      expect(order).toEqual(['card__title', 'file', 'share', 'btn'])
    })

    it.each([
      ['1', 'une journée'],
      ['3', '3 jours'],
      ['6', '6 jours'],
      ['7', 'une semaine'],
    ])('adapte le message à la durée choisie (%s jour(s) -> "%s")', async (days, label) => {
      const wrapper = await uploaded(days)

      expect(wrapper.find('.card__success').text()).toBe(
        `Félicitations, ton fichier sera conservé chez nous pendant ${label} !`,
      )
    })

    it('copie le lien dans le presse-papiers', async () => {
      const writeText = vi.fn().mockResolvedValue()
      vi.stubGlobal('navigator', { clipboard: { writeText } })
      const wrapper = await uploaded()

      const copier = wrapper.findAll('button').find((b) => b.text() === 'Copier le lien')
      await copier.trigger('click')
      await flushPromises()

      expect(writeText).toHaveBeenCalledWith('https://datashare.fr/UhGyr')
      expect(copier.text()).toBe('Lien copié !')
    })

    it('ne plante pas si le presse-papiers est indisponible', async () => {
      vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('refusé')) } })
      const wrapper = await uploaded()

      const copier = wrapper.findAll('button').find((b) => b.text() === 'Copier le lien')
      await copier.trigger('click')
      await flushPromises()

      expect(copier.text()).toBe('Copier le lien')
    })
  })
})
