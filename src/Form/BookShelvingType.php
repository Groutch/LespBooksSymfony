<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Book;
use App\Entity\Genre;
use App\Repository\GenreRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Ranger un livre : son rayon et sa position, rien d'autre. « le ranger », sur
 * la fiche, ouvrait le formulaire complet (BookType), quatorze champs a
 * parcourir pour en changer un.
 */
class BookShelvingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('genre', EntityType::class, [
                'label' => 'Rayon',
                'class' => Genre::class,
                'choice_label' => 'name',
                'query_builder' => static fn (GenreRepository $repository): QueryBuilder => $repository
                    ->createQueryBuilder('g')
                    ->orderBy('g.name', 'ASC'),
                // Des cases a toucher plutot qu'une liste deroulante : les rayons
                // sont peu nombreux, et on les voit tous d'un coup.
                'expanded' => true,
                // Ranger, c'est choisir un rayon : obligatoire ici, quand le
                // formulaire complet le laisse vide.
                'required' => true,
                'constraints' => [new NotNull(message: 'Choisissez le rayon où ranger le livre.')],
            ])
            ->add('shelfLocation', TextType::class, [
                'label' => 'Position sur l\'étagère',
                'help' => 'Facultatif : où le livre se trouve dans le rayon, « étagère 3 », « R2-B4 »…',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Book::class,
        ]);
    }
}
