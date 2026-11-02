<?php

namespace App\Form;

use App\Entity\StockMovement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotEqualTo;

class StockAdjustmentFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $reasons = [StockMovement::REASON_RESTOCK, StockMovement::REASON_DAMAGED, StockMovement::REASON_CORRECTION];

        $builder
            ->add('quantityChange', IntegerType::class, [
                'help' => 'Positive to add, negative to remove.',
                'constraints' => [new NotBlank(), new NotEqualTo(0)],
            ])
            ->add('movementReason', ChoiceType::class, ['choices' => array_combine($reasons, $reasons), 'label' => 'Reason'])
            ->add('note', TextType::class, ['required' => false, 'constraints' => [new Length(max: 255)]])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
