// ============================================================================
// stores/files.js = le store Pinia des fichiers de l'utilisateur connecté.
// Il garde la liste reçue de l'API (GET /api/files) et sait supprimer un fichier
// (DELETE /api/files/{id}). La page "Mon espace" l'utilise.
// ============================================================================

import { defineStore } from 'pinia'
import { api } from '../api'

export const useFilesStore = defineStore('files', {
  state: () => ({
    items: [], // [{ id, name, size, type, url, protected, expired, createdAt, expiresAt }, ...]
    loading: false, // vrai pendant le chargement de la liste
    loaded: false, // vrai une fois la liste reçue au moins une fois
    error: '', // message d'erreur du dernier chargement
  }),

  actions: {
    // Charge (ou recharge) la liste de MES fichiers.
    async fetch() {
      this.loading = true
      this.error = ''
      try {
        this.items = await api('/files')
        this.loaded = true
      } catch (e) {
        this.error = e.message
      } finally {
        this.loading = false
      }
    },

    // Supprime un fichier côté serveur, puis le retire de la liste.
    // En cas d'erreur, l'exception remonte à l'appelant et la liste ne change pas.
    async remove(file) {
      await api(`/files/${file.id}`, { method: 'DELETE' })
      this.items = this.items.filter((f) => f.id !== file.id)
    },
  },
})
