<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BookRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BookRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_book_title', columns: ['title'])]
#[UniqueEntity(fields: ['isbn13'], message: 'Ce livre est déjà présent dans la bibliothèque.')]
class Book
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    private string $title = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subtitle = null;

    #[ORM\Column(length: 13, unique: true, nullable: true)]
    #[Assert\Isbn(type: Assert\Isbn::ISBN_13, message: 'Cet ISBN-13 est invalide.')]
    private ?string $isbn13 = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $isbn10 = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $publisher = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1400, max: 2200)]
    private ?int $publishedYear = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $pageCount = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $language = null;

    /**
     * Emplacement physique du livre dans la bibliothèque.
     */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $shelfLocation = null;

    /** @var Collection<int, Author> */
    #[ORM\ManyToMany(targetEntity: Author::class, inversedBy: 'books', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'book_author')]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'author_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $authors;

    /**
     * Supprimer une catégorie ne retire que la ligne de jointure : le livre est conservé.
     *
     * @var Collection<int, Category>
     */
    #[ORM\ManyToMany(targetEntity: Category::class, inversedBy: 'books', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'book_category')]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'category_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $categories;

    #[ORM\ManyToOne(targetEntity: Genre::class, inversedBy: 'books')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Genre $genre = null;

    /** @var Collection<int, BookImage> */
    #[ORM\OneToMany(targetEntity: BookImage::class, mappedBy: 'book', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $images;

    /** @var Collection<int, Loan> */
    #[ORM\OneToMany(targetEntity: Loan::class, mappedBy: 'book', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['borrowedAt' => 'DESC'])]
    private Collection $loans;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->authors = new ArrayCollection();
        $this->categories = new ArrayCollection();
        $this->images = new ArrayCollection();
        $this->loans = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getSubtitle(): ?string
    {
        return $this->subtitle;
    }

    public function setSubtitle(?string $subtitle): self
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    public function getIsbn13(): ?string
    {
        return $this->isbn13;
    }

    public function setIsbn13(?string $isbn13): self
    {
        $this->isbn13 = $isbn13;

        return $this;
    }

    public function getIsbn10(): ?string
    {
        return $this->isbn10;
    }

    public function setIsbn10(?string $isbn10): self
    {
        $this->isbn10 = $isbn10;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getPublisher(): ?string
    {
        return $this->publisher;
    }

    public function setPublisher(?string $publisher): self
    {
        $this->publisher = $publisher;

        return $this;
    }

    public function getPublishedYear(): ?int
    {
        return $this->publishedYear;
    }

    public function setPublishedYear(?int $publishedYear): self
    {
        $this->publishedYear = $publishedYear;

        return $this;
    }

    public function getPageCount(): ?int
    {
        return $this->pageCount;
    }

    public function setPageCount(?int $pageCount): self
    {
        $this->pageCount = $pageCount;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): self
    {
        $this->language = $language;

        return $this;
    }

    public function getShelfLocation(): ?string
    {
        return $this->shelfLocation;
    }

    public function setShelfLocation(?string $shelfLocation): self
    {
        $this->shelfLocation = $shelfLocation;

        return $this;
    }

    /** @return Collection<int, Author> */
    public function getAuthors(): Collection
    {
        return $this->authors;
    }

    public function addAuthor(Author $author): self
    {
        if (!$this->authors->contains($author)) {
            $this->authors->add($author);
        }

        return $this;
    }

    public function removeAuthor(Author $author): self
    {
        $this->authors->removeElement($author);

        return $this;
    }

    public function getAuthorNames(): string
    {
        return implode(', ', $this->authors->map(static fn (Author $a): string => $a->getName())->toArray());
    }

    /** @return Collection<int, Category> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Category $category): self
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }

        return $this;
    }

    public function removeCategory(Category $category): self
    {
        $this->categories->removeElement($category);

        return $this;
    }

    public function getGenre(): ?Genre
    {
        return $this->genre;
    }

    public function setGenre(?Genre $genre): self
    {
        $this->genre = $genre;

        return $this;
    }

    /** @return Collection<int, BookImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(BookImage $image): self
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->setBook($this);
        }

        return $this;
    }

    public function removeImage(BookImage $image): self
    {
        $this->images->removeElement($image);

        return $this;
    }

    public function getPrimaryImage(): ?BookImage
    {
        foreach ($this->images as $image) {
            if ($image->isPrimary()) {
                return $image;
            }
        }

        return $this->images->first() ?: null;
    }

    /** @return Collection<int, Loan> */
    public function getLoans(): Collection
    {
        return $this->loans;
    }

    public function addLoan(Loan $loan): self
    {
        if (!$this->loans->contains($loan)) {
            $this->loans->add($loan);
            $loan->setBook($this);
        }

        return $this;
    }

    public function removeLoan(Loan $loan): self
    {
        $this->loans->removeElement($loan);

        return $this;
    }

    public function getActiveLoan(): ?Loan
    {
        foreach ($this->loans as $loan) {
            if (!$loan->isReturned()) {
                return $loan;
            }
        }

        return null;
    }

    public function isAvailable(): bool
    {
        return null === $this->getActiveLoan();
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
