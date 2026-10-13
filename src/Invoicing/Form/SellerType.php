<?php

namespace App\Invoicing\Form;

use App\Invoicing\Entity\Seller;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Seller>
 */
final class SellerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nazwa firmy'])
            ->add('nip', TextType::class, ['label' => 'NIP'])
            ->add('street', TextType::class, ['label' => 'Ulica i numer'])
            ->add('postalCode', TextType::class, ['label' => 'Kod pocztowy'])
            ->add('city', TextType::class, ['label' => 'Miejscowość'])
            ->add('bankAccount', TextType::class, [
                'label' => 'Rachunek bankowy',
                'required' => false,
                'help' => 'Drukowany na fakturach płatnych przelewem.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Seller::class]);
    }
}
