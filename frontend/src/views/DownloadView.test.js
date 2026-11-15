import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import DownloadView from './DownloadView.vue'
import { makeRouter } from '../test-utils'

// Faux appels réseau et faux téléchargement : on contrôle les réponses de l'API.
vi.mock('../api', async (importOriginal) => ({ ...(await importOriginal()), api: vi.fn() }))
vi.mock('../utils/download', () => ({ startDownload: vi.fn() }))
import { api } from '../api'
import { startDownload } from '../utils/download'

const DAY = 24 * 60 * 60 * 1000
const iso = (offsetMs) => new Date(Date.now() + offsetMs).toISOString()
const TOKEN = 'AAAA1111'
const DOWNLOAD_URL = '/api/shared/AAAA1111/download?ticket=1794742332.abc'

// Les informations d'un fichier, telles que les renvoie GET /api/shared/{token}.
const fileInfo = (overrides = {}) => ({
  token: TOKEN,
  name: 'IMG_9210_123123131313213231.jpg',
  size: 2_600_000,
  type: 'image/jpeg',
  protected: true,
  expiresAt: iso(3 * DAY - 1000), // dans 3 jours
  ...overrides,
})

// Fabrique l'erreur que lèverait api() : un message, un code HTTP et des violations éventuelles.
const apiError = (status, message, violations = []) => Object.assign(new Error(message), { status, violations })

// Monte la page sur le lien /AAAA1111. Par défaut l'API renvoie le fichier demandé.
async function mountPage({ info = fileInfo(), wait = true } = {}) {
  api.mockReset()
  startDownload.mockReset()
  api.mockImplementation(async (path) => {
    if (path === `/shared/${TOKEN}`) {
      if (info instanceof Error) throw info
      return info
    }
    if (path === `/shared/${TOKEN}/unlock`) return { downloadUrl: DOWNLOAD_URL }
  })

  const pinia = createPinia()
  setActivePinia(pinia)
  const router = makeRouter()
  router.push('/' + TOKEN)
  await router.isReady()

  const wrapper = mount(DownloadView, { global: { plugins: [pinia, router] } })
  if (wait) await flushPromises()
  return { wrapper, router }
}

const button = (wrapper) => wrapper.find('button[type="submit"]')
const passwordErrors = (wrapper) => wrapper.findAll('.field__error').map((e) => e.text())
const submit = async (wrapper) => {
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

describe('DownloadView', () => {
  beforeEach(() => localStorage.clear())

  describe('chargement', () => {
    it('demande les informations du lien SANS envoyer de token (page publique)', async () => {
      await mountPage()

      expect(api).toHaveBeenCalledWith(`/shared/${TOKEN}`, { auth: false })
    })

    it('affiche le titre et "Chargement…" en attendant la réponse', async () => {
      const { wrapper } = await mountPage({ wait: false })

      expect(wrapper.find('h1').text()).toBe('Télécharger un fichier')
      expect(wrapper.find('.card__loading').text()).toBe('Chargement…')
      expect(wrapper.find('form').exists()).toBe(false)
    })

    it('affiche l\'en-tête et le pied de page du site', async () => {
      const { wrapper } = await mountPage()

      expect(wrapper.find('.site-header').exists()).toBe(true)
      expect(wrapper.find('.public__footer').text()).toContain('Copyright DataShare')
    })
  })

  describe('fichier protégé par un mot de passe (Desktop 9 et 10)', () => {
    it('affiche le fichier : nom, taille et icône', async () => {
      const { wrapper } = await mountPage()

      expect(wrapper.find('.file__name').text()).toBe('IMG_9210_123123131313213231.jpg')
      expect(wrapper.find('.file__size').text()).toBe('2,6 Mo')
      expect(wrapper.find('img.file__icon').exists()).toBe(true)
    })

    it('affiche un bandeau bleu avec l\'expiration', async () => {
      const { wrapper } = await mountPage()

      const callout = wrapper.find('.callout')
      expect(callout.classes()).toContain('callout--info')
      expect(callout.text()).toBe('Ce fichier expirera dans 3 jours.')
    })

    it('demande le mot de passe', async () => {
      const { wrapper } = await mountPage()

      const input = wrapper.find('input')
      expect(wrapper.find('label').text()).toBe('Mot de passe')
      expect(input.attributes('type')).toBe('password')
      expect(input.attributes('placeholder')).toBe('Saisissez le mot de passe...')
    })

    it('grise le bouton "Télécharger" tant que le mot de passe est vide', async () => {
      const { wrapper } = await mountPage()

      expect(button(wrapper).text()).toContain('Télécharger')
      expect(button(wrapper).attributes('disabled')).toBeDefined()
    })

    it('active le bouton dès qu\'un mot de passe est saisi', async () => {
      const { wrapper } = await mountPage()

      await wrapper.find('input').setValue('mon-secret')

      expect(button(wrapper).attributes('disabled')).toBeUndefined()
    })

    it('ne fait rien si on valide le formulaire sans mot de passe', async () => {
      const { wrapper } = await mountPage()

      await submit(wrapper)

      expect(api).not.toHaveBeenCalledWith(`/shared/${TOKEN}/unlock`, expect.anything())
      expect(startDownload).not.toHaveBeenCalled()
    })

    it('envoie le mot de passe au serveur puis lance le téléchargement avec l\'URL reçue', async () => {
      const { wrapper } = await mountPage()
      await wrapper.find('input').setValue('mon-secret')

      await submit(wrapper)

      expect(api).toHaveBeenCalledWith(`/shared/${TOKEN}/unlock`, {
        method: 'POST',
        body: { password: 'mon-secret' },
        auth: false,
      })
      expect(startDownload).toHaveBeenCalledWith(DOWNLOAD_URL)
    })

    it('affiche "mot de passe incorrect" SOUS le champ, sans lancer de téléchargement', async () => {
      const { wrapper } = await mountPage()
      api.mockImplementation(async (path) => {
        if (path.endsWith('/unlock')) {
          throw apiError(403, 'Mot de passe incorrect.', [{ propertyPath: 'password', title: 'Mot de passe incorrect.' }])
        }
      })
      await wrapper.find('input').setValue('faux')

      await submit(wrapper)

      expect(passwordErrors(wrapper)).toEqual(['Mot de passe incorrect.'])
      expect(wrapper.find('input').attributes('aria-invalid')).toBe('true')
      expect(startDownload).not.toHaveBeenCalled()
    })

    it('affiche le message "trop de tentatives" sous le champ', async () => {
      const { wrapper } = await mountPage()
      api.mockImplementation(async (path) => {
        if (path.endsWith('/unlock')) {
          throw apiError(429, 'Trop de tentatives. Réessaie dans 15 minutes.', [
            { propertyPath: 'password', title: 'Trop de tentatives. Réessaie dans 15 minutes.' },
          ])
        }
      })
      await wrapper.find('input').setValue('essai')

      await submit(wrapper)

      expect(passwordErrors(wrapper)).toEqual(['Trop de tentatives. Réessaie dans 15 minutes.'])
    })

    it('efface l\'erreur dès qu\'on retape le mot de passe', async () => {
      const { wrapper } = await mountPage()
      api.mockRejectedValueOnce(apiError(403, 'x', [{ propertyPath: 'password', title: 'Mot de passe incorrect.' }]))
      await wrapper.find('input').setValue('faux')
      await submit(wrapper)
      expect(passwordErrors(wrapper)).toHaveLength(1)

      await wrapper.find('input').setValue('autre')

      expect(passwordErrors(wrapper)).toEqual([])
    })

    it('désactive le bouton pendant la vérification', async () => {
      const { wrapper } = await mountPage()
      let finish
      api.mockReturnValue(new Promise((resolve) => (finish = resolve)))
      await wrapper.find('input').setValue('mon-secret')

      await wrapper.find('form').trigger('submit')
      expect(button(wrapper).attributes('disabled')).toBeDefined()

      finish({ downloadUrl: DOWNLOAD_URL })
      await flushPromises()
      expect(button(wrapper).attributes('disabled')).toBeUndefined()
    })

    it('affiche une erreur générale si le serveur ne répond pas', async () => {
      const { wrapper } = await mountPage()
      api.mockRejectedValue(apiError(502, 'Erreur 502'))
      await wrapper.find('input').setValue('mon-secret')

      await submit(wrapper)

      expect(wrapper.find('.callout--error').text()).toBe('Erreur 502')
      expect(passwordErrors(wrapper)).toEqual([])
    })
  })

  describe('fichier sans mot de passe (Desktop 11)', () => {
    const open = (extra = {}) => mountPage({ info: fileInfo({ protected: false, expiresAt: iso(DAY - 1000), ...extra }) })

    it('n\'affiche pas de champ mot de passe', async () => {
      const { wrapper } = await open()

      expect(wrapper.find('input').exists()).toBe(false)
    })

    it('affiche un bandeau orange "expirera demain"', async () => {
      const { wrapper } = await open()

      const callout = wrapper.find('.callout')
      expect(callout.classes()).toContain('callout--warning')
      expect(callout.text()).toBe('Ce fichier expirera demain.')
    })

    it('le bouton "Télécharger" est actif tout de suite', async () => {
      const { wrapper } = await open()

      expect(button(wrapper).attributes('disabled')).toBeUndefined()
    })

    it('télécharge directement, avec un mot de passe vide', async () => {
      const { wrapper } = await open()

      await submit(wrapper)

      expect(api).toHaveBeenCalledWith(`/shared/${TOKEN}/unlock`, { method: 'POST', body: { password: '' }, auth: false })
      expect(startDownload).toHaveBeenCalledWith(DOWNLOAD_URL)
    })
  })

  describe('fichier expiré (Desktop 12)', () => {
    const EXPIRED = "Ce fichier n'est plus disponible en téléchargement car il a expiré."

    it('affiche le bandeau rouge quand le serveur répond 410', async () => {
      const { wrapper } = await mountPage({ info: apiError(410, 'Ce fichier a expiré...') })

      expect(wrapper.find('.callout--error').text()).toBe(EXPIRED)
    })

    it('n\'affiche ni fichier, ni champ, ni bouton', async () => {
      const { wrapper } = await mountPage({ info: apiError(410, 'x') })

      expect(wrapper.find('h1').text()).toBe('Télécharger un fichier')
      expect(wrapper.find('form').exists()).toBe(false)
      expect(wrapper.find('.file').exists()).toBe(false)
      expect(wrapper.find('button[type="submit"]').exists()).toBe(false)
    })

    it('détecte aussi l\'expiration d\'après la date, même si le serveur répond 200', async () => {
      const { wrapper } = await mountPage({ info: fileInfo({ expiresAt: iso(-1000) }) })

      expect(wrapper.find('.callout--error').text()).toBe(EXPIRED)
      expect(wrapper.find('form').exists()).toBe(false)
    })

    it('bascule sur "expiré" si le fichier expire pendant que la page est ouverte', async () => {
      const { wrapper } = await mountPage()
      api.mockRejectedValue(apiError(410, 'Ce fichier a expiré...'))
      await wrapper.find('input').setValue('mon-secret')

      await submit(wrapper)

      expect(wrapper.find('.callout--error').text()).toBe(EXPIRED)
      expect(wrapper.find('form').exists()).toBe(false)
      expect(startDownload).not.toHaveBeenCalled()
    })
  })

  describe('lien inconnu ou erreur de chargement', () => {
    it('affiche le message du serveur dans un bandeau rouge pour un lien inconnu (404)', async () => {
      const { wrapper } = await mountPage({ info: apiError(404, "Ce lien n'existe pas.") })

      expect(wrapper.find('.callout--error').text()).toBe("Ce lien n'existe pas.")
      expect(wrapper.find('form').exists()).toBe(false)
    })

    it('affiche l\'erreur quand le serveur est injoignable', async () => {
      const { wrapper } = await mountPage({ info: apiError(0, 'Failed to fetch') })

      expect(wrapper.find('.callout--error').text()).toBe('Failed to fetch')
    })
  })

  describe('changement de lien', () => {
    it('recharge les informations quand on ouvre un autre lien', async () => {
      const { wrapper, router } = await mountPage()
      await wrapper.find('input').setValue('mon-secret')

      await router.push('/BBBB2222')
      await flushPromises()

      expect(api).toHaveBeenCalledWith('/shared/BBBB2222', { auth: false })
      expect(wrapper.find('input').exists() ? wrapper.find('input').element.value : '').toBe('') // le mot de passe saisi est effacé
    })
  })
})
