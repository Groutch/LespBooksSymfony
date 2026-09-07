<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Category;
use App\Service\TextNormalizer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Event\PostSubmitEvent;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CategoryType extends AbstractType
{
    public function __construct(
        private readonly TextNormalizer $normalizer,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
            ])
            // Le slug n'est pas saisissable : il est derive du nom pour rester
            // la cle de deduplication utilisee par CategoryResolver.
            ->add('shelfCode', TextType::class, [
                'label' => 'Rayon',
                'required' => false,
                'help' => 'Repère de rangement physique, par exemple « A3 ».',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
            ]);

        // Priorite 10 : le slug doit exister avant que UniqueEntity ne le controle.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (PostSubmitEvent $event): void {
            $category = $event->getData();

            if ($category instanceof Category) {
                $category->setSlug($this->normalizer->slugify($category->getName()));
            }
        }, 10);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Category::class,
        ]);
    }
}
