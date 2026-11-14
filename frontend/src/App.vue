<!--
  App.vue = le composant RACINE, comme le "base.html.twig" de Symfony.

  Un fichier .vue ("composant") regroupe en un seul fichier :
    <script>   la logique (le "contrôleur")
    <template> le HTML      (le "template Twig")
    <style>    le CSS

  Ici il n'y a pas de logique : juste un <RouterView />.
  <RouterView /> est un emplacement qui affiche la page correspondant à l'URL
  courante (HomeView pour "/", AuthView pour "/login", etc.).
  C'est l'équivalent de {% block body %}{% endblock %} dans Twig.
-->
<template>
  <RouterView />
</template>
