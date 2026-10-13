<?php

namespace App\Sales\Form;

use App\Sales\Dto\OrderLineDraft;
use App\Shared\Catalog\ProductDirectory;
use App\Shared\Form\QuantityType;
use App\Shared\Tax\VatRate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<OrderLineDraft>
 */
final class SalesOrderLineType extends AbstractType
{
    public function __construct(private readonly ProductDirectory $products)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($this->products->sellable() as $product) {
            $choices[\sprintf('%s [%s]', $product->label(), $product->unit)] = $product->id;
        }

        $builder
            ->add('productId', ChoiceType::class, [
                'label' => 'Produkt',
                'placeholder' => 'Wybierz produkt',
                'choices' => $choices,
            ])
            ->add('quantity', QuantityType::class, ['label' => 'Ilość'])
            ->add('unitNetPrice', MoneyType::class, [
                'label' => 'Cena netto',
                'currency' => 'PLN',
                'divisor' => 100,
                'input' => 'integer',
                'invalid_message' => 'Podaj kwotę, np. 49,90.',
            ])
            ->add('vatRate', EnumType::class, [
                'class' => VatRate::class,
                'label' => 'VAT',
                'placeholder' => '—',
                'choice_label' => static fn (VatRate $rate): string => $rate->label(),
            ])
            // Remembers which product the price and VAT rate on this line were taken from
            ->add('pricedFor', HiddenType::class, ['mapped' => false]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $line = $event->getData();
            if ($line instanceof OrderLineDraft && null !== $line->productId) {
                $event->getForm()->get('pricedFor')->setData((string) $line->productId);
            }
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, $this->fillFromCatalog(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OrderLineDraft::class]);
    }

    /**
     * When a different product is picked, takes its price and VAT rate from the catalog.
     * Afterwards the user is free to change them for this order.
     */
    private function fillFromCatalog(FormEvent $event): void
    {
        $submitted = $event->getData();
        if (!\is_array($submitted)) {
            return;
        }

        $productId = (string) ($submitted['productId'] ?? '');
        if ('' === $productId || $productId === (string) ($submitted['pricedFor'] ?? '')) {
            return;
        }

        $product = $this->products->find((int) $productId);
        if (null === $product) {
            return;
        }

        $submitted['unitNetPrice'] = number_format($product->netPrice / 100, 2, ',', '');
        $submitted['vatRate'] = $product->vatRate->value;
        $submitted['pricedFor'] = $productId;
        if ('' === (string) ($submitted['quantity'] ?? '')) {
            $submitted['quantity'] = '1';
        }

        $event->setData($submitted);
    }
}
