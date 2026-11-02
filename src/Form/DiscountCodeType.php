<?php

namespace App\Form;

use App\Entity\DiscountCode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DiscountCodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code')
            ->add('discountType', ChoiceType::class, ['choices' => array_combine(DiscountCode::TYPES, DiscountCode::TYPES)])
            ->add('discountValue')
            ->add('minSpend', null, ['required' => false])
            ->add('usageLimit', null, ['required' => false])
            ->add('expiresAt', null, ['required' => false, 'widget' => 'single_text'])
            ->add('isActive', null, ['required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DiscountCode::class]);
    }
}
