import { describe, expect, it } from 'vitest'
import { MAX_FILE_SIZE, formatSize } from './format'

describe('formatSize', () => {
  it('affiche les mégaoctets avec une virgule française (exemple du design : 2,6 Mo)', () => {
    expect(formatSize(2_600_000)).toBe('2,6 Mo')
  })

  it('affiche les gigaoctets (exemple du design : 3,1 Go)', () => {
    expect(formatSize(3_100_000_000)).toBe('3,1 Go')
  })

  it('n\'affiche pas de décimale inutile', () => {
    expect(formatSize(1_000_000_000)).toBe('1 Go')
    expect(formatSize(5_000_000)).toBe('5 Mo')
  })

  it('arrondit à une décimale', () => {
    expect(formatSize(2_649_999)).toBe('2,6 Mo')
    expect(formatSize(2_650_000)).toBe('2,7 Mo')
  })

  it('utilise Ko pour les petits fichiers', () => {
    expect(formatSize(1_000)).toBe('1 Ko')
    expect(formatSize(850_000)).toBe('850 Ko')
  })

  it('utilise octets en dessous de 1 Ko, avec le bon accord', () => {
    expect(formatSize(0)).toBe('0 octet')
    expect(formatSize(1)).toBe('1 octet')
    expect(formatSize(999)).toBe('999 octets')
  })

  it('la limite de taille est de 1 Go', () => {
    expect(MAX_FILE_SIZE).toBe(1_000_000_000)
  })
})
