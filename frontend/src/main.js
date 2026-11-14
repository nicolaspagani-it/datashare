// ============================================================================
// main.js = le "public/index.php" + le "Kernel" de Symfony.
// C'est le POINT D'ENTRÉE : le premier fichier JavaScript exécuté par le navigateur.
// (index.html contient un <div id="app"></div> et charge ce fichier.)
// ============================================================================

// "import" = l'équivalent de "use" en PHP : on récupère du code d'un autre fichier/paquet.
import { createApp } from 'vue'
import { createPinia } from 'pinia' // Pinia = gestion de l'état global (voir stores/auth.js)
// Polices du design Figma (installées en local avec npm, aucun appel à un CDN)
import '@fontsource/dm-sans/300.css'
import '@fontsource/dm-sans/400.css'
import '@fontsource/dm-sans/600.css'
import '@fontsource/dm-sans/700.css'
import '@fontsource/inter/400.css'
import './style.css'
import App from './App.vue' // le composant racine : le "base.html.twig" de l'appli
import router from './router' // le routeur (voir router.js)
import { setUnauthorizedHandler } from './api'
import { useAuthStore } from './stores/auth'

// On crée l'application Vue à partir du composant racine (App.vue).
const app = createApp(App)

// "app.use()" branche des plugins, un peu comme enregistrer des bundles dans Symfony.
app.use(createPinia()) // active les stores : ils deviennent utilisables partout
app.use(router) // active les routes : <RouterView> et <RouterLink> fonctionnent

// Quand l'API répond 401 sur une route protégée (token expiré ou invalide),
// api.js appelle cette fonction : on vide la session et on renvoie vers /login.
// (Équivalent d'un EventListener sur l'exception d'accès refusé en Symfony.)
setUnauthorizedHandler(() => {
  useAuthStore().logout()
  router.push({ name: 'login' }) // = redirectToRoute('login')
})

// On "monte" l'appli : Vue prend le contrôle de <div id="app"> dans index.html
// et y affiche tout le contenu. À partir de là, la page n'est plus rechargée :
// c'est Vue qui change l'affichage (c'est ça, une SPA - Single Page Application).
app.mount('#app')
