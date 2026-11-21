// Test de charge minimal, sans dépendance (Node 18+) : envoie N requêtes GET avec C requêtes en parallèle
// et affiche le débit et les temps de réponse (médiane, p95, p99, maximum).
//
//   node scripts/perf/load-test.mjs <url> [requêtes=500] [parallèles=10]
//
// Exemple : node scripts/perf/load-test.mjs http://127.0.0.1:8001/api/health 500 10
// Voir PERF.md pour la méthode et les résultats.

const [url, total = '500', parallel = '10'] = process.argv.slice(2)
if (!url) {
  console.error('Usage : node scripts/perf/load-test.mjs <url> [requêtes] [parallèles]')
  process.exit(1)
}

const N = Number(total)
const C = Number(parallel)
const durations = []
const statuses = new Map()
let next = 0

async function worker() {
  while (next < N) {
    next++
    const start = performance.now()
    try {
      const res = await fetch(url)
      await res.arrayBuffer() // on lit tout le corps : le temps mesuré est complet
      statuses.set(res.status, (statuses.get(res.status) ?? 0) + 1)
    } catch {
      statuses.set('erreur réseau', (statuses.get('erreur réseau') ?? 0) + 1)
    }
    durations.push(performance.now() - start)
  }
}

// Échauffement : 5 requêtes non comptées (cache, connexion à la base)
for (let i = 0; i < 5; i++) await fetch(url).then((r) => r.arrayBuffer()).catch(() => {})

const begin = performance.now()
await Promise.all(Array.from({ length: C }, worker))
const seconds = (performance.now() - begin) / 1000

durations.sort((a, b) => a - b)
const pct = (p) => durations[Math.min(durations.length - 1, Math.floor((p / 100) * durations.length))]
const ms = (v) => `${v.toFixed(1)} ms`

console.log(`URL           : ${url}`)
console.log(`Requêtes      : ${N} (${C} en parallèle)`)
console.log(`Durée totale  : ${seconds.toFixed(2)} s`)
console.log(`Débit         : ${(N / seconds).toFixed(1)} requêtes/s`)
console.log(`Médiane (p50) : ${ms(pct(50))}`)
console.log(`p95           : ${ms(pct(95))}`)
console.log(`p99           : ${ms(pct(99))}`)
console.log(`Maximum       : ${ms(durations.at(-1))}`)
console.log(`Statuts       : ${[...statuses].map(([s, n]) => `${s} × ${n}`).join(', ')}`)
