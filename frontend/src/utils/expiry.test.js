import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { expiryLabel, expiryNotice, isExpired } from './expiry'

const DAY = 24 * 60 * 60 * 1000
const NOW = new Date('2026-01-10T12:00:00Z').getTime()

describe('expiry', () => {
  // On fige l'horloge : "maintenant" vaut toujours NOW, les tests ne dépendent pas de l'heure réelle.
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
  })
  afterEach(() => vi.useRealTimers())

  describe('isExpired', () => {
    it('est vrai pour une date passée', () => {
      expect(isExpired(NOW - DAY)).toBe(true)
    })

    it('est vrai pour la date exacte de maintenant', () => {
      expect(isExpired(NOW)).toBe(true)
    })

    it('est faux pour une date future', () => {
      expect(isExpired(NOW + 1000)).toBe(false)
    })

    it('accepte une chaîne ISO ou un objet Date', () => {
      expect(isExpired('2026-01-01T00:00:00Z')).toBe(true)
      expect(isExpired(new Date(NOW + DAY))).toBe(false)
    })
  })

  describe('expiryNotice (bandeau de la page de téléchargement)', () => {
    it('affiche un bandeau bleu "dans N jours" quand il reste plusieurs jours', () => {
      expect(expiryNotice(NOW + 3 * DAY)).toEqual({ variant: 'info', text: 'Ce fichier expirera dans 3 jours.' })
      expect(expiryNotice(NOW + 7 * DAY)).toEqual({ variant: 'info', text: 'Ce fichier expirera dans 7 jours.' })
    })

    it('compte un jour entamé comme un jour entier', () => {
      expect(expiryNotice(NOW + DAY + 1000)).toEqual({ variant: 'info', text: 'Ce fichier expirera dans 2 jours.' })
    })

    it('affiche un bandeau orange "demain" quand il reste un jour ou moins', () => {
      const demain = { variant: 'warning', text: 'Ce fichier expirera demain.' }

      expect(expiryNotice(NOW + DAY)).toEqual(demain)
      expect(expiryNotice(NOW + 3 * 60 * 60 * 1000)).toEqual(demain)
    })
  })

  describe('expiryLabel', () => {
    it('affiche "Expiré" pour une date passée', () => {
      expect(expiryLabel(NOW - DAY)).toBe('Expiré')
    })

    it('affiche "Expire demain" quand il reste moins de 24h', () => {
      expect(expiryLabel(NOW + 3 * 60 * 60 * 1000)).toBe('Expire demain')
    })

    it('affiche "Expire demain" quand il reste exactement 1 jour', () => {
      expect(expiryLabel(NOW + DAY)).toBe('Expire demain')
    })

    it('affiche "Expire dans N jours" au-delà', () => {
      expect(expiryLabel(NOW + 2 * DAY - 1000)).toBe('Expire dans 2 jours')
      expect(expiryLabel(NOW + 5 * DAY)).toBe('Expire dans 5 jours')
    })
  })
})
