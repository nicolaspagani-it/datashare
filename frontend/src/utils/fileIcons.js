// Choix de l'icône d'un fichier selon son type MIME ("image/png", "audio/mpeg", "video/mp4"...).
// Les 3 icônes viennent du design Figma (src/assets/icons).
import iconImage from '../assets/icons/file-image.svg'
import iconAudio from '../assets/icons/file-audio.svg'
import iconVideo from '../assets/icons/file-video.svg'

// Le type MIME commence par la famille du fichier : "audio/..." , "video/..." , "image/...".
// Tous les autres types (PDF, texte, archive...) utilisent l'icône par défaut.
export function iconForMime(mimeType = '') {
  if (mimeType.startsWith('audio/')) return iconAudio
  if (mimeType.startsWith('video/')) return iconVideo
  return iconImage
}
