<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Book;
use App\Entity\Genre;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

class BookType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre',
            ])
            ->add('subtitle', TextType::class, [
                'label' => 'Sous-titre',
                'required' => false,
            ])
            // Texte libre plutot qu'une liste : le scan doit pouvoir creer les
            // auteurs a la volee, la deduplication est faite par AuthorResolver.
            ->add('authorsText', TextType::class, [
                'label' => 'Auteurs',
                'required' => false,
                'mapped' => false,
                'help' => 'Séparez les auteurs par une virgule.',
            ])
            ->add('categoriesText', TextType::class, [
                'label' => 'Catégories',
                'required' => false,
                'mapped' => false,
                'help' => 'Séparez par une virgule. Une catégorie proche existante sera réutilisée.',
            ])
            ->add('genre', EntityType::class, [
                'label' => 'Genre',
                'class' => Genre::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '—',
            ])
            ->add('isbn13', TextType::class, [
                'label' => 'ISBN-13',
                'required' => false,
            ])
            ->add('isbn10', TextType::class, [
                'label' => 'ISBN-10',
                'required' => false,
            ])
            ->add('publisher', TextType::class, [
                'label' => 'Éditeur',
                'required' => false,
            ])
            ->add('publishedYear', IntegerType::class, [
                'label' => 'Année',
                'required' => false,
            ])
            ->add('pageCount', IntegerType::class, [
                'label' => 'Pages',
                'required' => false,
            ])
            ->add('language', TextType::class, [
                'label' => 'Langue',
                'required' => false,
            ])
            ->add('shelfLocation', TextType::class, [
                'label' => 'Emplacement en rayon',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
            ])
            ->add('coverFile', FileType::class, [
                'label' => 'Couverture (remplace l\'image principale)',
                'required' => false,
                'mapped' => false,
                'constraints' => [
                    new Image(maxSize: '8M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp']),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Book::class,
        ]);
    }
}
