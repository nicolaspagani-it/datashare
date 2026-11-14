import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import App from './App.vue'

describe('App', () => {
  it('affiche la page correspondant à l\'URL courante (<RouterView />)', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: { template: '<p>page d\'accueil</p>' } },
        { path: '/autre', component: { template: '<p>autre page</p>' } },
      ],
    })
    router.push('/')
    await router.isReady()
    const wrapper = mount(App, { global: { plugins: [router] } })

    expect(wrapper.text()).toBe('page d\'accueil')

    await router.push('/autre')
    expect(wrapper.text()).toBe('autre page')
  })
})
