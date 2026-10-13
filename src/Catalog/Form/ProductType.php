<?php

namespace App\Catalog\Form;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductKind;
use App\Catalog\Entity\UnitOfMeasure;
use App\Shared\Tax\VatRate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Product>
 */
final class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sku', TextType::class, [
                'label' => 'Indeks',
                'help' => 'Unikalny kod produktu, np. KAB-HDMI-2M.',
            ])
            ->add('name', TextType::class, ['label' => 'Nazwa'])
            ->add('kind', EnumType::class, [
                'class' => ProductKind::class,
                'label' => 'Rodzaj',
                'choice_label' => static fn (ProductKind $kind): string => $kind->label(),
            ])
            ->add('unit', EnumType::class, [
                'class' => UnitOfMeasure::class,
                'label' => 'Jednostka',
                'choice_label' => static fn (UnitOfMeasure $unit): string => $unit->label(),
            ])
            ->add('netPrice', MoneyType::class, [
                'label' => 'Cena netto',
                'currency' => 'PLN',
                // The entity keeps the price in grosze as an integer
                'divisor' => 100,
                'input' => 'integer',
                'invalid_message' => 'Podaj kwotę, np. 49,90.',
            ])
            ->add('vatRate', EnumType::class, [
                'class' => VatRate::class,
                'label' => 'Stawka VAT',
                'choice_label' => static fn (VatRate $rate): string => $rate->label(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}
