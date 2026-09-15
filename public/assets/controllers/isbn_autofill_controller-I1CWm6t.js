import { Controller } from '@hotwired/stimulus';

/**
 * Complete le formulaire avec les metadonnees d'un ISBN.
 *
 * Le formulaire est affiche immediatement et rempli quand les sources externes
 * repondent (2 a 12 s) : la saisie n'est jamais bloquee par l'attente reseau.
 */
/**
 * Les trois etats du bandeau. Ils reutilisent le composant `alert` plutot que d'en
 * inventer un sixieme : seule la peinture change d'un etat a l'autre.
 */
const ETATS = {
    encours: 'border-accent-wash bg-accent-wash text-accent',
    trouve: 'alert-success',
    echec: 'alert-error',
};

export default class extends Controller {
    static values = { lookupUrl: String, isbn: String };
    static targets = ['status', 'statusText', 'spinner', 'coverUrl', 'coverPreview'];

    connect() {
        if (this.hasIsbnValue && this.isbnValue !== '') {
            this.fetchMetadata(this.isbnValue);
        }
    }

    async fetchMetadata(isbn) {
        this.setStatus('Recherche des informations du livre…', 'encours');

        try {
            const response = await fetch(this.lookupUrlValue.replace('0000000000000', isbn), {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                this.setStatus("ISBN invalide : remplissez le formulaire à la main.", 'echec');
                return;
            }

            const data = await response.json();

            if (!data.found || !data.book) {
                this.fill('isbn13', data.isbn13 ?? isbn);
                this.setStatus("Aucune source ne connaît cet ISBN : saisie manuelle nécessaire.", 'echec');
                return;
            }

            this.applyBook(data.book, data.isbn13 ?? isbn);
            this.setStatus(`Informations trouvées via ${data.book.sources.join(', ')}. Vérifiez avant d'enregistrer.`, 'trouve');
        } catch (error) {
            this.setStatus('Recherche impossible. Vérifiez la connexion réseau.', 'echec');
        }
    }

    applyBook(book, isbn13) {
        this.fill('isbn13', isbn13);
        this.fill('title', book.title);
        this.fill('subtitle', book.subtitle);
        this.fill('authorsText', book.authors);
        this.fill('categoriesText', book.categories);
        this.fill('publisher', book.publisher);
        this.fill('publishedYear', book.publishedYear);
        this.fill('pageCount', book.pageCount);
        this.fill('language', book.language);
        this.fill('isbn10', book.isbn10);
        this.fill('description', book.description);

        if (book.coverUrl && this.hasCoverUrlTarget) {
            this.coverUrlTarget.value = book.coverUrl;

            if (this.hasCoverPreviewTarget) {
                this.coverPreviewTarget.src = book.coverUrl;
                this.coverPreviewTarget.classList.remove('hidden');
            }
        }
    }

    /**
     * Ne remplit que les champs vides, pour ne jamais ecraser une saisie en cours.
     */
    fill(field, value) {
        if (value === null || value === undefined || value === '') {
            return;
        }

        const input = this.element.querySelector(`[name="book[${field}]"]`);

        if (input && input.value.trim() === '') {
            input.value = value;
        }
    }

    setStatus(message, etat = 'trouve') {
        if (!this.hasStatusTarget) return;

        this.statusTextTarget.textContent = message;

        Object.values(ETATS).forEach((classes) => this.statusTarget.classList.remove(...classes.split(' ')));
        this.statusTarget.classList.add(...ETATS[etat].split(' '));

        // Le sablier ne tourne que pendant l'attente : le laisser sur un resultat
        // ferait croire que la recherche continue.
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.toggle('hidden', etat !== 'encours');
        }
    }
}
