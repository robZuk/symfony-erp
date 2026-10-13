<?php

namespace App\Partner\Form;

use App\Partner\Entity\Address;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Address>
 */
final class AddressType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('street', TextType::class, ['label' => 'Ulica i numer'])
            ->add('postalCode', TextType::class, ['label' => 'Kod pocztowy'])
            ->add('city', TextType::class, ['label' => 'Miejscowość'])
            ->add('countryCode', CountryType::class, [
                'label' => 'Kraj',
                'preferred_choices' => ['PL'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Address::class]);
    }
}
