/**
 * Redressement d'une photo de couverture, sans dépendance.
 *
 * Une couverture photographiée sur une table est un quadrilatère déformé par la
 * perspective. On retrouve ses quatre bords (detecterCouverture), on la ramène
 * à plat par une homographie (redresser), puis on étire légèrement ses niveaux
 * (rehausser) : une photo d'intérieur, terne ou jaunie, retrouve des blancs et
 * des noirs francs.
 *
 * Fonctions pures sur des images au format d'ImageData ({data, width, height},
 * RGBA) : elles se testent sous Node (tests/js/couverture.test.mjs), sans canvas.
 *
 * Pas d'OpenCV : une dizaine de Mo à télécharger à la première photo, sur les
 * téléphones des bénévoles. Cette détection suffit pour un livre posé sur un
 * fond uni ; ailleurs, le cadre tombe à côté et l'on tire les coins à la main.
 */

/** @typedef {{x: number, y: number}} Point */
/** @typedef {{data: Uint8ClampedArray, width: number, height: number}} Image */

const DEGRE = Math.PI / 180;

/**
 * Les quatre coins de la couverture, dans l'ordre haut-gauche, haut-droit,
 * bas-droit, bas-gauche ; null si rien de convaincant (le cadre par défaut
 * prend alors le relais). Prévue pour une image réduite d'environ 150 000
 * pixels (voir PIXELS_DETECTION dans cover_capture_controller.js).
 *
 * @param {Image} image
 * @returns {Point[]|null}
 */
export function detecterCouverture(image) {
    const { width: w, height: h } = image;
    const gris = flouter(flouter(niveauxDeGris(image), w, h, 1), w, h, 1);
    const { gx, gy, norme } = gradients(gris, w, h);
    const seuil = seuilDeBord(norme);

    const bords = new Uint8Array(w * h);
    const angles = new Float32Array(w * h);
    for (let i = 0; i < norme.length; i++) {
        if (norme[i] > seuil) {
            bords[i] = 1;
            angles[i] = Math.atan2(gy[i], gx[i]);
        }
    }

    const droites = droitesDominantes(gx, gy, norme, bords, w, h, seuil);

    return meilleurQuadrilatere(droites, { bords, angles, w, h });
}

/**
 * Cadre de départ quand la détection échoue : un rectangle centré, en retrait.
 *
 * @returns {Point[]}
 */
export function cadreParDefaut(largeur, hauteur, retrait = 0.12) {
    const x0 = largeur * retrait;
    const y0 = hauteur * retrait;
    const x1 = largeur * (1 - retrait);
    const y1 = hauteur * (1 - retrait);

    return [{ x: x0, y: y0 }, { x: x1, y: y0 }, { x: x1, y: y1 }, { x: x0, y: y1 }];
}

/**
 * Taille de l'image redressée. Les longueurs vues ne suffisent pas : un livre
 * photographié en biais sur une table a sa hauteur raccourcie par la
 * perspective, et sortait écrasé (un Folio, haut de 0,6 fois sa largeur en
 * moins, devenait presque carré). Avec `centre` (le centre de la photo), le
 * vrai rapport largeur/hauteur est retrouvé (rapportReel) ; sans perspective
 * mesurable, on garde les longueurs vues, alors justes.
 */
export function dimensionsRedressees(coins, coteMax = 1600, centre = null) {
    const [hg, hd, bd, bg] = coins;
    let largeur = Math.max(distance(hg, hd), distance(bg, bd));
    let hauteur = Math.max(distance(hg, bg), distance(hd, bd));

    const rapport = centre === null ? null : rapportReel(coins, centre);
    if (rapport !== null) {
        // La plus grande des dimensions vues, l'autre deduite du vrai rapport.
        hauteur = Math.max(hauteur, largeur / rapport);
        largeur = hauteur * rapport;
    }

    const facteur = Math.min(1, coteMax / Math.max(largeur, hauteur, 1));

    return {
        largeur: Math.max(1, Math.round(largeur * facteur)),
        hauteur: Math.max(1, Math.round(hauteur * facteur)),
    };
}

/**
 * Ramène à plat le quadrilatère `coins` de `source`, en une image largeur ×
 * hauteur. Chaque pixel de la sortie va chercher sa couleur dans la source par
 * l'homographie, en interpolation bilinéaire.
 *
 * @param {Image} source
 * @param {Point[]} coins
 * @returns {Image}
 */
export function redresser(source, coins, largeur, hauteur) {
    const H = homographie(
        [{ x: 0, y: 0 }, { x: largeur - 1, y: 0 }, { x: largeur - 1, y: hauteur - 1 }, { x: 0, y: hauteur - 1 }],
        coins,
    );
    const { data: s, width: sw, height: sh } = source;
    const sortie = new Uint8ClampedArray(largeur * hauteur * 4);

    for (let v = 0; v < hauteur; v++) {
        for (let u = 0; u < largeur; u++) {
            const p = appliquer(H, u, v);
            const x = Math.min(Math.max(p.x, 0), sw - 1);
            const y = Math.min(Math.max(p.y, 0), sh - 1);
            const x0 = Math.min(Math.floor(x), sw - 2);
            const y0 = Math.min(Math.floor(y), sh - 2);
            const fx = x - x0;
            const fy = y - y0;
            const a = (y0 * sw + x0) * 4;
            const b = a + 4;
            const c = a + sw * 4;
            const d = c + 4;
            const o = (v * largeur + u) * 4;

            for (let k = 0; k < 3; k++) {
                const haut = s[a + k] + (s[b + k] - s[a + k]) * fx;
                const bas = s[c + k] + (s[d + k] - s[c + k]) * fx;
                sortie[o + k] = haut + (bas - haut) * fy;
            }
            sortie[o + 3] = 255;
        }
    }

    return { data: sortie, width: largeur, height: hauteur };
}

/**
 * Rehaussement léger, en deux temps. Une balance des blancs douce rapproche les
 * hautes lumières de chaque canal de celles du plus clair (un quart au plus :
 * une couverture vraiment jaune doit le rester). Puis la luminance est étirée
 * entre ses 0,5 % et 99,5 %, sans jamais dépasser un gain de 1,8 ni assombrir
 * une image qui n'a pas de noir.
 *
 * @param {Image} image
 * @returns {Image}
 */
export function rehausser(image) {
    const { data, width, height } = image;
    const hauts = [0, 1, 2].map((canal) => percentile(histogrammeCanal(data, canal), 0.99));
    const reference = Math.max(...hauts);
    const facteurs = hauts.map((v) => (v > 0 ? Math.min(1.25, Math.max(1, reference / v)) : 1));

    const luminances = new Uint32Array(256);
    for (let p = 0; p < data.length; p += 4) {
        const l = 0.299 * data[p] * facteurs[0] + 0.587 * data[p + 1] * facteurs[1] + 0.114 * data[p + 2] * facteurs[2];
        luminances[Math.min(255, Math.round(l))]++;
    }

    // 64 : le plus sombre d'une photo d'interieur depasse souvent 50, meme sur
    // de l'encre noire ; une couverture pastel, elle, reste bien au-dessus.
    const bas = Math.min(percentile(luminances, 0.005), 64);
    const haut = Math.max(percentile(luminances, 0.995), 160);
    const gain = Math.min(1.8, 255 / Math.max(1, haut - bas));

    const sortie = new Uint8ClampedArray(data.length);
    for (let p = 0; p < data.length; p += 4) {
        for (let k = 0; k < 3; k++) {
            sortie[p + k] = (data[p + k] * facteurs[k] - bas) * gain;
        }
        sortie[p + 3] = 255;
    }

    return { data: sortie, width, height };
}

/**
 * Homographie qui envoie les 4 points `de` sur les 4 points `vers`, en 9
 * coefficients (le dernier vaut 1).
 *
 * @param {Point[]} de
 * @param {Point[]} vers
 * @returns {number[]}
 */
export function homographie(de, vers) {
    const A = [];
    const b = [];

    for (let i = 0; i < 4; i++) {
        const { x, y } = de[i];
        const { x: u, y: v } = vers[i];
        A.push([x, y, 1, 0, 0, 0, -u * x, -u * y]);
        b.push(u);
        A.push([0, 0, 0, x, y, 1, -v * x, -v * y]);
        b.push(v);
    }

    return [...resoudre(A, b), 1];
}

/**
 * Vrai rapport largeur/hauteur d'un rectangle vu en perspective, d'après Zhang
 * et He (« Whiteboard scanning and image enhancement », 2007) : en supposant le
 * centre optique au centre de la photo et des pixels carrés, les quatre coins
 * donnent la focale, puis le rapport. null quand la perspective est trop faible
 * pour la mesurer (photo prise de face : les longueurs vues sont alors justes).
 */
export function rapportReel([hg, hd, bd, bg], { x: u0, y: v0 }) {
    const m1 = [hg.x, hg.y, 1];
    const m2 = [hd.x, hd.y, 1];
    const m3 = [bg.x, bg.y, 1];
    const m4 = [bd.x, bd.y, 1];
    const k2 = scalaire(vectoriel(m1, m4), m3) / scalaire(vectoriel(m2, m4), m3);
    const k3 = scalaire(vectoriel(m1, m4), m2) / scalaire(vectoriel(m3, m4), m2);
    const n2 = m2.map((v, i) => k2 * v - m1[i]);
    const n3 = m3.map((v, i) => k3 * v - m1[i]);

    if (Math.abs(n2[2]) < 1e-9 || Math.abs(n3[2]) < 1e-9) return null;

    const f2 = -(
        (n2[0] * n3[0] - (n2[0] * n3[2] + n2[2] * n3[0]) * u0 + n2[2] * n3[2] * u0 * u0)
        + (n2[1] * n3[1] - (n2[1] * n3[2] + n2[2] * n3[1]) * v0 + n2[2] * n3[2] * v0 * v0)
    ) / (n2[2] * n3[2]);

    if (!(f2 > 0)) return null;

    const f = Math.sqrt(f2);
    const norme = (n) => ((n[0] - u0 * n[2]) / f) ** 2 + ((n[1] - v0 * n[2]) / f) ** 2 + n[2] ** 2;
    const rapport = Math.sqrt(norme(n2) / norme(n3));

    return Number.isFinite(rapport) && rapport > 0.2 && rapport < 5 ? rapport : null;
}

function vectoriel(a, b) {
    return [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]];
}

function scalaire(a, b) {
    return a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
}

/** @returns {Point} */
export function appliquer(H, x, y) {
    const w = H[6] * x + H[7] * y + H[8];

    return { x: (H[0] * x + H[1] * y + H[2]) / w, y: (H[3] * x + H[4] * y + H[5]) / w };
}

// --- Détection ---------------------------------------------------------------

function niveauxDeGris({ data, width, height }) {
    const gris = new Float32Array(width * height);

    for (let i = 0, p = 0; i < gris.length; i++, p += 4) {
        gris[i] = 0.299 * data[p] + 0.587 * data[p + 1] + 0.114 * data[p + 2];
    }

    return gris;
}

/** Flou en boîte séparable : il éteint le grain avant le calcul des gradients. */
function flouter(source, w, h, rayon) {
    const temp = new Float32Array(w * h);
    const sortie = new Float32Array(w * h);
    const n = 2 * rayon + 1;

    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            let somme = 0;
            for (let k = -rayon; k <= rayon; k++) {
                somme += source[y * w + Math.min(w - 1, Math.max(0, x + k))];
            }
            temp[y * w + x] = somme / n;
        }
    }

    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            let somme = 0;
            for (let k = -rayon; k <= rayon; k++) {
                somme += temp[Math.min(h - 1, Math.max(0, y + k)) * w + x];
            }
            sortie[y * w + x] = somme / n;
        }
    }

    return sortie;
}

/** Sobel. */
function gradients(gris, w, h) {
    const gx = new Float32Array(w * h);
    const gy = new Float32Array(w * h);
    const norme = new Float32Array(w * h);

    for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
            const i = y * w + x;
            const hg = gris[i - w - 1];
            const hm = gris[i - w];
            const hd = gris[i - w + 1];
            const mg = gris[i - 1];
            const md = gris[i + 1];
            const bg = gris[i + w - 1];
            const bm = gris[i + w];
            const bd = gris[i + w + 1];

            gx[i] = hd + 2 * md + bd - (hg + 2 * mg + bg);
            gy[i] = bg + 2 * bm + bd - (hg + 2 * hm + hd);
            norme[i] = Math.hypot(gx[i], gy[i]);
        }
    }

    return { gx, gy, norme };
}

/** Moyenne plus un écart-type : seuls les vrais contrastes passent. */
function seuilDeBord(norme) {
    let somme = 0;
    let carres = 0;

    for (const v of norme) {
        somme += v;
        carres += v * v;
    }

    const moyenne = somme / norme.length;
    const ecart = Math.sqrt(Math.max(0, carres / norme.length - moyenne * moyenne));

    return Math.max(15, moyenne + ecart);
}

/**
 * Transformée de Hough : chaque pixel de bord vote pour les droites qui le
 * traversent. Le gradient étant perpendiculaire au bord, il donne l'angle de
 * la droite à quelques degrés près : on ne vote que là, plus vite et plus net.
 * Une droite s'écrit x·cos θ + y·sin θ = ρ, θ en degrés de 0 à 179.
 *
 * Chaque vote est plafonné à deux fois le seuil de bord : une droite pèse par
 * sa longueur, pas par son contraste. Sans ce plafond, les lignes d'un titre
 * noir sur fond clair, courtes mais très contrastées, passaient devant le
 * bord de la couverture, long mais doux sur une table claire.
 */
function droitesDominantes(gx, gy, norme, bords, w, h, seuil) {
    const diag = Math.ceil(Math.hypot(w, h));
    const nRho = 2 * diag + 1;
    const votes = new Float32Array(180 * nRho);
    const cos = new Float32Array(180);
    const sin = new Float32Array(180);

    for (let t = 0; t < 180; t++) {
        cos[t] = Math.cos(t * DEGRE);
        sin[t] = Math.sin(t * DEGRE);
    }

    for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
            const i = y * w + x;
            if (!bords[i]) continue;

            const angle = Math.round(Math.atan2(gy[i], gx[i]) / DEGRE);
            const poids = Math.min(norme[i], 2 * seuil);
            for (let d = -4; d <= 4; d++) {
                const t = (((angle + d) % 180) + 180) % 180;
                const rho = Math.round(x * cos[t] + y * sin[t]);
                votes[t * nRho + rho + diag] += poids;
            }
        }
    }

    let max = 0;
    for (const v of votes) if (v > max) max = v;
    const plancher = max * 0.12;

    const pics = [];
    for (let t = 0; t < 180; t++) {
        for (let r = 0; r < nRho; r++) {
            const v = votes[t * nRho + r];
            if (v < plancher || !estMaximumLocal(votes, t, r, v, nRho)) continue;
            pics.push({ theta: t, rho: r - diag, votes: v });
        }
    }

    // Un trait epais (un cable pose sur la table) donne plusieurs droites
    // paralleles a quelques pixels l'une de l'autre : sans dedoublonnage, elles
    // occupaient toute la liste et en chassaient les vrais bords du livre.
    const ecartRho = Math.max(6, Math.round(0.015 * diag));
    const retenues = [];
    for (const pic of pics.sort((a, b) => b.votes - a.votes)) {
        const doublon = retenues.some((r) => {
            if (ecartAngulaire(r.theta, pic.theta) > 6) return false;
            // De part et d'autre de 0°/180°, la meme droite a un ρ oppose.
            const rho = Math.abs(r.theta - pic.theta) > 90 ? -pic.rho : pic.rho;

            return Math.abs(r.rho - rho) <= ecartRho;
        });
        if (!doublon) retenues.push(pic);
        if (retenues.length === 40) break;
    }

    return retenues;
}

/** Maximum de son voisinage (θ ± 3°, ρ ± 6), θ bouclant à 180° avec ρ opposé. */
function estMaximumLocal(votes, t, r, v, nRho) {
    for (let dt = -3; dt <= 3; dt++) {
        for (let dr = -6; dr <= 6; dr++) {
            if (dt === 0 && dr === 0) continue;

            let tt = t + dt;
            let rr = r + dr;
            if (tt < 0 || tt >= 180) {
                tt = (tt + 180) % 180;
                rr = nRho - 1 - rr;
            }
            if (rr < 0 || rr >= nRho) continue;

            const voisin = votes[tt * nRho + rr];
            // Égalité : on ne garde que le premier, pour ne pas doubler un pic.
            if (voisin > v || (voisin === v && tt * nRho + rr < t * nRho + r)) return false;
        }
    }

    return true;
}

/**
 * Deux droites presque horizontales et deux presque verticales, éloignées les
 * unes des autres : on essaie toutes les combinaisons des meilleures, et l'on
 * garde le quadrilatère convexe dont les côtés sont le mieux portés par des
 * bords réels de même orientation, à surface comparable le plus grand.
 */
function meilleurQuadrilatere(droites, contexte) {
    const { w, h } = contexte;
    const horizontales = droites.filter((d) => d.theta >= 45 && d.theta <= 135).slice(0, 12);
    const verticales = droites.filter((d) => d.theta < 45 || d.theta > 135).slice(0, 12);

    const yAuCentre = (d) => (d.rho - (w / 2) * Math.cos(d.theta * DEGRE)) / Math.sin(d.theta * DEGRE);
    const xAuCentre = (d) => (d.rho - (h / 2) * Math.sin(d.theta * DEGRE)) / Math.cos(d.theta * DEGRE);

    let meilleur = null;
    let meilleurScore = 0;

    for (const [h1, h2] of paires(horizontales)) {
        if (ecartAngulaire(h1.theta, h2.theta) > 25 || Math.abs(yAuCentre(h1) - yAuCentre(h2)) < 0.2 * h) continue;
        const [haut, bas] = yAuCentre(h1) < yAuCentre(h2) ? [h1, h2] : [h2, h1];

        for (const [v1, v2] of paires(verticales)) {
            if (ecartAngulaire(v1.theta, v2.theta) > 25 || Math.abs(xAuCentre(v1) - xAuCentre(v2)) < 0.2 * w) continue;
            const [gauche, droite] = xAuCentre(v1) < xAuCentre(v2) ? [v1, v2] : [v2, v1];

            const coins = [
                intersection(haut, gauche),
                intersection(haut, droite),
                intersection(bas, droite),
                intersection(bas, gauche),
            ];
            if (coins.some((c) => c === null) || !dansLImage(coins, w, h) || !convexe(coins)) continue;

            const surface = aire(coins) / (w * h);
            if (surface < 0.12) continue;

            const soutiens = coins.map((c, k) => soutien(c, coins[(k + 1) % 4], contexte));
            const moyenne = soutiens.reduce((s, v) => s + v, 0) / 4;
            const minimum = Math.min(...soutiens);
            if (minimum < 0.3 || moyenne < 0.45) continue;

            const score = 0.6 * moyenne + 0.4 * minimum + 0.3 * surface;
            if (score > meilleurScore) {
                meilleurScore = score;
                meilleur = coins;
            }
        }
    }

    return meilleur === null
        ? null
        : meilleur.map(({ x, y }) => ({ x: Math.min(Math.max(x, 0), w - 1), y: Math.min(Math.max(y, 0), h - 1) }));
}

/**
 * Part du segment [a, b] longée par des pixels de bord dont le gradient est
 * perpendiculaire au segment (à 25° près), dans un voisinage de 2 px : les
 * contrastes d'une illustration qu'il ne fait que traverser ne comptent pas.
 */
function soutien(a, b, { bords, angles, w, h }) {
    const normale = Math.atan2(b.x - a.x, -(b.y - a.y));
    const echantillons = 48;
    let portes = 0;

    for (let k = 0; k < echantillons; k++) {
        const t = (k + 0.5) / echantillons;
        const px = Math.round(a.x + (b.x - a.x) * t);
        const py = Math.round(a.y + (b.y - a.y) * t);

        voisinage: for (let dy = -2; dy <= 2; dy++) {
            for (let dx = -2; dx <= 2; dx++) {
                const x = px + dx;
                const y = py + dy;
                if (x < 0 || y < 0 || x >= w || y >= h) continue;

                const i = y * w + x;
                if (bords[i] && ecartAngulaire(angles[i] / DEGRE, normale / DEGRE) <= 25) {
                    portes++;
                    break voisinage;
                }
            }
        }
    }

    return portes / echantillons;
}

// --- Géométrie ---------------------------------------------------------------

function* paires(liste) {
    for (let i = 0; i < liste.length; i++) {
        for (let j = i + 1; j < liste.length; j++) {
            yield [liste[i], liste[j]];
        }
    }
}

/** Écart entre deux directions, en degrés, sans tenir compte du sens. */
function ecartAngulaire(a, b) {
    const d = Math.abs(a - b) % 180;

    return Math.min(d, 180 - d);
}

function intersection(d1, d2) {
    const c1 = Math.cos(d1.theta * DEGRE);
    const s1 = Math.sin(d1.theta * DEGRE);
    const c2 = Math.cos(d2.theta * DEGRE);
    const s2 = Math.sin(d2.theta * DEGRE);
    const det = c1 * s2 - s1 * c2;

    if (Math.abs(det) < 1e-6) return null;

    return { x: (d1.rho * s2 - d2.rho * s1) / det, y: (c1 * d2.rho - c2 * d1.rho) / det };
}

function dansLImage(coins, w, h) {
    return coins.every(({ x, y }) => x >= -0.03 * w && x <= 1.03 * w && y >= -0.03 * h && y <= 1.03 * h);
}

function convexe(coins) {
    let signe = 0;

    for (let k = 0; k < 4; k++) {
        const a = coins[k];
        const b = coins[(k + 1) % 4];
        const c = coins[(k + 2) % 4];
        const z = (b.x - a.x) * (c.y - b.y) - (b.y - a.y) * (c.x - b.x);
        if (z === 0) return false;
        if (signe === 0) signe = Math.sign(z);
        else if (Math.sign(z) !== signe) return false;
    }

    return true;
}

function aire(coins) {
    let somme = 0;

    for (let k = 0; k < 4; k++) {
        const a = coins[k];
        const b = coins[(k + 1) % 4];
        somme += a.x * b.y - b.x * a.y;
    }

    return Math.abs(somme) / 2;
}

function distance(a, b) {
    return Math.hypot(b.x - a.x, b.y - a.y);
}

// --- Calcul ------------------------------------------------------------------

/** Élimination de Gauss avec pivot partiel. */
function resoudre(A, b) {
    const n = b.length;
    const M = A.map((ligne, i) => [...ligne, b[i]]);

    for (let col = 0; col < n; col++) {
        let pivot = col;
        for (let l = col + 1; l < n; l++) {
            if (Math.abs(M[l][col]) > Math.abs(M[pivot][col])) pivot = l;
        }
        [M[col], M[pivot]] = [M[pivot], M[col]];

        for (let l = col + 1; l < n; l++) {
            const f = M[l][col] / M[col][col];
            for (let k = col; k <= n; k++) M[l][k] -= f * M[col][k];
        }
    }

    const x = new Array(n).fill(0);
    for (let l = n - 1; l >= 0; l--) {
        let s = M[l][n];
        for (let k = l + 1; k < n; k++) s -= M[l][k] * x[k];
        x[l] = s / M[l][l];
    }

    return x;
}

function histogrammeCanal(data, canal) {
    const histogramme = new Uint32Array(256);
    for (let p = canal; p < data.length; p += 4) histogramme[data[p]]++;

    return histogramme;
}

/** Valeur sous laquelle tombe la part `q` des pixels. */
function percentile(histogramme, q) {
    let total = 0;
    for (const v of histogramme) total += v;

    const cible = total * q;
    let cumul = 0;
    for (let i = 0; i < 256; i++) {
        cumul += histogramme[i];
        if (cumul >= cible) return i;
    }

    return 255;
}
