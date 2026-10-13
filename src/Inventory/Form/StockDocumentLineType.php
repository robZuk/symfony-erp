<?php

namespace App\Inventory\Form;

use App\Shared\Catalog\ProductDirectory;
use App\Shared\Catalog\ProductSummary;
use App\Shared\Form\QuantityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One new line of a warehouse document. Submits an array with "productId" and "quantity".
 *
 * @extends AbstractType<array{productId: int, quantity: int}>
 */
final class StockDocumentLineType extends AbstractType
{
    public function __construct(private readonly ProductDirectory $products)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($this->products->stockKept() as $product) {
            $choices[self::choiceLabel($product)] = $product->id;
        }

        $builder
            ->add('productId', ChoiceType::class, [
                'label' => 'Produkt',
                'placeholder' => 'Wybierz produkt',
                'choices' => $choices,
                'constraints' => [new Assert\NotBlank(message: 'Wybierz produkt.')],
            ])
            ->add('quantity', QuantityType::class, [
                'label' => 'Ilość',
                'constraints' => [
                    new Assert\NotBlank(message: 'Podaj ilość.'),
                    new Assert\Positive(message: 'Ilość musi być większa od zera.'),
                ],
            ]);
    }

    private static function choiceLabel(ProductSummary $product): string
    {
        return \sprintf('%s [%s]', $product->label(), $product->unit);
    }
}
