// Petites fonctions utilitaires (comme un service Symfony sans état).
// Elles calculent l'état d'expiration d'un fichier à partir de sa date d'expiration.

const DAY = 24 * 60 * 60 * 1000

// Vrai si la date d'expiration est dans le passé.
export const isExpired = (expiresAt) => new Date(expiresAt).getTime() <= Date.now()

// Texte affiché sous le nom du fichier : "Expire dans 2 jours", "Expire demain", "Expiré".
export function expiryLabel(expiresAt) {
  if (isExpired(expiresAt)) return 'Expiré'

  const days = Math.ceil((new Date(expiresAt).getTime() - Date.now()) / DAY)
  if (days <= 1) return 'Expire demain'
  return `Expire dans ${days} jours`
}
