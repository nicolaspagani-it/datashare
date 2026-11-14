// ============================================================================
// stores/auth.js = un "store" Pinia : un SERVICE partagé par toute l'application.
//
// Le problème qu'il résout : plusieurs pages ont besoin de savoir "qui est connecté ?".
// Au lieu de se passer l'information de page en page, on la met dans un store
// unique, accessible partout. C'est exactement le rôle d'un service Symfony,
// injecté là où on en a besoin (ici via useAuthStore()).
// ============================================================================

import { defineStore } from 'pinia'
import { api, getToken, setToken } from '../api'
import { useFilesStore } from './files'

// defineStore('auth', ...) déclare le service. Il est créé une seule fois (singleton).
export const useAuthStore = defineStore('auth', {
  // state = les DONNÉES (les propriétés du service).
  // Quand une de ces valeurs change, tous les composants qui l'affichent se
  // mettent à jour tout seuls : c'est la "réactivité", le cœur de Vue.
  state: () => ({
    user: null, // l'utilisateur courant ({ id, email, roles }) ou null
    token: getToken(), // le JWT, relu au chargement : on reste connecté après F5
  }),

  // getters = des valeurs CALCULÉES à partir du state (comme des getters d'entité).
  // "!!x" convertit en booléen : vrai si on a un token, faux sinon.
  getters: { isAuthenticated: (s) => !!s.token },

  // actions = les MÉTHODES du service ("this" désigne le store).
  actions: {
    // Appelle POST /api/login (géré par le firewall "login" de Symfony),
    // qui renvoie { token: "..." }. On le range puis on charge l'utilisateur.
    async login(email, password) {
      const { token } = await api('/login', { method: 'POST', body: { email, password } })
      this.token = token
      setToken(token) // sauvegarde dans le localStorage
      await this.fetchUser()
    },

    // Crée le compte (POST /api/register) puis connecte directement l'utilisateur.
    async register(email, password) {
      await api('/register', { method: 'POST', body: { email, password } })
      await this.login(email, password)
    },

    // Appelle GET /api/me pour savoir qui on est (nécessite le token).
    async fetchUser() {
      this.user = await api('/me')
    },

    // JWT est "stateless" : il n'y a rien à invalider côté serveur.
    // Se déconnecter = simplement oublier le token.
    logout() {
      this.token = null
      this.user = null
      setToken(null)
      // On vide aussi la liste des fichiers : un autre utilisateur qui se connecte
      // ensuite ne doit jamais voir, même un instant, les fichiers du précédent.
      useFilesStore().$reset()
    },
  },
})
