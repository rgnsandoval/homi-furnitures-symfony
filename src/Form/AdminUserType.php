<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Admin create/edit form for any account. The password is required when creating and optional when editing.
 */
class AdminUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $passwordConstraints = [new Length(min: 8, max: 4096, minMessage: 'Use at least {{ limit }} characters.')];
        if ($options['require_password']) {
            $passwordConstraints[] = new NotBlank(message: 'Set a password for the new account.');
        }

        $builder
            ->add('fullName', TextType::class, ['label' => 'Full name', 'empty_data' => ''])
            ->add('email', EmailType::class, ['empty_data' => ''])
            ->add('phone', TelType::class, ['required' => false])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'required' => $options['require_password'],
                'label' => $options['require_password'] ? 'Password' : 'New password',
                'help' => $options['require_password'] ? null : 'Leave blank to keep the current password.',
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => $passwordConstraints,
            ])
            ->add('admin', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Administrator (full access to the admin area)',
                'data' => $options['is_admin'],
                'disabled' => $options['lock_role'],
                'help' => $options['lock_role'] ? 'You cannot remove your own admin access.' : null,
            ])
            ->add('isActive', CheckboxType::class, ['required' => false, 'label' => 'Active (can log in and order)', 'disabled' => $options['lock_role']])
            ->add('isVerified', CheckboxType::class, ['required' => false, 'label' => 'Email verified'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'require_password' => false,
            'is_admin' => false,
            'lock_role' => false,
        ]);
        $resolver->setAllowedTypes('require_password', 'bool');
        $resolver->setAllowedTypes('is_admin', 'bool');
        $resolver->setAllowedTypes('lock_role', 'bool');
    }
}
