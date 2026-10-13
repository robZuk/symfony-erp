<?php

namespace App\Invoicing\Form;

use App\Invoicing\Dto\IssueInvoice;
use App\Invoicing\Entity\PaymentMethod;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<IssueInvoice>
 */
final class IssueInvoiceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('issueDate', DateType::class, [
                'label' => 'Data wystawienia',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('paymentDays', IntegerType::class, [
                'label' => 'Termin płatności (dni)',
                'help' => 'Liczony od daty wystawienia.',
            ])
            ->add('paymentMethod', EnumType::class, [
                'class' => PaymentMethod::class,
                'label' => 'Forma płatności',
                'choice_label' => static fn (PaymentMethod $method): string => $method->label(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => IssueInvoice::class]);
    }
}
