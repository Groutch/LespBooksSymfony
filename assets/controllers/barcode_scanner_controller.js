import { Controller } from '@hotwired/stimulus';

/**
 * Lit un code-barres EAN-13 avec la caméra.
 *
 * Chrome sur Android expose BarcodeDetector, bien plus rapide et econome ;
 * Safari/iOS ne l'implemente pas, d'ou le repli sur ZXing.
 */
export default class extends Controller {
    static targets = ['video', 'status', 'statusText', 'spinner', 'manual', 'confirmation', 'detected', 'startButton', 'stopButton'];
    static values = { checkUrl: String, newUrl: String };

    connect() {
        this.stream = null;
        this.zxingControls = null;
        this.stopped = true;
        this.detectedAt = null;
        this.resumeTimer = null;
        // Chaque arret invalide les demarrages encore en vol (voir start()).
        this.generation = 0;
        // Dernier code refuse (pas un livre) : tant qu'il reste devant l'objectif,
        // la camera le relirait a chaque reprise, message et vibration en boucle.
        this.ignoredCode = null;

        // La camera s'allume d'elle-meme : scanner a la chaine imposait un clic
        // par livre, au retour de chaque enregistrement. Pas pendant l'apercu que
        // Turbo affiche depuis son cache au retour arriere : la vraie page suit
        // aussitot, et c'est elle qui l'allume.
        if (!document.documentElement.hasAttribute('data-turbo-preview')) {
            this.start();
        }
    }

    disconnect() {
        clearTimeout(this.resumeTimer);
        this.stop();
    }

    async start() {
        clearTimeout(this.resumeTimer);
        // Jamais deux flux a la fois : un second clic, ou une reprise automatique,
        // repart d'une camera eteinte.
        this.stop();
        this.stopped = false;
        const generation = this.generation;

        this.detectedAt = null;
        this.hideConfirmation();
        this.setSpinner(false);
        this.showRunning(true);
        this.setStatus('Activation de la caméra…');

        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment' },
            });
        } catch (error) {
            if (generation === this.generation) {
                this.showRunning(false);
                this.setStatus("Caméra inaccessible. Vérifiez l'autorisation, ou saisissez l'ISBN à la main.", true);
            }
            return;
        }

        // Arrete pendant la demande d'autorisation (page quittee, second clic) :
        // ce flux arrive trop tard, il faut l'eteindre aussitot, sinon le voyant
        // de la camera reste allume sans plus aucune page pour le couper.
        if (generation !== this.generation) {
            stream.getTracks().forEach((track) => track.stop());
            return;
        }

        this.stream = stream;
        this.videoTarget.srcObject = this.stream;
        this.videoTarget.classList.remove('hidden');

        try {
            await this.videoTarget.play();
        } catch (error) {
            // Interrompu par un arret entre-temps : rien a faire.
            return;
        }

        if (generation !== this.generation) return;

        this.setStatus('Visez le code-barres au dos du livre.');

        if ('BarcodeDetector' in window) {
            await this.scanWithNativeDetector();
        } else {
            await this.scanWithZxing();
        }
    }

    async scanWithNativeDetector() {
        const detector = new window.BarcodeDetector({ formats: ['ean_13'] });

        const tick = async () => {
            if (this.stopped) return;

            try {
                const codes = await detector.detect(this.videoTarget);
                if (codes.length > 0 && this.handleCode(codes[0].rawValue)) {
                    return;
                }
            } catch (error) {
                // Une frame illisible ne doit pas interrompre le scan.
            }

            requestAnimationFrame(tick);
        };

        requestAnimationFrame(tick);
    }

    async scanWithZxing() {
        const { BrowserMultiFormatReader } = await import('@zxing/browser');
        const reader = new BrowserMultiFormatReader();

        this.zxingControls = await reader.decodeFromVideoElement(this.videoTarget, (result) => {
            if (result && !this.stopped) {
                this.handleCode(result.getText());
            }
        });
    }

    /**
     * @returns {boolean} faux si le code est ignore : la lecture continue alors.
     */
    handleCode(rawValue) {
        if (rawValue === this.ignoredCode) {
            return false;
        }

        this.ignoredCode = null;
        this.stop();
        this.detectedAt = Date.now();

        // Un retour haptique confirme la lecture sans obliger a fixer l'ecran : on
        // scanne une etagere le bras tendu. Safari/iOS ne l'expose pas, l'animation
        // y reste le seul signal.
        if (typeof navigator.vibrate === 'function') {
            navigator.vibrate(60);
        }

        this.showConfirmation(rawValue);
        this.lookup(rawValue, true);

        return true;
    }

    showConfirmation(rawValue) {
        if (this.hasDetectedTarget) {
            this.detectedTarget.textContent = rawValue;
        }

        this.videoTarget.classList.add('hidden');

        if (!this.hasConfirmationTarget) return;

        // `hidden` et `flex` se disputent la propriete display : on ne les laisse
        // jamais coexister, sinon l'ordre du CSS compile tranche a notre place.
        this.confirmationTarget.classList.remove('hidden');
        this.confirmationTarget.classList.add('flex');
    }

    hideConfirmation() {
        if (!this.hasConfirmationTarget) return;

        this.confirmationTarget.classList.remove('flex');
        this.confirmationTarget.classList.add('hidden');
    }

    /**
     * Le controle en base repond en quelques millisecondes : sans ce plancher, la
     * confirmation de lecture n'existerait que le temps d'un clignotement, et le
     * benevole conclurait que le scan a echoue. L'attente reseau s'impute dessus,
     * elle n'est donc payee que lorsque la reponse arrive plus vite que l'oeil.
     */
    async holdConfirmation() {
        if (this.detectedAt === null) return;

        const reste = 700 - (Date.now() - this.detectedAt);

        if (reste > 0) {
            await new Promise((resolve) => setTimeout(resolve, reste));
        }
    }

    /**
     * Saisir l'ISBN a la main, c'est renoncer a la camera pour ce livre : elle
     * s'eteint, et ne reprend pas seule si la saisie est invalide.
     */
    submitManual(event) {
        event.preventDefault();
        const value = this.manualTarget.value.trim();

        if (value !== '') {
            clearTimeout(this.resumeTimer);
            this.stop();
            this.showRunning(false);
            this.lookup(value, false);
        }
    }

    /**
     * Verifie seulement si le livre est deja au catalogue : une requete en base,
     * quelques millisecondes. Les metadonnees sont l'affaire du formulaire, qui
     * les cherche pendant que le benevole le decouvre. Les demander ici aussi
     * ajoutait quatre secondes d'attente pour un resultat jete.
     */
    async lookup(isbn, fromCamera) {
        const cleaned = isbn.replace(/[^0-9Xx]/g, '');
        this.setStatus('Recherche du livre…');
        this.setSpinner(true);

        try {
            const response = await fetch(this.checkUrlValue.replace('0000000000000', cleaned), {
                headers: { Accept: 'application/json' },
            });

            if (response.status === 422) {
                // Code lu correctement, mais pas un livre : etiquette de prix, code
                // magasin. En serie, la camera reprend d'elle-meme, en ignorant ce
                // code s'il reste dans le champ.
                if (fromCamera) {
                    this.ignoredCode = isbn;
                }
                this.failed("Ce code n'est pas l'ISBN d'un livre. Visez le code-barres qui commence par 978 ou 979.", fromCamera);
                return;
            }

            if (!response.ok) {
                this.failed('Vérification impossible. Réessayez dans un instant.');
                return;
            }

            const data = await response.json();

            if (data.existing) {
                this.setStatus(`Déjà au catalogue : « ${data.existing.title} ». Ouverture de sa fiche…`);
                await this.holdConfirmation();
                window.location.href = data.existing.url;
                return;
            }

            await this.holdConfirmation();
            window.location.href = `${this.newUrlValue}?isbn=${encodeURIComponent(data.isbn13 ?? cleaned)}`;
        } catch (error) {
            this.failed('Vérification impossible. Vérifiez la connexion réseau.');
        }
    }

    /**
     * Un echec rend la main : la confirmation verte disparait, sinon elle
     * contredirait le message d'erreur affiche juste en dessous. Apres un code
     * de camera qui n'etait pas un livre, la camera reprend seule une fois le
     * message lu ; apres une panne, elle attend qu'on la relance.
     */
    failed(message, resume = false) {
        this.hideConfirmation();
        this.setSpinner(false);
        this.detectedAt = null;
        this.setStatus(message, true);

        if (resume) {
            this.showRunning(true);
            this.resumeTimer = setTimeout(() => this.start(), 2500);
        } else {
            this.showRunning(false);
        }
    }

    /**
     * Couper le flux ne vide pas l'element : il conserve sa derniere frame et
     * restait affiche en cadre noir. Il faut le masquer et lui retirer sa source.
     */
    stop() {
        this.stopped = true;
        this.generation += 1;

        if (this.zxingControls) {
            this.zxingControls.stop();
            this.zxingControls = null;
        }

        if (this.stream) {
            this.stream.getTracks().forEach((track) => track.stop());
            this.stream = null;
        }

        if (this.hasVideoTarget) {
            this.videoTarget.srcObject = null;
            this.videoTarget.classList.add('hidden');
        }
    }

    /**
     * L'arret demande par le benevole, par opposition a l'arret technique que
     * declenchent une lecture reussie ou le demontage du controleur : lui seul
     * doit dire ce qui vient de se passer.
     */
    stopManually() {
        clearTimeout(this.resumeTimer);
        this.ignoredCode = null;
        this.stop();
        this.hideConfirmation();
        this.setSpinner(false);
        this.detectedAt = null;
        this.showRunning(false);
        this.setStatus("Caméra arrêtée. Réactivez-la, ou saisissez l'ISBN à la main.");
    }

    /**
     * Un seul des deux boutons a la fois. La classe `hidden` vit sur leur
     * enveloppe : `btn` pose son propre display et l'emporterait sur elle.
     */
    showRunning(running) {
        if (this.hasStartButtonTarget) {
            this.startButtonTarget.classList.toggle('hidden', running);
        }

        if (this.hasStopButtonTarget) {
            this.stopButtonTarget.classList.toggle('hidden', !running);
        }
    }

    setStatus(message, isError = false) {
        if (!this.hasStatusTextTarget) return;

        this.statusTextTarget.textContent = message;
        this.statusTarget.classList.toggle('text-danger', isError);
        this.statusTarget.classList.toggle('text-ink-muted', !isError);
    }

    setSpinner(visible) {
        if (!this.hasSpinnerTarget) return;

        this.spinnerTarget.classList.toggle('hidden', !visible);
    }
}
