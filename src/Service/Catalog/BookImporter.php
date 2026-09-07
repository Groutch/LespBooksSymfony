<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\Book;
use App\Entity\BookImage;
use App\Entity\User;
use App\Service\Metadata\BookMetadata;

/**
 * Applique des métadonnées externes à un livre, en réutilisant les auteurs et
 * catégories déjà présents plutôt que d'en créer des doublons.
 */
class BookImporter
{
    public function __construct(
        private readonly AuthorResolver $authorResolver,
        private readonly CategoryResolver $categoryResolver,
        private readonly CoverDownloader $coverDownloader,
    ) {
    }

    public function createFromMetadata(BookMetadata $metadata, ?User $createdBy = null): Book
    {
        $book = new Book();
        $book->setCreatedBy($createdBy);

        $this->apply($book, $metadata);

        return $book;
    }

    /**
     * Ne remplit que les champs encore vides : une saisie manuelle de l'utilisateur
     * n'est jamais écrasée par une source externe.
     */
    public function apply(Book $book, BookMetadata $metadata, bool $withCover = true): void
    {
        if ('' === $book->getTitle() && null !== $metadata->title) {
            $book->setTitle($metadata->title);
        }

        $book->setSubtitle($book->getSubtitle() ?? $metadata->subtitle);
        $book->setIsbn13($book->getIsbn13() ?? $metadata->isbn13);
        $book->setIsbn10($book->getIsbn10() ?? $metadata->isbn10);
        $book->setDescription($book->getDescription() ?? $metadata->description);
        $book->setPublisher($book->getPublisher() ?? $metadata->publisher);
        $book->setPublishedYear($book->getPublishedYear() ?? $metadata->publishedYear);
        $book->setPageCount($book->getPageCount() ?? $metadata->pageCount);
        $book->setLanguage($book->getLanguage() ?? $metadata->language);

        if ($book->getAuthors()->isEmpty()) {
            foreach ($this->authorResolver->resolveAll($metadata->authors) as $author) {
                $book->addAuthor($author);
            }
        }

        if ($book->getCategories()->isEmpty()) {
            foreach ($this->categoryResolver->resolveAll($metadata->categories) as $category) {
                $book->addCategory($category);
            }
        }

        if ($withCover && null !== $metadata->coverUrl && $book->getImages()->isEmpty()) {
            $this->attachCover($book, $metadata->coverUrl);
        }
    }

    private function attachCover(Book $book, string $coverUrl): void
    {
        $file = $this->coverDownloader->download($coverUrl, $book->getIsbn13() ?? 'livre');

        if (null === $file) {
            return;
        }

        $image = (new BookImage())
            ->setPrimary(true)
            ->setPosition(0)
            ->setSourceUrl($coverUrl);

        $image->setImageFile($file);
        $book->addImage($image);
    }
}
