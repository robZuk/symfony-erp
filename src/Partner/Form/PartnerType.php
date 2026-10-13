<?php

namespace App\Partner\Form;

use App\Partner\Entity\Partner;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Partner>
 */
final class PartnerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nazwa'])
            ->add('nip', TextType::class, [
                'label' => 'NIP',
                'required' => false,
                'help' => 'Zostaw puste dla osoby prywatnej.',
            ])
            ->add('customer', CheckboxType::class, ['label' => 'Odbiorca', 'required' => false])
            ->add('supplier', CheckboxType::class, ['label' => 'Dostawca', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'required' => false])
            ->add('phone', TelType::class, ['label' => 'Telefon', 'required' => false])
            ->add('address', AddressType::class, ['label' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Partner::class]);
    }
}
