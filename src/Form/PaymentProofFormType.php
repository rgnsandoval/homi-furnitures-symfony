<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

class PaymentProofFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('proof', FileType::class, [
            'label' => 'Proof of payment (JPEG, PNG, WebP or PDF, up to 5 MB)',
            'constraints' => [
                new NotNull(message: 'Please choose a file.'),
                new File(
                    maxSize: '5M',
                    mimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
                    mimeTypesMessage: 'Please upload a JPEG, PNG, WebP or PDF file.',
                ),
            ],
        ]);
    }
}
