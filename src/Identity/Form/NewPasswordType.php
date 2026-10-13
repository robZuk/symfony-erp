<?php

namespace App\Identity\Form;

use App\Identity\Service\UserAdministration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A new password, typed twice. With "ask_current" the signed-in user must first prove
 * they know the current one, which is how people change their own password.
 *
 * Submits an array: "current" (when asked for) and "password".
 *
 * @extends AbstractType<array{current?: string, password: string}>
 */
final class NewPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['ask_current']) {
            $builder->add('current', PasswordType::class, [
                'label' => 'Obecne hasło',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new UserPassword(message: 'To nie jest Twoje obecne hasło.')],
            ]);
        }

        $builder->add('password', RepeatedType::class, [
            'type' => PasswordType::class,
            'label' => false,
            'invalid_message' => 'Oba hasła muszą być takie same.',
            'first_options' => ['label' => 'Nowe hasło', 'attr' => ['autocomplete' => 'new-password']],
            'second_options' => ['label' => 'Powtórz nowe hasło', 'attr' => ['autocomplete' => 'new-password']],
            'constraints' => [
                new Assert\NotBlank(message: 'Podaj nowe hasło.'),
                new Assert\Length(min: UserAdministration::MIN_PASSWORD_LENGTH, minMessage: 'Hasło musi mieć co najmniej {{ limit }} znaków.', max: 200),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['ask_current' => false]);
        $resolver->setAllowedTypes('ask_current', 'bool');
    }
}
