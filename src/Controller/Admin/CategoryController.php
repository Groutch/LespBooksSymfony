<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Form\CategoryType;
use App\Repository\CategoryRepository;
use App\Service\Catalog\CategoryMerger;
use App\Service\Catalog\CategoryResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/categories')]
#[IsGranted('ROLE_ADMIN')]
class CategoryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryResolver $categoryResolver,
    ) {
    }

    #[Route('', name: 'admin_category_index', methods: ['GET'])]
    public function index(CategoryRepository $categoryRepository): Response
    {
        return $this->render('admin/category/index.html.twig', [
            'rows' => $categoryRepository->findAllWithBookCount(),
            'duplicates' => $this->categoryResolver->findDuplicatePairs(),
        ]);
    }

    #[Route('/nouvelle', name: 'admin_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $category = new Category();
        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Le rangement physique interdit les doublons : on montre les voisines
            // avant de creer, sauf si l'administrateur a explicitement confirme.
            $suggestions = $this->categoryResolver->suggest($category->getName());

            if ([] !== $suggestions && !$request->request->getBoolean('confirm')) {
                return $this->render('admin/category/new.html.twig', [
                    'form' => $form,
                    'suggestions' => $suggestions,
                ]);
            }

            $this->entityManager->persist($category);
            $this->entityManager->flush();

            $this->addFlash('success', \sprintf('La catégorie « %s » a été créée.', $category->getName()));

            return $this->redirectToRoute('admin_category_index');
        }

        return $this->render('admin/category/new.html.twig', [
            'form' => $form,
            'suggestions' => [],
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_category_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Category $category, CategoryRepository $categoryRepository): Response
    {
        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Les modifications ont été enregistrées.');

            return $this->redirectToRoute('admin_category_index');
        }

        return $this->render('admin/category/edit.html.twig', [
            'form' => $form,
            'category' => $category,
            'others' => array_filter(
                $categoryRepository->findAllOrdered(),
                static fn (Category $other): bool => $other->getId() !== $category->getId(),
            ),
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_category_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Category $category): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-categorie-'.$category->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $name = $category->getName();

        // Seules les lignes de jointure disparaissent : les livres restent au catalogue.
        $this->entityManager->remove($category);
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('La catégorie « %s » a été supprimée, les livres sont conservés.', $name));

        return $this->redirectToRoute('admin_category_index');
    }

    #[Route('/fusionner', name: 'admin_category_merge', methods: ['POST'])]
    public function merge(Request $request, CategoryRepository $categoryRepository, CategoryMerger $merger): Response
    {
        if (!$this->isCsrfTokenValid('fusionner-categories', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $source = $categoryRepository->find($request->request->getInt('source'));
        $target = $categoryRepository->find($request->request->getInt('target'));

        if (null === $source || null === $target || $source === $target) {
            $this->addFlash('error', 'Choisissez deux catégories différentes à fusionner.');

            return $this->redirectToRoute('admin_category_index');
        }

        $sourceName = $source->getName();
        $moved = $merger->merge($source, $target);

        $this->addFlash('success', \sprintf(
            '« %s » a été fusionnée dans « %s » (%d livre%s déplacé%s).',
            $sourceName,
            $target->getName(),
            $moved,
            $moved > 1 ? 's' : '',
            $moved > 1 ? 's' : '',
        ));

        return $this->redirectToRoute('admin_category_index');
    }
}
