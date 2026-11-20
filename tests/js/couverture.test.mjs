// Tests de assets/lib/couverture.js, sans dependance : node --test tests/js/
// Les photos sont synthetiques — un livre texture pose sur une table veinee — ce
// qui donne les vrais coins, a comparer a ceux que la detection retrouve.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    appliquer,
    cadreParDefaut,
    detecterCouverture,
    dimensionsRedressees,
    homographie,
    redresser,
    rehausser,
} from '../../assets/lib/couverture.js';

const LARGEUR = 300;
const HAUTEUR = 400;

/** Generateur pseudo-aleatoire determine : un test qui echoue echoue toujours. */
function hasard(graine) {
    let etat = graine;
    return () => {
        etat = (etat * 1664525 + 1013904223) % 4294967296;
        return etat / 4294967296;
    };
}

function dansLeQuad(x, y, coins) {
    let signe = 0;
    for (let k = 0; k < 4; k++) {
        const a = coins[k];
        const b = coins[(k + 1) % 4];
        const z = (b.x - a.x) * (y - a.y) - (b.y - a.y) * (x - a.x);
        if (z === 0) continue;
        if (signe === 0) signe = Math.sign(z);
        else if (Math.sign(z) !== signe) return false;
    }
    return true;
}

/** Distance du point (x, y) a la droite passant par a et b, en fractions de l'image. */
function surLeCable(x, y, [ax, ay], [bx, by], epaisseur) {
    const x1 = ax * LARGEUR;
    const y1 = ay * HAUTEUR;
    const x2 = bx * LARGEUR;
    const y2 = by * HAUTEUR;
    const distance = Math.abs((y2 - y1) * x - (x2 - x1) * y + x2 * y1 - y2 * x1) / Math.hypot(x2 - x1, y2 - y1);

    return distance < epaisseur;
}

/**
 * Photo d'un livre (coins en fractions de l'image) sur une table en bois. Avec
 * `cables`, deux cables noirs traversent la table et passent sous le livre :
 * de longues diagonales tres contrastees, comme sur une vraie photo d'essai,
 * ou elles chassaient les bords du livre de la liste des candidates.
 */
function photo(coinsRelatifs, graine = 7, { cables = false } = {}) {
    const aleatoire = hasard(graine);
    const coins = coinsRelatifs.map(([x, y]) => ({ x: x * LARGEUR, y: y * HAUTEUR }));
    // Coordonnees « sur la couverture » de chaque pixel, pour y dessiner une
    // illustration et des lignes de titre qui suivent la perspective.
    const versCouverture = homographie(coins, [{ x: 0, y: 0 }, { x: 1, y: 0 }, { x: 1, y: 1 }, { x: 0, y: 1 }]);
    const data = new Uint8ClampedArray(LARGEUR * HAUTEUR * 4);

    for (let y = 0; y < HAUTEUR; y++) {
        for (let x = 0; x < LARGEUR; x++) {
            const p = (y * LARGEUR + x) * 4;
            const grain = (aleatoire() - 0.5) * 24;
            let r;
            let g;
            let b;

            if (dansLeQuad(x, y, coins)) {
                const { x: s, y: t } = appliquer(versCouverture, x, y);
                // Ciel clair en haut, illustration bariolee en bas.
                r = 150 + 70 * Math.sin(s * 9) * t;
                g = 185 + 50 * Math.cos(t * 11 + s * 3) * t;
                b = 225 - 90 * t;
                // Trois lignes de titre, sombres : de vrais contrastes horizontaux
                // a l'interieur de la couverture.
                for (const ligne of [0.12, 0.17, 0.22]) {
                    if (Math.abs(t - ligne) < 0.012 && s > 0.45 && s < 0.9) {
                        r = 40;
                        g = 40;
                        b = 50;
                    }
                }
            } else {
                // Bois : veines horizontales douces.
                const veine = 14 * Math.sin(y / 9 + Math.sin(x / 37) * 2);
                r = 175 + veine;
                g = 125 + veine * 0.8;
                b = 75 + veine * 0.5;

                if (cables && (surLeCable(x, y, [0.5, 0], [1, 0.7], 3) || surLeCable(x, y, [0, 0.05], [0.7, 0], 2.5))) {
                    r = 30;
                    g = 30;
                    b = 35;
                }
            }

            data[p] = r + grain;
            data[p + 1] = g + grain;
            data[p + 2] = b + grain;
            data[p + 3] = 255;
        }
    }

    return { image: { data, width: LARGEUR, height: HAUTEUR }, coins };
}

function ecartMaximal(trouves, attendus) {
    return Math.max(...trouves.map((c, k) => Math.hypot(c.x - attendus[k].x, c.y - attendus[k].y)));
}

const TOLERANCE = 0.03 * Math.hypot(LARGEUR, HAUTEUR); // 15 px

test('detecte un livre photographie de face', () => {
    const { image, coins } = photo([[0.2, 0.15], [0.8, 0.15], [0.8, 0.85], [0.2, 0.85]]);
    const trouves = detecterCouverture(image);

    assert.ok(trouves, 'aucun cadre detecte');
    assert.ok(ecartMaximal(trouves, coins) <= TOLERANCE, `coins trop loin : ${JSON.stringify(trouves)}`);
});

test('detecte un livre pose de travers', () => {
    const { image, coins } = photo([[0.26, 0.12], [0.84, 0.19], [0.74, 0.88], [0.16, 0.81]], 11);
    const trouves = detecterCouverture(image);

    assert.ok(trouves, 'aucun cadre detecte');
    assert.ok(ecartMaximal(trouves, coins) <= TOLERANCE, `coins trop loin : ${JSON.stringify(trouves)}`);
});

test('detecte un livre vu en perspective, comme sur une table', () => {
    // Le haut, plus loin de l'objectif, est plus etroit que le bas.
    const { image, coins } = photo([[0.27, 0.22], [0.72, 0.22], [0.83, 0.6], [0.15, 0.6]], 23);
    const trouves = detecterCouverture(image);

    assert.ok(trouves, 'aucun cadre detecte');
    assert.ok(ecartMaximal(trouves, coins) <= TOLERANCE, `coins trop loin : ${JSON.stringify(trouves)}`);
});

test('ne se laisse pas detourner par des cables poses sur la table', () => {
    const { image, coins } = photo([[0.22, 0.24], [0.76, 0.23], [0.82, 0.6], [0.12, 0.59]], 31, { cables: true });
    const trouves = detecterCouverture(image);

    assert.ok(trouves, 'aucun cadre detecte');
    assert.ok(ecartMaximal(trouves, coins) <= TOLERANCE, `coins trop loin : ${JSON.stringify(trouves)}`);
});

test('ne pretend rien trouver sur une table vide', () => {
    const { image } = photo([[2, 2], [2.1, 2], [2.1, 2.1], [2, 2.1]], 5);

    assert.equal(detecterCouverture(image), null);
});

test('le cadre par defaut est un rectangle centre en retrait', () => {
    assert.deepEqual(cadreParDefaut(100, 200, 0.1), [
        { x: 10, y: 20 }, { x: 90, y: 20 }, { x: 90, y: 180 }, { x: 10, y: 180 },
    ]);
});

test("l'homographie envoie chaque coin sur son image", () => {
    const de = [{ x: 0, y: 0 }, { x: 99, y: 0 }, { x: 99, y: 149 }, { x: 0, y: 149 }];
    const vers = [{ x: 30, y: 40 }, { x: 210, y: 55 }, { x: 260, y: 330 }, { x: 10, y: 300 }];
    const H = homographie(de, vers);

    de.forEach((point, k) => {
        const image = appliquer(H, point.x, point.y);
        assert.ok(Math.abs(image.x - vers[k].x) < 1e-6 && Math.abs(image.y - vers[k].y) < 1e-6);
    });
});

test('la couverture redressee retrouve ses proportions et sa position', () => {
    // Chaque pixel de la couverture porte sa position sur elle : rouge = gauche
    // a droite, vert = haut en bas. Redressee, l'image doit former un degrade
    // regulier, quelle que soit la perspective de depart.
    const coins = [{ x: 60, y: 90 }, { x: 230, y: 80 }, { x: 260, y: 330 }, { x: 40, y: 300 }];
    const versCouverture = homographie(coins, [{ x: 0, y: 0 }, { x: 1, y: 0 }, { x: 1, y: 1 }, { x: 0, y: 1 }]);
    const data = new Uint8ClampedArray(LARGEUR * HAUTEUR * 4);
    for (let y = 0; y < HAUTEUR; y++) {
        for (let x = 0; x < LARGEUR; x++) {
            const { x: s, y: t } = appliquer(versCouverture, x, y);
            const p = (y * LARGEUR + x) * 4;
            data[p] = s * 255;
            data[p + 1] = t * 255;
            data[p + 3] = 255;
        }
    }

    const { largeur, hauteur } = dimensionsRedressees(coins);
    const sortie = redresser({ data, width: LARGEUR, height: HAUTEUR }, coins, largeur, hauteur);

    assert.ok(hauteur > largeur, 'une couverture debout doit rester plus haute que large');
    for (const [u, v] of [[0, 0], [largeur - 1, 0], [largeur >> 1, hauteur >> 1], [0, hauteur - 1], [largeur - 1, hauteur - 1]]) {
        const p = (v * largeur + u) * 4;
        assert.ok(Math.abs(sortie.data[p] - (u / (largeur - 1)) * 255) <= 3, `rouge en ${u},${v}`);
        assert.ok(Math.abs(sortie.data[p + 1] - (v / (hauteur - 1)) * 255) <= 3, `vert en ${u},${v}`);
    }
});

/** Coins image d'un rectangle 3D (largeur × hauteur) incline, vu par une camera simulee. */
function projeter(largeur, hauteur, inclinaison, pivot, focale, centre) {
    const ci = Math.cos(inclinaison * Math.PI / 180);
    const si = Math.sin(inclinaison * Math.PI / 180);
    const cp = Math.cos(pivot * Math.PI / 180);
    const sp = Math.sin(pivot * Math.PI / 180);

    return [[-1, -1], [1, -1], [1, 1], [-1, 1]].map(([a, b]) => {
        let x = (a * largeur) / 2;
        let y = (b * hauteur) / 2;
        let z = 0;
        [y, z] = [y * ci - z * si, y * si + z * ci]; // inclinaison autour de x
        [x, z] = [x * cp + z * sp, -x * sp + z * cp]; // pivot autour de y
        z += 2.5;
        return { x: (focale * x) / z + centre.x, y: (focale * y) / z + centre.y };
    });
}

test('un livre photographie en biais garde ses vraies proportions', () => {
    // Un Folio : 0,6 fois plus large que haut. Vu a 45°, sa hauteur apparente
    // est ecrasee ; les longueurs vues donneraient une couverture presque carree.
    const centre = { x: 150, y: 200 };
    const coins = projeter(0.6, 1, 45, 10, 900, centre);
    const { largeur, hauteur } = dimensionsRedressees(coins, 1600, centre);

    assert.ok(Math.abs(largeur / hauteur - 0.6) < 0.02, `rapport ${(largeur / hauteur).toFixed(3)} au lieu de 0,6`);
});

test('de face, les proportions vues sont gardees', () => {
    const coins = [{ x: 60, y: 50 }, { x: 240, y: 50 }, { x: 240, y: 350 }, { x: 60, y: 350 }];

    assert.deepEqual(dimensionsRedressees(coins, 1600, { x: 150, y: 200 }), { largeur: 180, hauteur: 300 });
});

test('la taille redressee ne depasse jamais le cote maximal', () => {
    const coins = [{ x: 0, y: 0 }, { x: 3000, y: 0 }, { x: 3000, y: 4000 }, { x: 0, y: 4000 }];

    assert.deepEqual(dimensionsRedressees(coins, 1600), { largeur: 1200, hauteur: 1600 });
});

function statistiques(image) {
    const lum = [];
    const max = [0, 0, 0];
    for (let p = 0; p < image.data.length; p += 4) {
        lum.push(0.299 * image.data[p] + 0.587 * image.data[p + 1] + 0.114 * image.data[p + 2]);
        for (let k = 0; k < 3; k++) max[k] = Math.max(max[k], image.data[p + k]);
    }
    lum.sort((a, b) => a - b);
    return { bas: lum[Math.floor(lum.length * 0.005)], haut: lum[Math.floor(lum.length * 0.995)], max };
}

test('le rehaussement rend ses blancs et ses noirs a une photo terne et jaunie', () => {
    const data = new Uint8ClampedArray(200 * 100 * 4);
    for (let i = 0; i < 200 * 100; i++) {
        const f = (i % 200) / 199;
        data[i * 4] = 60 + 140 * f;     // rouge 60..200
        data[i * 4 + 1] = 60 + 130 * f; // vert 60..190
        data[i * 4 + 2] = 40 + 110 * f; // bleu 40..150 : le jaunissement
        data[i * 4 + 3] = 255;
    }

    const apres = statistiques(rehausser({ data, width: 200, height: 100 }));

    assert.ok(apres.haut >= 235, `blancs trop ternes : ${apres.haut}`);
    assert.ok(apres.bas <= 20, `noirs trop gris : ${apres.bas}`);
    assert.ok(apres.max[0] - apres.max[2] < 30, `encore jaune : ${apres.max}`);
});

test('le rehaussement laisse intacte une photo deja bien exposee', () => {
    const data = new Uint8ClampedArray(256 * 4);
    for (let i = 0; i < 256; i++) {
        data.set([i, i, i, 255], i * 4);
    }

    const apres = rehausser({ data, width: 256, height: 1 });

    for (let i = 0; i < 256; i++) {
        assert.ok(Math.abs(apres.data[i * 4] - i) <= 3, `valeur ${i} devenue ${apres.data[i * 4]}`);
    }
});
