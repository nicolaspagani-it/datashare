import vue from '@vitejs/plugin-vue'
// "vitest/config" fournit le même defineConfig que Vite qui comprend aussi la clé "test" ci-dessous.
import { defineConfig } from 'vitest/config'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  // Configuration des tests (npm test)
  test: {
    environment: 'jsdom', // un faux navigateur (DOM, localStorage...) pour exécuter les tests
    include: ['src/**/*.test.js'],
    restoreMocks: true, // les mocks/espions sont remis à zéro entre deux tests

    // Couverture de code : npm run test:coverage
    coverage: {
      provider: 'v8',
      include: ['src/**/*.{js,vue}'], // on mesure tout le code de l'appli...
      exclude: ['src/**/*.test.js', 'src/test-utils.js', 'src/main.js'], // ...sauf les tests et le point d'entrée
      reporter: ['text', 'html'], // tableau dans le terminal + rapport détaillé dans coverage/index.html
    },
  },
})
