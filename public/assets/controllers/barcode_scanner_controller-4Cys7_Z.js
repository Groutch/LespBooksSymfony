import { Controller } from '@hotwired/stimulus';

/**
 * Lit un code-barres EAN-13 avec la caméra.
 *
 * Chrome sur Android expose BarcodeDetector, bien plus rapide et econome ;
 * Safari/iOS ne l'implemente pas, d'ou le repli sur ZXing.
 */
export default class extends Controller {
    static targets = ['video', 'status', 'statusText', 'spinner', 'manual', 'confirmation', 'detected'];
    static values = { checkUrl: String, newUrl: String };

    connect() {
        this.stream = null;
        this.zxingControls = null;
        this.stopped = false;
        this.detectedAt = null;
    }

    disconnect() {
        this.stop();
    }

    async start() {
        this.stopped = false;
        this.detectedAt = null;
        this.hideConfirmation();
        this.setSpinner(false);
        this.setStatus('Activation de la caméra…');

        try {
            this.stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment' },
            });
        } catch (error) {
            this.setStatus("Caméra inaccessible. Vérifiez l'autorisation, ou saisissez l'ISBN à la main.", true);
            return;
        }

        this.videoTarget.srcObject = this.stream;
        this.videoTarget.classList.remove('hidden');
        await this.videoTarget.play();

        if ('BarcodeDetector' in window) {
            this.setStatus('Visez le code-barres au dos du livre.');
            await this.scanWithNativeDetector();
        } else {
            this.setStatus('Visez le code-barres au dos du livre.');
            await this.scanWithZxing();
        }
    }

    async scanWithNativeDetector() {
        const detector = new window.BarcodeDetector({ formats: ['ean_13'] });

        const tick = async () => {
            if (this.stopped) return;

            try {
                const codes = await detector.detect(this.videoTarget);
                if (codes.length > 0) {
                    this.handleCode(codes[0].rawValue);
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

    handleCode(rawValue) {
        this.stop();
        this.detectedAt = Date.now();

        // Un retour haptique confirme la lecture sans obliger a fixer l'ecran : on
        // scanne une etagere le bras tendu. Safari/iOS ne l'expose pas, l'animation
        // y reste le seul signal.
        if (typeof navigator.vibrate === 'function') {
            navigator.vibrate(60);
        }

        this.showConfirmation(rawValue);
        this.lookup(rawValue);
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

    submitManual(event) {
        event.preventDefault();
        const value = this.manualTarget.value.trim();

        if (value !== '') {
            this.lookup(value);
        }
    }

    /**
     * Verifie seulement si le livre est deja au catalogue : une requete en base,
     * quelques millisecondes. Les metadonnees sont l'affaire du formulaire, qui
     * les cherche pendant que le benevole le decouvre. Les demander ici aussi
     * ajoutait quatre secondes d'attente pour un resultat jete.
     */
    async lookup(isbn) {
        const cleaned = isbn.replace(/[^0-9Xx]/g, '');
        this.setStatus('Recherche du livre…');
        this.setSpinner(true);

        try {
            const response = await fetch(this.checkUrlValue.replace('0000000000000', cleaned), {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                this.failed("Ce code ne correspond pas à un ISBN de livre valide.");
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
     * contredirait le message d'erreur affiche juste en dessous.
     */
    failed(message) {
        this.hideConfirmation();
        this.setSpinner(false);
        this.detectedAt = null;
        this.setStatus(message, true);
    }

    /**
     * Couper le flux ne vide pas l'element : il conserve sa derniere frame et
     * restait affiche en cadre noir. Il faut le masquer et lui retirer sa source.
     */
    stop() {
        this.stopped = true;

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
        this.stop();
        this.hideConfirmation();
        this.setSpinner(false);
        this.detectedAt = null;
        this.setStatus("Caméra arrêtée. Réactivez-la, ou saisissez l'ISBN à la main.");
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
