// ============================================================================
// router.js = le routing, l'équivalent de config/routes.yaml + security.yaml.
//
// Différence importante avec Symfony : ici le routage se fait DANS LE NAVIGATEUR.
// Quand on clique sur un lien, la page n'est pas rechargée : Vue change simplement
// le composant affiché et met à jour l'URL. Symfony n'est appelé que pour les
// données (/api/...), jamais pour afficher une page.
// ============================================================================

import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from './stores/auth'
import HomeView from './views/HomeView.vue'
import AuthView from './views/AuthView.vue'
import MySpaceView from './views/MySpaceView.vue'
import DownloadView from './views/DownloadView.vue'

const router = createRouter({
  // Utilise de "vraies" URLs (/login) plutôt que des URLs avec "#" (/#/login).
  history: createWebHistory(),

  // La liste des routes : URL -> composant à afficher (le "contrôleur + template").
  routes: [
    // L'accueil est PUBLIC : visible sans être connecté (l'en-tête propose "Se connecter").
    { path: '/', name: 'home', component: HomeView },

    // meta.requiresAuth est une étiquette perso : elle sert plus bas à protéger la page
    // (équivalent d'un access_control dans security.yaml).
    // "Mon espace" : la liste des fichiers de l'utilisateur
    { path: '/mon-espace', name: 'my-space', component: MySpaceView, meta: { requiresAuth: true } },

    // Les 2 routes utilisent le même composant AuthView. "props" lui passe un
    // paramètre (comme un argument de contrôleur) pour choisir connexion / inscription.
    { path: '/login', name: 'login', component: AuthView, props: { mode: 'login' } },
    { path: '/register', name: 'register', component: AuthView, props: { mode: 'register' } },

    // Le lien de partage d'un fichier : /<token> (8 lettres ou chiffres, ex : /UhGyr4Kd). Page PUBLIQUE.
    // Cette route est déclarée en DERNIER, et vue-router donne la priorité aux chemins fixes : /login,
    // /register et /mon-espace ne sont donc jamais pris pour un lien de partage.
    { path: '/:token([A-Za-z0-9]{1,16})', name: 'download', component: DownloadView },
  ],
})

// beforeEach = un "garde" exécuté AVANT chaque navigation, comme un EventListener
// sur kernel.request ou un voter. "to" est la route demandée.
//  - ne rien retourner = laisser passer
//  - retourner une route = rediriger vers elle
router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (to.meta.requiresAuth) {
    // Page protégée sans token -> on envoie sur la connexion.
    if (!auth.isAuthenticated) return { name: 'login' }

    // On a un token (ex : après un F5) mais pas encore l'utilisateur : on le charge.
    // Si l'API refuse (token expiré), on déconnecte et on renvoie sur /login.
    if (!auth.user) {
      try {
        await auth.fetchUser()
      } catch {
        auth.logout()
        return { name: 'login' }
      }
    }
  } else if (auth.isAuthenticated && ['login', 'register'].includes(to.name)) {
    // Déjà connecté : inutile d'afficher login/inscription, direction l'accueil.
    return { name: 'home' }
  }
})

export default router
