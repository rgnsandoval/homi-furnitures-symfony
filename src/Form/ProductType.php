<?php

namespace App\Form;

use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Json;

class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $image = new File(
            maxSize: '5M',
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
            mimeTypesMessage: 'Please upload a JPEG, PNG or WebP image.',
        );

        $builder
            ->add('name')
            ->add('sku')
            ->add('slug')
            ->add('description')
            ->add('price')
            ->add('costPrice')
            ->add('salePrice', null, ['required' => false])
            ->add('status', ChoiceType::class, ['choices' => array_combine(Product::STATUSES, Product::STATUSES)])
            ->add('isFeatured', null, ['required' => false])
            ->add('material')
            ->add('defaultColor', null, ['required' => false])
            ->add('widthCm', null, ['required' => false])
            ->add('depthCm', null, ['required' => false])
            ->add('heightCm', null, ['required' => false])
            ->add('weightKg', null, ['required' => false])
            ->add('assemblyRequired', null, ['required' => false])
            ->add('warrantyMonths', null, ['required' => false])
            ->add('leadTimeDays')
            ->add('specifications', TextareaType::class, [
                'required' => false,
                'help' => 'JSON, for example {"Seats": "3"}',
            ])
            ->add('category', EntityType::class, ['class' => Category::class, 'choice_label' => 'name'])
            ->add('brand', EntityType::class, [
                'class' => Brand::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'No brand',
            ])
            ->add('imageFile', FileType::class, [
                'label' => 'Main image',
                'mapped' => false,
                'required' => false,
                'constraints' => [$image],
            ])
            ->add('galleryFiles', FileType::class, [
                'label' => 'Add gallery images',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'constraints' => [new All([$image])],
            ])
        ;

        $builder->get('specifications')->addModelTransformer(new CallbackTransformer(
            static fn (?array $value): string => null === $value ? '' : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            static function (?string $value): ?array {
                if (null === $value || '' === trim($value)) {
                    return null;
                }
                $decoded = json_decode($value, true);

                if (!is_array($decoded)) {
                    throw new \Symfony\Component\Form\Exception\TransformationFailedException('Specifications must be a JSON object.');
                }

                return $decoded;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
