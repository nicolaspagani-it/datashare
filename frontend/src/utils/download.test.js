import { describe, expect, it, vi } from 'vitest'
import { startDownload } from './download'

describe('startDownload', () => {
  it('"clique" sur un lien vers l\'URL, avec l\'attribut download', () => {
    const clicks = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
      clicks.push({ href: this.getAttribute('href'), download: this.hasAttribute('download'), attached: this.isConnected })
    })

    startDownload('/api/shared/AAAA1111/download?ticket=1.abc')

    expect(clicks).toEqual([{ href: '/api/shared/AAAA1111/download?ticket=1.abc', download: true, attached: true }])
  })

  it('ne laisse aucun lien dans la page après le téléchargement', () => {
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const before = document.querySelectorAll('a').length

    startDownload('/api/shared/AAAA1111/download')

    expect(document.querySelectorAll('a')).toHaveLength(before)
  })
})
