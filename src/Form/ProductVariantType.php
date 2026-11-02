<?php

namespace App\Form;

use App\Entity\Product;
use App\Entity\ProductVariant;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductVariantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ProductVariant|null $variant */
        $variant = $builder->getData();
        $isNew = null === $variant?->getId();

        $builder
            ->add('product', EntityType::class, ['class' => Product::class, 'choice_label' => 'name'])
            ->add('sku')
            ->add('name')
            ->add('color', null, ['required' => false])
            ->add('size', null, ['required' => false])
            ->add('finish', null, ['required' => false])
            ->add('priceAdjustment')
            // After creation, stock changes only through a recorded stock adjustment.
            ->add('stock', IntegerType::class, [
                'label' => 'Opening stock',
                'disabled' => !$isNew,
            ])
            ->add('lowStockThreshold')
            ->add('isActive', null, ['required' => false])
            ->add('image', null, ['required' => false, 'help' => 'Image filename, optional.'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProductVariant::class]);
    }
}
