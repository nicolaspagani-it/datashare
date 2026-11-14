import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useFilesStore } from './files'

// Faux appels réseau : on contrôle ce que répond l'API.
vi.mock('../api', async (importOriginal) => ({ ...(await importOriginal()), api: vi.fn() }))
import { api } from '../api'

const file = (id, name = `fichier-${id}.pdf`) => ({ id, name, type: 'application/pdf', expiresAt: '2030-01-01T00:00:00+00:00' })

describe('store files', () => {
  beforeEach(() => {
    api.mockReset()
    setActivePinia(createPinia())
  })

  it('démarre vide, sans chargement ni erreur', () => {
    const store = useFilesStore()

    expect(store.items).toEqual([])
    expect(store.loading).toBe(false)
    expect(store.loaded).toBe(false)
    expect(store.error).toBe('')
  })

  describe('fetch()', () => {
    it('charge la liste depuis GET /files', async () => {
      api.mockResolvedValue([file(1), file(2)])
      const store = useFilesStore()

      await store.fetch()

      expect(api).toHaveBeenCalledWith('/files')
      expect(store.items.map((f) => f.id)).toEqual([1, 2])
      expect(store.loaded).toBe(true)
      expect(store.error).toBe('')
    })

    it('indique le chargement en cours, puis le termine', async () => {
      let finish
      api.mockReturnValue(new Promise((resolve) => (finish = resolve)))
      const store = useFilesStore()

      const pending = store.fetch()
      expect(store.loading).toBe(true)

      finish([])
      await pending
      expect(store.loading).toBe(false)
    })

    it('garde le message d\'erreur et ne marque pas la liste comme chargée en cas d\'échec', async () => {
      api.mockRejectedValue(new Error('Le serveur est indisponible.'))
      const store = useFilesStore()

      await store.fetch() // ne lève pas d'exception : l'erreur est rangée dans le store

      expect(store.error).toBe('Le serveur est indisponible.')
      expect(store.loaded).toBe(false)
      expect(store.loading).toBe(false)
    })

    it('efface l\'ancienne erreur au nouvel essai', async () => {
      api.mockRejectedValueOnce(new Error('Boum')).mockResolvedValueOnce([file(1)])
      const store = useFilesStore()
      await store.fetch()

      await store.fetch()

      expect(store.error).toBe('')
      expect(store.items).toHaveLength(1)
    })

    it('remplace la liste précédente par la nouvelle', async () => {
      api.mockResolvedValueOnce([file(1), file(2)]).mockResolvedValueOnce([file(3)])
      const store = useFilesStore()

      await store.fetch()
      await store.fetch()

      expect(store.items.map((f) => f.id)).toEqual([3])
    })
  })

  describe('remove()', () => {
    it('appelle DELETE /files/{id} puis retire le fichier de la liste', async () => {
      api.mockResolvedValueOnce([file(1), file(2), file(3)]).mockResolvedValueOnce(null)
      const store = useFilesStore()
      await store.fetch()

      await store.remove(file(2))

      expect(api).toHaveBeenLastCalledWith('/files/2', { method: 'DELETE' })
      expect(store.items.map((f) => f.id)).toEqual([1, 3])
    })

    it('garde le fichier dans la liste si le serveur refuse, et laisse remonter l\'erreur', async () => {
      api.mockResolvedValueOnce([file(1)]).mockRejectedValueOnce(new Error('Fichier introuvable.'))
      const store = useFilesStore()
      await store.fetch()

      await expect(store.remove(file(1))).rejects.toThrow('Fichier introuvable.')

      expect(store.items.map((f) => f.id)).toEqual([1])
    })
  })

  it('$reset() vide tout (utilisé à la déconnexion)', async () => {
    api.mockResolvedValue([file(1)])
    const store = useFilesStore()
    await store.fetch()

    store.$reset()

    expect(store.items).toEqual([])
    expect(store.loaded).toBe(false)
  })
})
