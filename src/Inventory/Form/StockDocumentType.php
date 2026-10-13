<?php

namespace App\Inventory\Form;

use App\Inventory\Dto\DocumentHeader;
use App\Inventory\Entity\DocumentType;
use App\Inventory\Entity\Warehouse;
use App\Shared\Partner\PartnerDirectory;
use App\Shared\Partner\PartnerSummary;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Header of a warehouse document. The fields depend on the document type,
 * so the form must be created with a DocumentHeader as its data.
 *
 * @extends AbstractType<DocumentHeader>
 */
final class StockDocumentType extends AbstractType
{
    public function __construct(private readonly PartnerDirectory $partners)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $header = $options['data'] ?? null;
        if (!$header instanceof DocumentHeader) {
            throw new \LogicException('StockDocumentType needs a DocumentHeader as its data.');
        }
        $type = $header->type;

        $activeWarehouses = static fn (EntityRepository $repository): QueryBuilder => $repository
            ->createQueryBuilder('w')
            ->where('w.active = true')
            ->orderBy('w.code', 'ASC');

        $builder->add('warehouse', EntityType::class, [
            'class' => Warehouse::class,
            'query_builder' => $activeWarehouses,
            'choice_label' => static fn (Warehouse $warehouse): string => $warehouse->label(),
            'placeholder' => 'Wybierz magazyn',
            'label' => match ($type) {
                DocumentType::Receipt => 'Magazyn przyjmujący',
                DocumentType::Issue => 'Magazyn wydający',
                DocumentType::Transfer => 'Magazyn źródłowy',
            },
        ]);

        if (DocumentType::Transfer === $type) {
            $builder->add('targetWarehouse', EntityType::class, [
                'class' => Warehouse::class,
                'query_builder' => $activeWarehouses,
                'choice_label' => static fn (Warehouse $warehouse): string => $warehouse->label(),
                'placeholder' => 'Wybierz magazyn',
                'label' => 'Magazyn docelowy',
            ]);
        } else {
            $partners = DocumentType::Receipt === $type ? $this->partners->suppliers() : $this->partners->customers();

            $builder->add('partnerId', ChoiceType::class, [
                'label' => DocumentType::Receipt === $type ? 'Dostawca' : 'Odbiorca',
                'required' => false,
                'placeholder' => '— brak —',
                'choices' => array_column(
                    array_map(static fn (PartnerSummary $partner): array => [$partner->name, $partner->id], $partners),
                    1,
                    0,
                ),
            ]);
        }

        $builder
            ->add('documentDate', DateType::class, [
                'label' => 'Data dokumentu',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('note', TextareaType::class, ['label' => 'Uwagi', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DocumentHeader::class]);
    }
}
