// Petites fonctions utilitaires (comme un service Symfony sans état).
// Elles calculent l'état d'expiration d'un fichier à partir de sa date d'expiration.

const DAY = 24 * 60 * 60 * 1000

// Vrai si la date d'expiration est dans le passé.
export const isExpired = (expiresAt) => new Date(expiresAt).getTime() <= Date.now()

// Bandeau d'information de la page de téléchargement (voir AppCallout) :
//   plus d'un jour restant -> bandeau bleu  "Ce fichier expirera dans 3 jours."
//   un jour ou moins       -> bandeau orange "Ce fichier expirera demain."
export function expiryNotice(expiresAt) {
  const days = Math.ceil((new Date(expiresAt).getTime() - Date.now()) / DAY)
  if (days <= 1) return { variant: 'warning', text: 'Ce fichier expirera demain.' }
  return { variant: 'info', text: `Ce fichier expirera dans ${days} jours.` }
}

// Texte affiché sous le nom du fichier : "Expire dans 2 jours", "Expire demain", "Expiré".
export function expiryLabel(expiresAt) {
  if (isExpired(expiresAt)) return 'Expiré'

  const days = Math.ceil((new Date(expiresAt).getTime() - Date.now()) / DAY)
  if (days <= 1) return 'Expire demain'
  return `Expire dans ${days} jours`
}
