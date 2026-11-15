// ============================================================================
// api.js = un petit "client HTTP" (comme le HttpClient de Symfony).
// Tous les appels vers le backend passent par la fonction api() ci-dessous :
// elle ajoute le token JWT, lit le JSON et gère les erreurs au même endroit.
// ============================================================================

// Le token JWT est gardé dans le "localStorage" du navigateur : un petit stockage
// clé/valeur qui survit au rechargement de la page (un peu comme la session,
// mais côté navigateur). La clé sous laquelle on le range :
const TOKEN_KEY = 'datashare_token'

// Fonctions fléchées : "(x) => ..." est une façon courte d'écrire une fonction.
// getToken() lit le token, setToken(t) l'enregistre (ou le supprime si t est vide).
export const getToken = () => localStorage.getItem(TOKEN_KEY)
export const setToken = (t) => (t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY))

// Fonction à appeler quand l'API répond 401. Elle est définie dans main.js
// (ici on ne connaît pas le routeur, on évite donc de tout mélanger).
let onUnauthorized = () => {}
export const setUnauthorizedHandler = (fn) => (onUnauthorized = fn)

// api('/me')                                   -> GET /api/me
// api('/login', { method: 'POST', body: {...} }) -> POST /api/login avec du JSON
//
// "async" : la fonction fait des appels réseau, qui sont ASYNCHRONES (le navigateur
// n'attend pas la réponse en bloquant tout). "await" signifie "attends le résultat
// ici avant de continuer", ce qui se lit comme du code PHP normal.
//
// Option "auth: false" : pour les routes PUBLIQUES (ex : le téléchargement par lien). On n'envoie
// alors pas le token, et un 401 ne déconnecte pas : un visiteur qui garde un vieux token expiré
// en mémoire ne doit pas être renvoyé vers /login en ouvrant un lien de partage.
export async function api(path, { method = 'GET', body, auth = true } = {}) {
  const headers = { Accept: 'application/json' }

  // Un FormData (envoi de fichier) est transmis tel quel : c'est le navigateur qui
  // fixe lui-même le Content-Type "multipart/form-data" (avec sa "boundary").
  // Tout autre body est envoyé en JSON.
  const isFormData = body instanceof FormData
  if (body && !isFormData) headers['Content-Type'] = 'application/json'

  // Si on est connecté, on envoie le token à chaque requête.
  // C'est ce que le firewall "api" de Symfony (jwt: ~) attend dans cet en-tête.
  const token = auth ? getToken() : null
  if (token) headers.Authorization = `Bearer ${token}`

  // fetch() est la fonction native du navigateur pour appeler une URL.
  // "/api/..." passe par le proxy de Vite (vite.config.js) qui le renvoie vers Symfony.
  // JSON.stringify transforme un objet JS en texte JSON (= json_encode en PHP).
  const payload = body ? (isFormData ? body : JSON.stringify(body)) : undefined
  const res = await fetch(`/api${path}`, { method, headers, body: payload })

  // res.json() lit le corps de la réponse (= json_decode). Si ce n'est pas du JSON,
  // ".catch(() => null)" évite de planter et renvoie null.
  const data = await res.json().catch(() => null)

  // res.ok vaut true pour les codes 200-299. Sinon on lève une erreur
  // (= "throw new Exception" en PHP), que l'appelant attrapera avec try/catch.
  if (!res.ok) {
    // 401 sur une route protégée = token expiré/invalide -> retour à la connexion.
    // Mais pas pour /login : là, un 401 veut juste dire "mauvais mot de passe".
    if (res.status === 401 && path !== '/login' && auth) onUnauthorized()

    // On cherche un message lisible dans la réponse de Symfony :
    // "message" (nos erreurs), "detail" (erreurs standard), ou la liste "violations"
    // renvoyée par le validateur (422). "||" prend la première valeur non vide.
    // Les "violations" (422) passent en premier : leur "title" est le message propre,
    // alors que "detail" est préfixé par le nom du champ ("password: ...").
    const message =
      (data?.violations ? data.violations.map((v) => v.title).join(' ') : null) ||
      data?.message ||
      data?.detail ||
      `Erreur ${res.status}`

    // On joint à l'erreur la liste détaillée des violations : [{ propertyPath: 'email', title: '...' }].
    // Un formulaire peut ainsi afficher chaque message sous le champ concerné
    // (propertyPath = le nom du champ), au lieu d'un seul message global.
    const error = new Error(message)
    error.status = res.status
    error.violations = Array.isArray(data?.violations) ? data.violations : []
    throw error
  }
  return data
}
