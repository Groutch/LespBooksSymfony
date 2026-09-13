import { Controller } from '@hotwired/stimulus';

/**
 * Enregistre le service worker et propose d'installer l'administration.
 *
 * Les deux plateformes ne se comportent pas du tout pareil :
 *
 * - Android/Chrome emet `beforeinstallprompt`, qu'on peut retenir pour declencher
 *   l'invite native au moment choisi. Sans `preventDefault`, Chrome affiche sa
 *   propre banniere, moins visible.
 * - iOS/Safari n'expose AUCUNE API d'installation. Le seul chemin est
 *   Partager -> « Sur l'ecran d'accueil », qu'il faut donc expliquer, sans quoi
 *   la moitie des benevoles n'installera jamais rien.
 */
export default class extends Controller {
    static targets = ['banniere', 'actionAndroid', 'consigneIos'];
    static values = { swUrl: String, cle: { type: String, default: 'pwa-invite-rejetee' } };

    connect() {
        this.invite = null;
        this.enregistrerServiceWorker();

        if (this.dejaInstallee() || this.rejetMemorise()) {
            return;
        }

        // Ne se declenche que sur les navigateurs qui savent installer.
        this.surInvite = (event) => {
            event.preventDefault();
            this.invite = event;
            this.afficher(this.hasActionAndroidTarget ? this.actionAndroidTarget : null);
        };
        window.addEventListener('beforeinstallprompt', this.surInvite);

        if (this.estIos()) {
            this.afficher(this.hasConsigneIosTarget ? this.consigneIosTarget : null);
        }
    }

    disconnect() {
        if (this.surInvite) {
            window.removeEventListener('beforeinstallprompt', this.surInvite);
        }
    }

    async enregistrerServiceWorker() {
        if (!('serviceWorker' in navigator) || !this.hasSwUrlValue) {
            return;
        }

        try {
            await navigator.serviceWorker.register(this.swUrlValue);
        } catch (error) {
            // Un service worker indisponible ne doit jamais empecher de cataloguer.
        }
    }

    async installer() {
        if (!this.invite) {
            return;
        }

        this.invite.prompt();
        await this.invite.userChoice;
        this.invite = null;
        this.masquer();
    }

    /** Le refus est memorise : reproposer a chaque visite serait harcelant. */
    rejeter() {
        try {
            window.localStorage.setItem(this.cleValue, '1');
        } catch (error) {
            // Navigation privee : le bandeau reviendra, c'est sans gravite.
        }

        this.masquer();
    }

    /**
     * On bascule la classe `hidden` et non l'attribut : l'attribut est inoperant
     * des qu'un utilitaire Tailwind pose un `display` sur l'element, ce que fait
     * `btn` avec `inline-flex`.
     */
    afficher(bloc) {
        if (!this.hasBanniereTarget) {
            return;
        }

        if (bloc) {
            bloc.classList.remove('hidden');
        }
        this.banniereTarget.classList.remove('hidden');
    }

    masquer() {
        if (this.hasBanniereTarget) {
            this.banniereTarget.classList.add('hidden');
        }
    }

    /** Lancee depuis l'ecran d'accueil : il n'y a plus rien a proposer. */
    dejaInstallee() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
    }

    estIos() {
        return /iphone|ipad|ipod/i.test(window.navigator.userAgent);
    }

    rejetMemorise() {
        try {
            return window.localStorage.getItem(this.cleValue) === '1';
        } catch (error) {
            return false;
        }
    }
}
