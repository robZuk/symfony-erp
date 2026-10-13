<?php

namespace App\Invoicing\Form;

use App\Invoicing\Entity\Invoice;
use App\Shared\Form\QuantityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The correction form: one quantity field per line of the original invoice, plus the reason.
 * Submits an array: "issueDate", "reason" and "quantities" (line id => new quantity in thousandths).
 *
 * @extends AbstractType<array{issueDate: \DateTimeImmutable, reason: string, quantities: array<int, int>}>
 */
final class CorrectInvoiceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Invoice $invoice */
        $invoice = $options['invoice'];

        $builder
            ->add('issueDate', DateType::class, [
                'label' => 'Data wystawienia korekty',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull(message: 'Podaj datę wystawienia.')],
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'Przyczyna korekty',
                'help' => 'Trafia na fakturę korygującą, np. „Zwrot towaru”.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Podaj przyczynę korekty.'),
                    new Assert\Length(max: 500),
                ],
            ]);

        $quantities = $builder->create('quantities', FormType::class, ['label' => false]);
        foreach ($invoice->getLines() as $line) {
            $current = $invoice->currentQuantity($line);

            $quantities->add((string) $line->getId(), QuantityType::class, [
                'label' => false,
                'data' => $current,
                'constraints' => [
                    new Assert\NotNull(message: 'Podaj ilość.'),
                    new Assert\Range(min: 0, max: $current, notInRangeMessage: 'Ilość po korekcie musi być między zerem a ilością dotychczasową.'),
                ],
            ]);
        }
        $builder->add($quantities);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('invoice');
        $resolver->setAllowedTypes('invoice', Invoice::class);
    }
}
