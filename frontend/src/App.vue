<script setup>
import { onMounted, ref } from 'vue'

const status = ref('chargement…')

onMounted(async () => {
  try {
    const res = await fetch('/api/health')
    const data = await res.json()
    status.value = `${data.app} : ${data.status}`
  } catch {
    status.value = 'API injoignable'
  }
})
</script>

<template>
  <main>
    <h1>DataShare</h1>
    <p>API Symfony → {{ status }}</p>
  </main>
</template>
