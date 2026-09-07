<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Book;
use App\Entity\BookImage;
use App\Entity\User;
use App\Form\BookType;
use App\Model\BookSearchCriteria;
use App\Repository\BookRepository;
use App\Repository\CategoryRepository;
use App\Service\Catalog\AuthorResolver;
use App\Service\Catalog\BookImporter;
use App\Service\Catalog\CategoryResolver;
use App\Service\IsbnNormalizer;
use App\Service\Metadata\BookMetadata;
use App\Service\Metadata\MetadataFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/livres')]
#[IsGranted('ROLE_ADMIN')]
class BookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthorResolver $authorResolver,
        private readonly CategoryResolver $categoryResolver,
    ) {
    }

    #[Route('', name: 'admin_book_index', methods: ['GET'])]
    public function index(
        BookRepository $bookRepository,
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $criteria = new BookSearchCriteria(query: $q, page: $page);
        $paginator = $bookRepository->search($criteria);

        return $this->render('admin/book/index.html.twig', [
            'books' => $paginator,
            'total' => \count($paginator),
            'criteria' => $criteria,
            'pageCount' => (int) ceil(\count($paginator) / BookSearchCriteria::PER_PAGE),
        ]);
    }

    #[Route('/scanner', name: 'admin_book_scan', methods: ['GET'])]
    public function scan(): Response
    {
        return $this->render('admin/book/scan.html.twig');
    }

    /**
     * Interrogée en arrière-plan par le formulaire : l'écran s'affiche
     * immédiatement et se complète quand les sources externes répondent.
     */
    #[Route('/recherche-isbn/{isbn}', name: 'admin_book_lookup', requirements: ['isbn' => '[0-9Xx\-]{10,17}'], methods: ['GET'])]
    public function lookup(
        string $isbn,
        MetadataFetcher $fetcher,
        IsbnNormalizer $isbnNormalizer,
        BookRepository $bookRepository,
    ): JsonResponse {
        $isbn13 = $isbnNormalizer->normalize($isbn);

        if (null === $isbn13) {
            return $this->json(['found' => false, 'reason' => 'isbn_invalide'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = $bookRepository->findOneByIsbn($isbn13);
        $metadata = $fetcher->fetchByIsbn($isbn13);

        return $this->json([
            'found' => null !== $metadata,
            'isbn13' => $isbn13,
            'existing' => null === $existing ? null : [
                'id' => $existing->getId(),
                'title' => $existing->getTitle(),
                'url' => $this->generateUrl('admin_book_edit', ['id' => $existing->getId()]),
            ],
            'book' => null === $metadata ? null : [
                'title' => $metadata->title,
                'subtitle' => $metadata->subtitle,
                'authors' => implode(', ', $metadata->authors),
                'categories' => implode(', ', $metadata->categories),
                'publisher' => $metadata->publisher,
                'publishedYear' => $metadata->publishedYear,
                'pageCount' => $metadata->pageCount,
                'language' => $metadata->language,
                'description' => $metadata->description,
                'isbn10' => $metadata->isbn10,
                'coverUrl' => $metadata->coverUrl,
                'sources' => $metadata->sources,
            ],
        ]);
    }

    #[Route('/nouveau', name: 'admin_book_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        MetadataFetcher $fetcher,
        BookImporter $importer,
        CategoryRepository $categoryRepository,
        #[MapQueryParameter] ?string $isbn = null,
    ): Response {
        $book = new Book();
        $user = $this->getUser();
        $book->setCreatedBy($user instanceof User ? $user : null);

        $form = $this->createForm(BookType::class, $book);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyFreeTextFields($form, $book);

            $coverUrl = $request->request->getString('coverUrl');
            if ('' !== $coverUrl && $book->getImages()->isEmpty()) {
                $importer->apply($book, new BookMetadata(coverUrl: $coverUrl));
            }

            $this->applyUploadedCover($form, $book);

            $this->entityManager->persist($book);
            $this->entityManager->flush();

            $this->addFlash('success', \sprintf('« %s » a été ajouté au catalogue.', $book->getTitle()));

            return $this->redirectToRoute($request->request->has('saveAndScan') ? 'admin_book_scan' : 'admin_book_index');
        }

        return $this->render('admin/book/new.html.twig', [
            'form' => $form,
            'prefillIsbn' => $isbn,
            'knownCategories' => $categoryRepository->findAllOrdered(),
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_book_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Book $book,
        CategoryRepository $categoryRepository,
    ): Response {
        $form = $this->createForm(BookType::class, $book);
        $form->get('authorsText')->setData($book->getAuthorNames());
        $form->get('categoriesText')->setData(implode(', ', $book->getCategories()->map(
            static fn ($category): string => $category->getName(),
        )->toArray()));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyFreeTextFields($form, $book);
            $this->applyUploadedCover($form, $book);

            $this->entityManager->flush();
            $this->addFlash('success', 'Les modifications ont été enregistrées.');

            return $this->redirectToRoute('admin_book_index');
        }

        return $this->render('admin/book/edit.html.twig', [
            'form' => $form,
            'book' => $book,
            'knownCategories' => $categoryRepository->findAllOrdered(),
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_book_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Book $book): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-livre-'.$book->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $title = $book->getTitle();
        $this->entityManager->remove($book);
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('« %s » a été supprimé.', $title));

        return $this->redirectToRoute('admin_book_index');
    }

    /**
     * Convertit les champs texte en entités, en réutilisant l'existant.
     */
    private function applyFreeTextFields(FormInterface $form, Book $book): void
    {
        $authorsText = (string) $form->get('authorsText')->getData();
        $categoriesText = (string) $form->get('categoriesText')->getData();

        foreach ($book->getAuthors()->toArray() as $author) {
            $book->removeAuthor($author);
        }
        foreach ($this->authorResolver->resolveAll($this->splitList($authorsText)) as $author) {
            $book->addAuthor($author);
        }

        foreach ($book->getCategories()->toArray() as $category) {
            $book->removeCategory($category);
        }
        foreach ($this->categoryResolver->resolveAll($this->splitList($categoriesText)) as $category) {
            $book->addCategory($category);
        }
    }

    private function applyUploadedCover(FormInterface $form, Book $book): void
    {
        $uploaded = $form->get('coverFile')->getData();

        if (null === $uploaded) {
            return;
        }

        foreach ($book->getImages() as $image) {
            $image->setPrimary(false);
        }

        $image = (new BookImage())->setPrimary(true)->setPosition(0);
        $image->setImageFile($uploaded);
        $book->addImage($image);
    }

    /**
     * @return array<int, string>
     */
    private function splitList(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $v): bool => '' !== $v));
    }
}
