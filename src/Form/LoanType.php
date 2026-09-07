<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Loan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LoanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('borrowerFirstName', TextType::class, [
                'label' => 'Prénom',
            ])
            ->add('borrowerLastName', TextType::class, [
                'label' => 'Nom',
            ])
            ->add('borrowedAt', DateType::class, [
                'label' => 'Date de prêt',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('dueAt', DateType::class, [
                'label' => 'Retour prévu',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'Facultatif : laissez vide pour un prêt sans échéance.',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Note',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Loan::class,
        ]);
    }
}
