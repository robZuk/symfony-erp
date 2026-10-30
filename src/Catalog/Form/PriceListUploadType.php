<?php

namespace App\Catalog\Form;

use App\Catalog\Dto\PriceListUpload;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PriceListUpload>
 */
final class PriceListUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, [
            'label' => 'Plik cennika (CSV albo XML)',
            'attr' => ['accept' => '.csv,.xml,text/csv,application/xml'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PriceListUpload::class]);
    }
}
