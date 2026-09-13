/*
 * Service worker de l'administration.
 *
 * Il vit a la racine, et non dans /admin/ : un dossier `public/admin/` ferait
 * intervenir le DirectorySlash d'Apache, qui redirigerait /admin vers /admin/
 * par un 301 — permanent, donc mis en cache durablement par le navigateur, et
 * paye a chaque lancement de l'application. La portee racine couvre /admin sans
 * negocier d'en-tete `Service-Worker-Allowed` ; l'interception, elle, est bornee
 * a /admin plus bas.
 *
 * Il n'est pas servi par AssetMapper, qui ajoute une empreinte au nom de
 * fichier : un service worker doit vivre a une URL stable, sinon le navigateur
 * en installerait un nouveau a chaque livraison.
 *
 * IL NE MET RIEN EN CACHE, et c'est deliberé. L'administration ne fait rien
 * d'utile sans reseau : le scan interroge Google Books, les prets ecrivent en
 * base, les listes viennent du serveur. Un cache ne servirait qu'une coquille
 * vide, au prix d'ecrans perimes apres chaque deploiement. Sa seule raison
 * d'etre est de rendre l'application installable — Chrome exige un gestionnaire
 * `fetch` pour proposer l'installation — et d'offrir une page hors ligne
 * presentable plutot que l'erreur brute du navigateur en mode plein ecran.
 */

const HORS_LIGNE = `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hors ligne</title>
<style>
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         background:#f7f3ec; color:#23201b; font-family:system-ui,sans-serif; padding:2rem; }
  div { max-width:32ch; text-align:center; }
  h1 { font-family:Georgia,serif; font-size:1.5rem; margin:0 0 .5rem; }
  p { color:#6b6358; line-height:1.6; margin:0 0 1.5rem; }
  button { background:#8c3a2b; color:#fffdf9; border:0; border-radius:3px;
           padding:.7rem 1.2rem; font:inherit; font-weight:500; cursor:pointer; }
</style></head>
<body><div>
  <h1>Pas de connexion</h1>
  <p>L'administration a besoin du réseau pour chercher les livres et enregistrer les prêts.</p>
  <button onclick="location.reload()">Réessayer</button>
</div></body></html>`;

self.addEventListener('install', () => {
    // Prendre la main tout de suite : sans cela, une livraison n'est active
    // qu'apres la fermeture de tous les onglets ouverts.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    // Seules les navigations vers l'administration sont interceptees : tout le
    // reste — assets, requetes de fond, site vitrine — part au reseau sans
    // detour, exactement comme sans service worker.
    if (event.request.mode !== 'navigate') {
        return;
    }

    if (!new URL(event.request.url).pathname.startsWith('/admin')) {
        return;
    }

    event.respondWith(
        fetch(event.request).catch(() => new Response(HORS_LIGNE, {
            status: 503,
            headers: { 'Content-Type': 'text/html; charset=utf-8' },
        })),
    );
});
