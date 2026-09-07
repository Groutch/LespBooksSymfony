<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Genre;
use App\Service\TextNormalizer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Event\PostSubmitEvent;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GenreType extends AbstractType
{
    public function __construct(
        private readonly TextNormalizer $normalizer,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Nom du rayon',
            'help' => 'Le genre correspond à l\'endroit où le livre est rangé : Roman, Policier, Jeunesse, BD…',
        ]);

        // Priorite 10 : le slug doit exister avant que UniqueEntity ne le controle.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (PostSubmitEvent $event): void {
            $genre = $event->getData();

            if ($genre instanceof Genre) {
                $genre->setSlug($this->normalizer->slugify($genre->getName()));
            }
        }, 10);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Genre::class,
        ]);
    }
}
