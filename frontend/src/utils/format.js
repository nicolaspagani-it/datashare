// Fonctions d'affichage des tailles de fichier, en français.

// Taille maximale d'un fichier : 1 Go (1 Go = 1 000 000 000 octets, comme dans le design).
export const MAX_FILE_SIZE = 1_000_000_000

const UNITS = [
  { unit: 'Go', size: 1_000_000_000 },
  { unit: 'Mo', size: 1_000_000 },
  { unit: 'Ko', size: 1_000 },
]

// 2_600_000 -> "2,6 Mo"   |   3_100_000_000 -> "3,1 Go"   |   850 -> "850 octets"
// Une décimale au maximum, avec la virgule française ("2,6" et pas "2.6").
export function formatSize(bytes) {
  for (const { unit, size } of UNITS) {
    if (bytes >= size) {
      const value = Math.round((bytes / size) * 10) / 10
      return `${String(value).replace('.', ',')} ${unit}`
    }
  }
  return `${bytes} ${bytes > 1 ? 'octets' : 'octet'}`
}
