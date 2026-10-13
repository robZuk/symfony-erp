<?php

namespace App\Shared\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A quantity typed with up to three decimals ("2,5") and kept as an integer
 * number of thousandths (2500), the same way money is kept in grosze.
 *
 * @extends AbstractType<int>
 */
final class QuantityType extends AbstractType
{
    public const int SCALE = 1000;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?int $thousandths): ?float => null === $thousandths ? null : $thousandths / self::SCALE,
            static fn (int|float|null $units): ?int => null === $units ? null : (int) round($units * self::SCALE),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // No fixed "scale": a whole quantity is shown as "2", not "2,000"
        $resolver->setDefaults([
            'invalid_message' => 'Podaj ilość, np. 2 albo 2,5.',
        ]);
    }

    public function getParent(): string
    {
        return NumberType::class;
    }
}
