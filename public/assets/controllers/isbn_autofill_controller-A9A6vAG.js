import { Controller } from '@hotwired/stimulus';

/**
 * Complete le formulaire avec les metadonnees d'un ISBN.
 *
 * Le formulaire est affiche immediatement et rempli quand les sources externes
 * repondent (2 a 12 s) : la saisie n'est jamais bloquee par l'attente reseau.
 */
export default class extends Controller {
    static values = { lookupUrl: String, isbn: String };
    static targets = ['status', 'coverUrl', 'coverPreview'];

    connect() {
        if (this.hasIsbnValue && this.isbnValue !== '') {
            this.fetchMetadata(this.isbnValue);
        }
    }

    async fetchMetadata(isbn) {
        this.setStatus('Recherche des informations du livre…');

        try {
            const response = await fetch(this.lookupUrlValue.replace('0000000000000', isbn), {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                this.setStatus("ISBN invalide : remplissez le formulaire à la main.", true);
                return;
            }

            const data = await response.json();

            if (!data.found || !data.book) {
                this.fill('isbn13', data.isbn13 ?? isbn);
                this.setStatus("Aucune source ne connaît cet ISBN : saisie manuelle nécessaire.", true);
                return;
            }

            this.applyBook(data.book, data.isbn13 ?? isbn);
            this.setStatus(`Informations trouvées via ${data.book.sources.join(', ')}. Vérifiez avant d'enregistrer.`);
        } catch (error) {
            this.setStatus('Recherche impossible. Vérifiez la connexion réseau.', true);
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

    setStatus(message, isError = false) {
        if (!this.hasStatusTarget) return;

        this.statusTarget.textContent = message;
        this.statusTarget.classList.toggle('text-red-600', isError);
        this.statusTarget.classList.toggle('text-slate-600', !isError);
    }
}
