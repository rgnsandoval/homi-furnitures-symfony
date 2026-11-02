<?php

namespace App\Form;

use App\Entity\ReturnRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class ReturnRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('returnReason', TextareaType::class, ['label' => 'Why are you returning this order?'])
            ->add('photoFile', FileType::class, [
                'label' => 'Photo (optional)',
                'mapped' => false,
                'required' => false,
                'constraints' => [new File(
                    maxSize: '5M',
                    mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                    mimeTypesMessage: 'Please upload a JPEG, PNG or WebP image.',
                )],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReturnRequest::class]);
    }
}
