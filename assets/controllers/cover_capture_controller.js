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

/**
 * Photographier une couverture comme on numerise un document : l'appareil photo
 * du telephone, puis un ecran de recadrage ou le cadre se pose seul sur le livre
 * (assets/lib/couverture.js), quatre poignees pour l'ajuster, et une image
 * redressee a plat qui remplace le fichier du formulaire.
 *
 * Le champ fichier d'origine reste celui qui part avec le formulaire : sans
 * JavaScript, il s'affiche et suffit ; avec, il est masque et recoit la
 * couverture redressee. Rien ne change cote serveur.
 */
export default class extends Controller {
    static targets = ['input', 'actions', 'camera', 'gallery', 'result', 'message', 'editor', 'canvas', 'overlay', 'polygon', 'handle', 'status', 'validate'];

    connect() {
        this.inputTarget.classList.add('hidden');
        this.actionsTarget.classList.remove('hidden');
        this.saisi = null;
    }

    disconnect() {
        if (this.apercu) URL.revokeObjectURL(this.apercu);
    }

    /**
     * `capture` ouvre directement l'appareil photo : la photo revient a la page
     * sans passer par la galerie du telephone.
     */
    photographier() {
        this.origine = this.cameraTarget;
        this.cameraTarget.click();
    }

    choisir() {
        this.origine = this.galleryTarget;
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
        } catch (error) {
            this.signaler("Cette image n'a pas pu être ouverte. Reprenez la photo, ou choisissez-en une autre.");
            return;
        }

        this.signaler('');
        this.photo = this.versCanvas(image, COTE_PHOTO);
        this.poserCadre();
        this.editorTarget.showModal();
        this.dessiner();
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
     * Copie reduite dans un canvas. Le navigateur y applique deja l'orientation
     * EXIF : une photo prise en portrait ne revient pas couchee.
     */
    versCanvas(source, coteMax) {
        const largeur = source.naturalWidth ?? source.width;
        const hauteur = source.naturalHeight ?? source.height;
        const facteur = Math.min(1, coteMax / Math.max(largeur, hauteur));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(largeur * facteur));
        canvas.height = Math.max(1, Math.round(hauteur * facteur));
        canvas.getContext('2d', { willReadFrequently: true }).drawImage(source, 0, 0, canvas.width, canvas.height);

        return canvas;
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
        if (!this.photo || !this.editorTarget.open) return;

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
        event.preventDefault();
    }

    deplacer(event) {
        if (this.saisi === null) return;

        this.coins[this.saisi] = this.pointPhoto(event);
        this.dessinerCadre();
    }

    lacher() {
        this.saisi = null;
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

    reprendre() {
        this.editorTarget.close();
        (this.origine ?? this.cameraTarget).click();
    }

    annuler() {
        this.editorTarget.close();
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
        } catch (error) {
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
