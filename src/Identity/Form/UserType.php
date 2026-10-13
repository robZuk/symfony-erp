<?php

namespace App\Identity\Form;

use App\Identity\Entity\User;
use App\Identity\Service\UserAdministration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Account data as an administrator edits it. With "is_new" it also asks for the e-mail and
 * a first password; afterwards the e-mail is the account's identity and stays as it is.
 *
 * Submits an array: "email" and "password" (new accounts only), "fullName", "roles".
 *
 * @extends AbstractType<array{email?: string, password?: string, fullName: string, roles: list<string>}>
 */
final class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['is_new']) {
            $builder->add('email', EmailType::class, [
                'label' => 'E-mail',
                'help' => 'Służy do logowania. Później nie da się go zmienić.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Podaj adres e-mail.'),
                    new Assert\Email(message: 'To nie jest poprawny adres e-mail.'),
                    new Assert\Length(max: 180),
                ],
            ]);
        }

        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Imię i nazwisko',
                'constraints' => [new Assert\NotBlank(message: 'Podaj imię i nazwisko.'), new Assert\Length(max: 120)],
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Role',
                'choices' => array_flip(User::ROLE_LABELS),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'Bez żadnej roli użytkownik może tylko przeglądać dane.',
            ]);

        if ($options['is_new']) {
            $builder->add('password', PasswordType::class, [
                'label' => 'Hasło początkowe',
                'help' => 'Przekaż je użytkownikowi; zmieni je sam w „Moim koncie”.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Podaj hasło.'),
                    new Assert\Length(min: UserAdministration::MIN_PASSWORD_LENGTH, minMessage: 'Hasło musi mieć co najmniej {{ limit }} znaków.', max: 200),
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['is_new' => false]);
        $resolver->setAllowedTypes('is_new', 'bool');
    }
}
