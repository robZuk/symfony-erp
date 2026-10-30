<?php

namespace App\Catalog\Twig;

use App\Catalog\Entity\ProductImport;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The outcome of a price list import. While the worker is still at it, the component asks the
 * server again every moment (data-poll) and shows the report by itself once it is there.
 */
#[AsLiveComponent(name: 'Catalog:ImportReport', template: 'catalog/product/_import_report.html.twig')]
final class ImportReport
{
    use DefaultActionTrait;

    /** Reloaded from the database on every poll, so the status is always current. */
    #[LiveProp]
    public ProductImport $import;
}
