<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LoanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LoanRepository::class)]
#[ORM\Index(name: 'idx_loan_returned', columns: ['returned_at'])]
#[Assert\Expression(
    'this.getDueAt() === null or this.getDueAt() >= this.getBorrowedAt()',
    message: 'La date de retour prévue ne peut pas précéder la date de prêt.',
)]
class Loan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class, inversedBy: 'loans')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Book $book = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Le prénom de l\'emprunteur est obligatoire.')]
    private string $borrowerFirstName = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Le nom de l\'emprunteur est obligatoire.')]
    private string $borrowerLastName = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date de prêt est obligatoire.')]
    private ?\DateTimeImmutable $borrowedAt = null;

    /**
     * Nullable : un prêt peut être consenti sans échéance.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $returnedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->borrowedAt = new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBook(): ?Book
    {
        return $this->book;
    }

    public function setBook(?Book $book): self
    {
        $this->book = $book;

        return $this;
    }

    public function getBorrowerFirstName(): string
    {
        return $this->borrowerFirstName;
    }

    public function setBorrowerFirstName(string $borrowerFirstName): self
    {
        $this->borrowerFirstName = $borrowerFirstName;

        return $this;
    }

    public function getBorrowerLastName(): string
    {
        return $this->borrowerLastName;
    }

    public function setBorrowerLastName(string $borrowerLastName): self
    {
        $this->borrowerLastName = $borrowerLastName;

        return $this;
    }

    public function getBorrowerName(): string
    {
        return trim($this->borrowerFirstName.' '.$this->borrowerLastName);
    }

    public function getBorrowedAt(): ?\DateTimeImmutable
    {
        return $this->borrowedAt;
    }

    public function setBorrowedAt(?\DateTimeImmutable $borrowedAt): self
    {
        $this->borrowedAt = $borrowedAt;

        return $this;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function setDueAt(?\DateTimeImmutable $dueAt): self
    {
        $this->dueAt = $dueAt;

        return $this;
    }

    public function getReturnedAt(): ?\DateTimeImmutable
    {
        return $this->returnedAt;
    }

    public function setReturnedAt(?\DateTimeImmutable $returnedAt): self
    {
        $this->returnedAt = $returnedAt;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    public function isReturned(): bool
    {
        return null !== $this->returnedAt;
    }

    public function isOverdue(): bool
    {
        if ($this->isReturned() || null === $this->dueAt) {
            return false;
        }

        return $this->dueAt < new \DateTimeImmutable('today');
    }
}
