<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fusionne deux catégories jugées équivalentes sans jamais perdre un livre :
 * les livres de la source sont rattachés à la cible avant sa suppression.
 */
class CategoryMerger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return int Nombre de livres nouvellement rattachés à la cible
     */
    public function merge(Category $source, Category $target): int
    {
        if ($source === $target) {
            throw new \InvalidArgumentException('Une catégorie ne peut pas être fusionnée avec elle-même.');
        }

        $moved = 0;

        foreach ($source->getBooks()->toArray() as $book) {
            $book->removeCategory($source);

            if (!$book->getCategories()->contains($target)) {
                $book->addCategory($target);
                ++$moved;
            }
        }

        $this->entityManager->remove($source);
        $this->entityManager->flush();

        return $moved;
    }
}
