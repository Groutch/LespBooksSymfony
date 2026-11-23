/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { cadreParDefaut, detecterCouverture, dimensionsRedressees, redresser, rehausser } from '../lib/couverture.js';

/** Photo ramenee a 2000 px : assez pour une couverture nette, supportable en memoire au telephone. */
const COTE_PHOTO = 2000;
/**
 * La detection travaille sur une vignette d'environ 150 000 pixels, quelle que
 * soit la forme de la photo. Limitee par son plus grand cote (320 px), une photo
 * haute tombait a 150 px de large : le livre n'y faisait plus que 90 px, et ses
 * bords pesaient moins que les cables poses a cote (essai sur une vraie photo).
 */
const PIXELS_DETECTION = 150_000;
/** Couverture enregistree : 1600 px au plus, loin des 8 Mo admis par le formulaire. */
const COTE_SORTIE = 1600;
/** Rayon des poignees a l'ecran, et distance a laquelle un doigt les attrape (44 px, cible tactile). */
const RAYON_POIGNEE = 14;
const PORTEE_DOIGT = 44;
/** Loupe au-dessus du doigt : taille a l'ecran (128 px, comme size-32) et grossissement. */
const TAILLE_LOUPE = 128;
const GROSSISSEMENT_LOUPE = 3;

/**
 * Photographier une couverture comme on numerise un document : un viseur dans
 * la page, puis un ecran de recadrage ou le cadre se pose seul sur le livre
 * (assets/lib/couverture.js), quatre poignees pour l'ajuster, et une image
 * redressee a plat qui remplace le fichier du formulaire.
 *
 * Le viseur est dans la page plutot que l'appareil photo du systeme : celui-ci
 * impose apres chaque prise un ecran « Reessayer / OK » qu'aucune page ne peut
 * sauter, une etape de trop a chaque livre. Il reste le recours quand la camera
 * est inaccessible ; « Choisir une image » passe par le meme recadrage.
 *
 * Le champ fichier d'origine reste celui qui part avec le formulaire : sans
 * JavaScript, il s'affiche et suffit ; avec, il est masque et recoit la
 * couverture redressee. Rien ne change cote serveur.
 */
export default class extends Controller {
    static targets = [
        'input', 'actions', 'camera', 'gallery', 'result', 'message', 'editor',
        'viseur', 'video', 'viseurStatus', 'shutter', 'natif',
        'recadreur', 'canvas', 'overlay', 'polygon', 'handle', 'loupe', 'status', 'validate',
    ];

    connect() {
        this.inputTarget.classList.add('hidden');
        this.actionsTarget.classList.remove('hidden');
        this.saisi = null;
        this.flux = null;
        // Chaque extinction invalide les allumages encore en vol : un flux arrive
        // apres coup est coupe aussitot, sans quoi le voyant resterait allume.
        this.generation = 0;
    }

    disconnect() {
        this.eteindreCamera();
        if (this.apercu) URL.revokeObjectURL(this.apercu);
    }

    // --- Prise de vue ----------------------------------------------------------

    async photographier() {
        this.origine = 'viseur';

        if (!navigator.mediaDevices?.getUserMedia) {
            this.appareilNatif();
            return;
        }

        this.afficherVolet('viseur');
        if (!this.editorTarget.open) this.editorTarget.showModal();
        await this.allumerCamera();
    }

    async allumerCamera() {
        this.eteindreCamera();
        const generation = this.generation;
        this.natifTarget.classList.add('hidden');
        this.shutterTarget.disabled = true;
        this.viseurStatusTarget.textContent = 'Activation de la caméra…';

        let flux;
        try {
            flux = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment', width: { ideal: 3840 }, height: { ideal: 2160 } },
                audio: false,
            });
        } catch (erreur) {
            if (generation === this.generation) {
                this.viseurStatusTarget.textContent = "Caméra inaccessible : passez par l'appareil photo du téléphone.";
                this.natifTarget.classList.remove('hidden');
            }
            return;
        }

        if (generation !== this.generation || !this.editorTarget.open) {
            flux.getTracks().forEach((piste) => piste.stop());
            return;
        }

        this.flux = flux;
        this.videoTarget.srcObject = flux;
        try {
            await this.videoTarget.play();
        } catch (erreur) {
            return;
        }

        if (generation !== this.generation) return;

        this.shutterTarget.disabled = false;
        this.viseurStatusTarget.textContent = 'Cadrez la couverture, puis déclenchez.';
    }

    eteindreCamera() {
        this.generation += 1;
        this.flux?.getTracks().forEach((piste) => piste.stop());
        this.flux = null;
        if (this.hasVideoTarget) this.videoTarget.srcObject = null;
    }

    /**
     * Fige l'image du flux, telle qu'on la voit : son orientation est toujours
     * la bonne, ce que la prise de photo pleine resolution (ImageCapture) ne
     * garantit pas sur tous les Android.
     */
    declencher() {
        if (!this.flux || this.videoTarget.videoWidth === 0) return;

        this.photo = this.versCanvas(this.videoTarget, COTE_PHOTO);
        this.eteindreCamera();

        if (typeof navigator.vibrate === 'function') {
            navigator.vibrate(40);
        }

        this.ouvrirRecadrage();
    }

    /** Recours : l'appareil photo du systeme, avec son ecran de confirmation. */
    appareilNatif() {
        this.origine = 'camera';
        if (this.editorTarget.open) this.editorTarget.close();
        this.cameraTarget.click();
    }

    choisir() {
        this.origine = 'galerie';
        this.galleryTarget.click();
    }

    async charger(event) {
        const fichier = event.target.files?.[0];
        // Vide le champ : reprendre la meme photo doit redeclencher `change`.
        event.target.value = '';
        if (!fichier) return;

        let image;
        try {
            image = await this.decoder(fichier);
        } catch (erreur) {
            this.signaler("Cette image n'a pas pu être ouverte. Reprenez la photo, ou choisissez-en une autre.");
            return;
        }

        this.signaler('');
        this.photo = this.versCanvas(image, COTE_PHOTO);
        this.ouvrirRecadrage();
    }

    decoder(fichier) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(fichier);
            const image = new Image();
            image.onload = () => {
                URL.revokeObjectURL(url);
                resolve(image);
            };
            image.onerror = () => {
                URL.revokeObjectURL(url);
                reject(new Error('Image illisible'));
            };
            image.src = url;
        });
    }

    /**
     * Copie reduite dans un canvas, d'une image ou d'une video. Le navigateur y
     * applique deja l'orientation EXIF : une photo prise en portrait ne revient
     * pas couchee.
     */
    versCanvas(source, coteMax) {
        const largeur = source.naturalWidth || source.videoWidth || source.width;
        const hauteur = source.naturalHeight || source.videoHeight || source.height;
        const facteur = Math.min(1, coteMax / Math.max(largeur, hauteur));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(largeur * facteur));
        canvas.height = Math.max(1, Math.round(hauteur * facteur));
        canvas.getContext('2d', { willReadFrequently: true }).drawImage(source, 0, 0, canvas.width, canvas.height);

        return canvas;
    }

    // --- Recadrage ---------------------------------------------------------------

    ouvrirRecadrage() {
        this.saisi = null;
        this.loupeTarget.classList.add('hidden');
        this.afficherVolet('recadreur');
        if (!this.editorTarget.open) this.editorTarget.showModal();
        this.poserCadre();
        this.dessiner();
    }

    afficherVolet(volet) {
        this.viseurTarget.classList.toggle('hidden', volet !== 'viseur');
        this.recadreurTarget.classList.toggle('hidden', volet !== 'recadreur');
    }

    poserCadre() {
        const surface = this.photo.width * this.photo.height;
        const cote = Math.max(this.photo.width, this.photo.height) * Math.min(1, Math.sqrt(PIXELS_DETECTION / surface));
        const vignette = this.versCanvas(this.photo, cote);
        const pixels = vignette.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, vignette.width, vignette.height);
        const trouves = detecterCouverture(pixels);
        const facteur = this.photo.width / vignette.width;

        this.coins = trouves
            ? trouves.map(({ x, y }) => ({ x: x * facteur, y: y * facteur }))
            : cadreParDefaut(this.photo.width, this.photo.height);

        this.statusTarget.textContent = trouves
            ? 'Ajustez les coins si besoin, puis validez.'
            : "Couverture non repérée : placez les quatre coins sur ses angles.";
    }

    dessiner() {
        const { width, height } = this.photo;
        this.canvasTarget.width = width;
        this.canvasTarget.height = height;
        this.canvasTarget.getContext('2d').drawImage(this.photo, 0, 0);
        this.overlayTarget.setAttribute('viewBox', `0 0 ${width} ${height}`);
        this.dessinerCadre();
    }

    /** Appele aussi au redimensionnement : les poignees gardent leur taille a l'ecran. */
    dessinerCadre() {
        if (!this.photo || !this.editorTarget.open || this.recadreurTarget.classList.contains('hidden')) return;

        const echelle = this.echelle();
        this.polygonTarget.setAttribute('points', this.coins.map(({ x, y }) => `${x},${y}`).join(' '));
        this.polygonTarget.setAttribute('stroke-width', String(3 * echelle));

        this.handleTargets.forEach((poignee, k) => {
            poignee.setAttribute('cx', String(this.coins[k].x));
            poignee.setAttribute('cy', String(this.coins[k].y));
            poignee.setAttribute('r', String(RAYON_POIGNEE * echelle));
            poignee.setAttribute('stroke-width', String(3 * echelle));
        });
    }

    /** Pixels de photo par pixel d'ecran. */
    echelle() {
        const boite = this.overlayTarget.getBoundingClientRect();

        return boite.width > 0 ? this.photo.width / boite.width : 1;
    }

    pointPhoto(event) {
        const boite = this.overlayTarget.getBoundingClientRect();

        return {
            x: Math.min(Math.max((event.clientX - boite.left) * (this.photo.width / boite.width), 0), this.photo.width),
            y: Math.min(Math.max((event.clientY - boite.top) * (this.photo.height / boite.height), 0), this.photo.height),
        };
    }

    /** Le doigt attrape le coin le plus proche, s'il est a portee. */
    saisir(event) {
        const point = this.pointPhoto(event);
        let proche = null;
        let meilleur = PORTEE_DOIGT * this.echelle();

        this.coins.forEach((coin, k) => {
            const d = Math.hypot(coin.x - point.x, coin.y - point.y);
            if (d <= meilleur) {
                meilleur = d;
                proche = k;
            }
        });

        if (proche === null) return;

        this.saisi = proche;
        this.overlayTarget.setPointerCapture(event.pointerId);
        this.loupeTarget.classList.remove('hidden');
        this.dessinerLoupe();
        event.preventDefault();
    }

    deplacer(event) {
        if (this.saisi === null) return;

        this.coins[this.saisi] = this.pointPhoto(event);
        this.dessinerCadre();
        this.dessinerLoupe();
    }

    lacher() {
        this.saisi = null;
        this.loupeTarget.classList.add('hidden');
    }

    /**
     * Appui long sur une poignee : sans cela, le telephone selectionne le texte
     * de la page derriere, et son menu de copie masque la loupe.
     */
    bloquer(event) {
        event.preventDefault();
    }

    /**
     * Loupe au-dessus du doigt, comme celle de l'appareil photo : la zone de la
     * photo autour du coin saisi, grossie, avec le cadre et un repere au centre.
     * Placee du cote oppose a la poignee, pour que le doigt ne la cache pas.
     */
    dessinerLoupe() {
        if (this.saisi === null) return;

        const boite = this.overlayTarget.getBoundingClientRect();
        const coin = this.coins[this.saisi];
        const marge = 8;
        const surEcran = {
            x: (coin.x / this.photo.width) * boite.width,
            y: (coin.y / this.photo.height) * boite.height,
        };
        const haut = surEcran.y > boite.height / 2 ? marge : boite.height - TAILLE_LOUPE - marge;
        const gauche = Math.min(Math.max(surEcran.x - TAILLE_LOUPE / 2, marge), boite.width - TAILLE_LOUPE - marge);
        this.loupeTarget.style.top = `${haut}px`;
        this.loupeTarget.style.left = `${gauche}px`;

        // Taille de la zone copiee, en pixels de photo. Le canvas a le double de sa
        // taille affichee, pour rester net sur les ecrans haute densite.
        const cote = (TAILLE_LOUPE / GROSSISSEMENT_LOUPE) * this.echelle();
        if (this.loupeTarget.width !== 2 * TAILLE_LOUPE) {
            this.loupeTarget.width = 2 * TAILLE_LOUPE;
            this.loupeTarget.height = 2 * TAILLE_LOUPE;
        }
        const taille = this.loupeTarget.width;
        const k = taille / cote;
        const sx = coin.x - cote / 2;
        const sy = coin.y - cote / 2;

        const contexte = this.loupeTarget.getContext('2d');
        contexte.clearRect(0, 0, taille, taille);
        contexte.drawImage(this.photo, sx, sy, cote, cote, 0, 0, taille, taille);

        // Le cadre passe dans la loupe, pour voir l'arete qu'on vise.
        contexte.beginPath();
        this.coins.forEach(({ x, y }, i) => {
            const px = (x - sx) * k;
            const py = (y - sy) * k;
            if (i === 0) contexte.moveTo(px, py);
            else contexte.lineTo(px, py);
        });
        contexte.closePath();
        contexte.lineWidth = 3;
        contexte.strokeStyle = '#f7f3ec';
        contexte.stroke();

        // Repere au centre : le coin saisi se trouve la, sous le doigt.
        const centre = taille / 2;
        const bras = 14;
        contexte.beginPath();
        contexte.moveTo(centre - bras, centre);
        contexte.lineTo(centre + bras, centre);
        contexte.moveTo(centre, centre - bras);
        contexte.lineTo(centre, centre + bras);
        contexte.lineWidth = 2;
        contexte.strokeStyle = '#f7f3ec';
        contexte.stroke();
    }

    /** Un quart de tour : la detection reprend sur la photo tournee. */
    tourner({ params: { sens } }) {
        const { width, height } = this.photo;
        const canvas = document.createElement('canvas');
        canvas.width = height;
        canvas.height = width;

        const contexte = canvas.getContext('2d', { willReadFrequently: true });
        contexte.translate(canvas.width / 2, canvas.height / 2);
        contexte.rotate((sens * Math.PI) / 2);
        contexte.drawImage(this.photo, -width / 2, -height / 2);

        this.photo = canvas;
        this.poserCadre();
        this.dessiner();
    }

    /** Retour au viseur, ou au selecteur d'ou venait la photo. */
    reprendre() {
        if (this.origine === 'viseur') {
            this.afficherVolet('viseur');
            this.allumerCamera();
            return;
        }

        this.editorTarget.close();
        (this.origine === 'galerie' ? this.galleryTarget : this.cameraTarget).click();
    }

    annuler() {
        this.editorTarget.close();
    }

    /** Fermeture, quelle qu'en soit la cause (Annuler, touche retour) : la camera s'eteint. */
    ferme() {
        this.eteindreCamera();
    }

    async valider() {
        this.validateTarget.disabled = true;
        this.statusTarget.textContent = 'Redressement de la couverture…';
        // Laisse le message s'afficher : le calcul qui suit occupe le fil principal.
        await new Promise((resolve) => requestAnimationFrame(() => setTimeout(resolve, 0)));

        try {
            const source = this.photo.getContext('2d', { willReadFrequently: true })
                .getImageData(0, 0, this.photo.width, this.photo.height);
            // Le centre de la photo sert de centre optique : il permet de retrouver
            // les vraies proportions d'un livre photographie en biais.
            const centre = { x: this.photo.width / 2, y: this.photo.height / 2 };
            const { largeur, hauteur } = dimensionsRedressees(this.coins, COTE_SORTIE, centre);
            const couverture = rehausser(redresser(source, this.coins, largeur, hauteur));

            const canvas = document.createElement('canvas');
            canvas.width = largeur;
            canvas.height = hauteur;
            canvas.getContext('2d').putImageData(new ImageData(couverture.data, largeur, hauteur), 0, 0);

            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.88));
            this.deposer(new File([blob], 'couverture.jpg', { type: 'image/jpeg' }));
            this.montrer(blob);
            this.editorTarget.close();
            // La couverture proposee par la recherche ISBN n'a plus lieu d'etre.
            this.dispatch('remplacee');
        } catch (erreur) {
            this.editorTarget.close();
            // Navigateur trop ancien pour garnir un champ fichier : on rend le
            // champ d'origine, qui fonctionne toujours.
            this.inputTarget.classList.remove('hidden');
            this.signaler("Le redressement n'a pas abouti sur ce navigateur : choisissez la photo avec le champ ci-dessus.");
        } finally {
            this.validateTarget.disabled = false;
        }
    }

    /** Le champ d'origine recoit la couverture redressee : c'est lui que le formulaire envoie. */
    deposer(fichier) {
        const transfert = new DataTransfer();
        transfert.items.add(fichier);
        this.inputTarget.files = transfert.files;
    }

    montrer(blob) {
        if (this.apercu) URL.revokeObjectURL(this.apercu);
        this.apercu = URL.createObjectURL(blob);
        this.resultTarget.src = this.apercu;
        this.resultTarget.classList.remove('hidden');
    }

    signaler(message) {
        this.messageTarget.textContent = message;
        this.messageTarget.classList.toggle('hidden', message === '');
    }
}
