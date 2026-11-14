import { describe, expect, it } from 'vitest'
import { iconForMime } from './fileIcons'
import iconImage from '../assets/icons/file-image.svg'
import iconAudio from '../assets/icons/file-audio.svg'
import iconVideo from '../assets/icons/file-video.svg'

describe('iconForMime', () => {
  it.each(['image/png', 'image/jpeg', 'image/svg+xml'])('utilise l\'icône image pour %s', (mime) => {
    expect(iconForMime(mime)).toBe(iconImage)
  })

  it.each(['audio/mpeg', 'audio/wav'])('utilise l\'icône audio pour %s', (mime) => {
    expect(iconForMime(mime)).toBe(iconAudio)
  })

  it.each(['video/mp4', 'video/webm'])('utilise l\'icône vidéo pour %s', (mime) => {
    expect(iconForMime(mime)).toBe(iconVideo)
  })

  it.each(['application/pdf', 'text/plain', 'application/octet-stream'])(
    'utilise l\'icône par défaut (image) pour %s',
    (mime) => {
      expect(iconForMime(mime)).toBe(iconImage)
    },
  )

  it('utilise l\'icône par défaut quand le type est absent', () => {
    expect(iconForMime()).toBe(iconImage)
    expect(iconForMime('')).toBe(iconImage)
  })

  it('les trois icônes sont différentes', () => {
    expect(new Set([iconImage, iconAudio, iconVideo]).size).toBe(3)
  })
})
