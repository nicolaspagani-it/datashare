<!--
  PublicLayout.vue = la "coquille" des pages publiques : fond dégradé, en-tête, pied de page.
  Le contenu propre à chaque page est injecté dans le <slot /> (comme {% block body %}).

  Utilisé par : l'accueil (HomeView) et les pages connexion / inscription (AuthView).
-->
<script setup>
import SiteHeader from './SiteHeader.vue'
</script>

<template>
  <div class="public">
    <SiteHeader />

    <main class="public__main">
      <slot />
    </main>

    <footer class="public__footer">Copyright DataShare<small>©</small> 2025</footer>
  </div>
</template>

<style scoped>
.public {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: linear-gradient(175deg, #ffb88c 2.29%, #de6262 97.7%);
}

.public__main {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 24px;
  padding: 0 24px;
}

.public__footer {
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
  padding: 16px;
  color: #fff;
  font-family: var(--alt-font);
  line-height: 24px;
}

.public__footer small {
  font-size: 10.32px;
}
</style>
