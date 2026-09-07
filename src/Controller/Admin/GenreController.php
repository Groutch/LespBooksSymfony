<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Genre;
use App\Form\GenreType;
use App\Repository\GenreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le genre porte le rangement physique : un livre n'est range qu'a un seul endroit.
 */
#[Route('/admin/rayons')]
#[IsGranted('ROLE_ADMIN')]
class GenreController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'admin_genre_index', methods: ['GET', 'POST'])]
    public function index(Request $request, GenreRepository $genreRepository): Response
    {
        $genre = new Genre();
        $form = $this->createForm(GenreType::class, $genre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($genre);
            $this->entityManager->flush();

            $this->addFlash('success', \sprintf('Le rayon « %s » a été créé.', $genre->getName()));

            return $this->redirectToRoute('admin_genre_index');
        }

        return $this->render('admin/genre/index.html.twig', [
            'form' => $form,
            'genres' => $genreRepository->findAllOrdered(),
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_genre_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Genre $genre): Response
    {
        $form = $this->createForm(GenreType::class, $genre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Le rayon a été renommé.');

            return $this->redirectToRoute('admin_genre_index');
        }

        return $this->render('admin/genre/edit.html.twig', [
            'form' => $form,
            'genre' => $genre,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_genre_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Genre $genre): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-rayon-'.$genre->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $name = $genre->getName();

        // La cle etrangere est en SET NULL : les livres restent, sans rayon.
        $this->entityManager->remove($genre);
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('Le rayon « %s » a été supprimé, les livres sont conservés.', $name));

        return $this->redirectToRoute('admin_genre_index');
    }
}
