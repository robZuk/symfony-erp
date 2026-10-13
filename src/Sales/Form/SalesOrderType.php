<?php

namespace App\Sales\Form;

use App\Sales\Dto\OrderDraft;
use App\Shared\Inventory\WarehouseDirectory;
use App\Shared\Partner\PartnerDirectory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * @extends AbstractType<OrderDraft>
 */
final class SalesOrderType extends AbstractType
{
    public function __construct(
        private readonly PartnerDirectory $partners,
        private readonly WarehouseDirectory $warehouses,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $customers = [];
        foreach ($this->partners->customers() as $customer) {
            $customers[$customer->name] = $customer->id;
        }

        $warehouses = [];
        foreach ($this->warehouses->active() as $warehouse) {
            $warehouses[$warehouse->label()] = $warehouse->id;
        }

        $builder
            ->add('customerId', ChoiceType::class, [
                'label' => 'Odbiorca',
                'placeholder' => 'Wybierz odbiorcę',
                'choices' => $customers,
            ])
            ->add('warehouseId', ChoiceType::class, [
                'label' => 'Wydanie z magazynu',
                'placeholder' => 'Wybierz magazyn',
                'choices' => $warehouses,
            ])
            ->add('orderDate', DateType::class, [
                'label' => 'Data zamówienia',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('note', TextareaType::class, ['label' => 'Uwagi', 'required' => false])
            // A collection whose "add" and "remove" buttons are handled by the Live Component, without custom JavaScript
            ->add('lines', LiveCollectionType::class, [
                'entry_type' => SalesOrderLineType::class,
                'label' => false,
                'button_add_options' => ['label' => 'Dodaj pozycję'],
                'button_delete_options' => ['label' => 'Usuń'],
                'error_bubbling' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OrderDraft::class]);
    }
}
