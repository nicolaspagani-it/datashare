import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, getToken, setToken, setUnauthorizedHandler } from './api'

// Fabrique une fausse réponse HTTP, comme celle que renverrait fetch().
function response(status, body) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: body === undefined ? () => Promise.reject(new Error('pas du JSON')) : () => Promise.resolve(body),
  }
}

describe('stockage du token', () => {
  beforeEach(() => localStorage.clear())

  it('est vide au départ', () => {
    expect(getToken()).toBeNull()
  })

  it('enregistre et relit le token', () => {
    setToken('abc')
    expect(getToken()).toBe('abc')
  })

  it('supprime le token quand on passe une valeur vide', () => {
    setToken('abc')
    setToken(null)
    expect(getToken()).toBeNull()
  })
})

describe('api()', () => {
  let fetchMock
  const onUnauthorized = vi.fn()

  beforeEach(() => {
    localStorage.clear()
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    setUnauthorizedHandler(onUnauthorized)
    onUnauthorized.mockClear()
  })

  it('envoie un GET sur /api + chemin, en demandant du JSON', async () => {
    fetchMock.mockResolvedValue(response(200, { ok: true }))

    const data = await api('/health')

    expect(data).toEqual({ ok: true })
    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/health')
    expect(options.method).toBe('GET')
    expect(options.headers.Accept).toBe('application/json')
    expect(options.body).toBeUndefined()
  })

  it('n\'envoie pas d\'en-tête Authorization sans token', async () => {
    fetchMock.mockResolvedValue(response(200, {}))

    await api('/health')

    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBeUndefined()
  })

  it('envoie le token JWT dans l\'en-tête Authorization quand on en a un', async () => {
    setToken('mon-jwt')
    fetchMock.mockResolvedValue(response(200, {}))

    await api('/me')

    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer mon-jwt')
  })

  it('envoie le body en JSON avec le bon Content-Type', async () => {
    fetchMock.mockResolvedValue(response(201, { id: 1 }))

    await api('/register', { method: 'POST', body: { email: 'a@b.fr', password: 'secret123' } })

    const options = fetchMock.mock.calls[0][1]
    expect(options.method).toBe('POST')
    expect(options.headers['Content-Type']).toBe('application/json')
    expect(JSON.parse(options.body)).toEqual({ email: 'a@b.fr', password: 'secret123' })
  })

  it('envoie un FormData tel quel, sans Content-Type JSON (le navigateur fixe le multipart)', async () => {
    fetchMock.mockResolvedValue(response(201, { url: 'https://datashare.fr/abc' }))
    const form = new FormData()
    form.append('file', new File(['x'], 'photo.jpg'))

    await api('/files', { method: 'POST', body: form })

    const options = fetchMock.mock.calls[0][1]
    expect(options.body).toBe(form)
    expect(options.headers['Content-Type']).toBeUndefined()
  })

  describe('gestion des erreurs', () => {
    it('lève une erreur avec le titre des violations (422), sans le préfixe du champ', async () => {
      fetchMock.mockResolvedValue(
        response(422, {
          detail: 'password: Cette chaîne est trop courte.',
          violations: [{ title: 'Cette chaîne est trop courte.' }, { title: 'Autre erreur.' }],
        }),
      )

      await expect(api('/register', { method: 'POST', body: {} })).rejects.toThrow(
        'Cette chaîne est trop courte. Autre erreur.',
      )
    })

    it('joint à l\'erreur la liste des violations (champ + message) et le code HTTP', async () => {
      const violations = [
        { propertyPath: 'email', title: 'Email KO' },
        { propertyPath: 'password', title: 'Mot de passe KO' },
      ]
      fetchMock.mockResolvedValue(response(422, { violations }))

      const error = await api('/register', { method: 'POST', body: {} }).catch((e) => e)

      expect(error.violations).toEqual(violations)
      expect(error.status).toBe(422)
    })

    it('a une liste de violations vide quand le serveur n\'en renvoie pas', async () => {
      fetchMock.mockResolvedValue(response(500, { detail: 'Boum' }))

      const error = await api('/x').catch((e) => e)

      expect(error.violations).toEqual([])
      expect(error.status).toBe(500)
    })

    it('utilise "message" quand il n\'y a pas de violations (ex : 409)', async () => {
      fetchMock.mockResolvedValue(response(409, { message: 'Cet email est déjà utilisé.' }))

      await expect(api('/register', { method: 'POST', body: {} })).rejects.toThrow('Cet email est déjà utilisé.')
    })

    it('utilise "detail" à défaut', async () => {
      fetchMock.mockResolvedValue(response(500, { detail: 'Boum' }))

      await expect(api('/x')).rejects.toThrow('Boum')
    })

    it('retombe sur "Erreur <code>" si la réponse n\'est pas du JSON', async () => {
      fetchMock.mockResolvedValue(response(502, undefined))

      await expect(api('/x')).rejects.toThrow('Erreur 502')
    })
  })

  describe('erreur 401', () => {
    it('appelle le gestionnaire de déconnexion sur une route protégée', async () => {
      fetchMock.mockResolvedValue(response(401, { message: 'Expired JWT Token' }))

      await expect(api('/me')).rejects.toThrow()

      expect(onUnauthorized).toHaveBeenCalledTimes(1)
    })

    it('ne déconnecte PAS sur /login : c\'est juste un mauvais mot de passe', async () => {
      fetchMock.mockResolvedValue(response(401, { message: 'Identifiants invalides.' }))

      await expect(api('/login', { method: 'POST', body: {} })).rejects.toThrow('Identifiants invalides.')

      expect(onUnauthorized).not.toHaveBeenCalled()
    })
  })
})
