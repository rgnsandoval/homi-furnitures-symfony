<?php

namespace App\Form;

use App\Entity\Review;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Moderation edit: fix a rating or wording (e.g. remove personal details) and approve or hide the review.
 */
class AdminReviewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rating', ChoiceType::class, ['choices' => ['5 stars' => 5, '4 stars' => 4, '3 stars' => 3, '2 stars' => 2, '1 star' => 1]])
            ->add('body', TextareaType::class, ['label' => 'Review text', 'empty_data' => ''])
            ->add('isApproved', CheckboxType::class, ['required' => false, 'label' => 'Approved (shown on the product page)'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Review::class]);
    }
}
