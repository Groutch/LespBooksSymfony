import { Controller } from '@hotwired/stimulus';

/**
 * Lit un code-barres EAN-13 avec la caméra.
 *
 * Chrome sur Android expose BarcodeDetector, bien plus rapide et econome ;
 * Safari/iOS ne l'implemente pas, d'ou le repli sur ZXing.
 */
export default class extends Controller {
    static targets = ['video', 'status', 'manual'];
    static values = { lookupUrl: String, newUrl: String };

    connect() {
        this.stream = null;
        this.zxingControls = null;
        this.stopped = false;
    }

    disconnect() {
        this.stop();
    }

    async start() {
        this.stopped = false;
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
        this.setStatus(`Code détecté : ${rawValue}. Recherche en cours…`);
        this.lookup(rawValue);
    }

    submitManual(event) {
        event.preventDefault();
        const value = this.manualTarget.value.trim();

        if (value !== '') {
            this.setStatus('Recherche en cours…');
            this.lookup(value);
        }
    }

    async lookup(isbn) {
        const cleaned = isbn.replace(/[^0-9Xx]/g, '');

        try {
            const response = await fetch(this.lookupUrlValue.replace('0000000000000', cleaned), {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                this.setStatus("Ce code ne correspond pas à un ISBN de livre valide.", true);
                return;
            }

            const data = await response.json();

            if (data.existing) {
                this.setStatus(`Déjà au catalogue : « ${data.existing.title} ». Ouverture de sa fiche…`);
                window.location.href = data.existing.url;
                return;
            }

            window.location.href = `${this.newUrlValue}?isbn=${encodeURIComponent(data.isbn13 ?? cleaned)}`;
        } catch (error) {
            this.setStatus('Recherche impossible. Vérifiez la connexion réseau.', true);
        }
    }

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
    }

    setStatus(message, isError = false) {
        this.statusTarget.textContent = message;
        this.statusTarget.classList.toggle('text-red-600', isError);
        this.statusTarget.classList.toggle('text-slate-600', !isError);
    }
}
