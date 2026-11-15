// Petits outils partagés par les tests des composants.
import { createMemoryHistory, createRouter } from 'vue-router'

// Un routeur de test : mêmes noms de routes que l'appli, mais avec des pages vides
// (on ne teste pas ici le contenu des pages) et sans les gardes de navigation.
// createMemoryHistory = l'historique reste en mémoire, pas dans l'URL du navigateur.
export function makeRouter() {
  const Empty = { template: '<div />' }

  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: Empty },
      { path: '/login', name: 'login', component: Empty },
      { path: '/register', name: 'register', component: Empty },
      { path: '/mon-espace', name: 'my-space', component: Empty },
      { path: '/:token([A-Za-z0-9]{1,16})', name: 'download', component: Empty },
    ],
  })
}
