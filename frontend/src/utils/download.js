// Lance le téléchargement d'un fichier à partir de son URL (celle renvoyée par l'API : "downloadUrl").
//
// Le serveur répond avec "Content-Disposition: attachment" : le navigateur enregistre alors le
// fichier SANS quitter la page. Le fichier est envoyé en flux directement par le navigateur
// (et non lu en mémoire par JavaScript), ce qui permet des fichiers jusqu'à 1 Go.
//
// Principe : on crée un lien <a> invisible vers cette URL et on "clique" dessus par programme.
export function startDownload(url) {
  const link = document.createElement('a')
  link.href = url
  link.download = '' // indique au navigateur qu'il s'agit d'un téléchargement
  document.body.appendChild(link)
  link.click()
  link.remove()
}
