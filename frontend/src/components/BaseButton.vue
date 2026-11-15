<!--
  BaseButton.vue = un bouton réutilisable (le "Button Component" du design Figma).

  On l'utilise partout au lieu de réécrire le même <button> : si on change le style
  ici, tous les boutons changent (comme un thème de formulaire Symfony).

  Props (= les paramètres du composant) :
    variant : 'dark' (fond noir) | 'outline' (contour orange) | 'ghost' (sans contour)
    size    : 'md' (8px 12px) | 'lg' (12px) | 'icon' (carré 32px, icône seule)
    to      : si fourni, le composant devient un lien de navigation (<RouterLink>)
    type    : 'button' (défaut) ou 'submit' pour valider un formulaire
    block   : le bouton prend toute la largeur disponible
    (variant 'soft' : fond pêche + contour orange, utilisé pour valider un formulaire)

  <slot /> = l'emplacement où s'affiche le contenu placé entre les balises
  <BaseButton>...</BaseButton> (comme un {% block %} Twig).

  <component :is="..."> choisit dynamiquement la balise : un lien ou un bouton.
  (Ce commentaire est ici et pas dans le <template> : un commentaire à la racine du
  template compte comme un 2e élément racine et gêne les tests.)
-->
<script setup>
import { RouterLink } from 'vue-router'

defineProps({
  variant: { type: String, default: 'outline' },
  size: { type: String, default: 'md' },
  to: { type: [String, Object], default: null },
  type: { type: String, default: 'button' },
  block: { type: Boolean, default: false },
})
</script>

<template>
  <component
    :is="to ? RouterLink : 'button'"
    :to="to"
    :type="to ? undefined : type"
    class="btn"
    :class="[`btn--${variant}`, `btn--${size}`, { 'btn--block': block }]"
  >
    <slot />
  </component>
</template>

<style scoped>
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 8px 12px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  font-family: var(--main-font);
  font-size: 16px;
  line-height: 16px;
  white-space: nowrap;
  text-decoration: none;
  cursor: pointer;
  overflow: hidden;
}

.btn:hover {
  filter: brightness(0.95);
}

.btn--dark {
  background: var(--btn-dark);
  color: var(--btn-dark-text);
}

/* Contour dessiné avec une ombre intérieure : il ne prend pas de place,
   donc le bouton fait exactement 32px de haut comme dans Figma. */
.btn--outline {
  box-shadow: inset 0 0 0 1px var(--accent-border);
  color: var(--accent);
}

.btn--ghost {
  color: var(--accent);
}

.btn--soft {
  background: var(--bg-header);
  box-shadow: inset 0 0 0 1px var(--accent-border);
  color: var(--accent);
}

.btn--block {
  display: flex;
  width: 100%;
}

/* Désactivé : gris, comme dans le design (valable pour toutes les variantes) */
.btn:disabled {
  background: var(--disabled-bg);
  box-shadow: inset 0 0 0 1px var(--disabled-border);
  color: var(--disabled-text);
  cursor: not-allowed;
  filter: none;
}

.btn--lg {
  padding: 12px;
}

.btn--icon {
  padding: 8px;
}
</style>
