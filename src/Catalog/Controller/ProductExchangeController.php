<?php

namespace App\Catalog\Controller;

use App\Catalog\Dto\PriceListUpload;
use App\Catalog\Entity\ProductImport;
use App\Catalog\Exchange\PriceListColumns;
use App\Catalog\Exchange\PriceListFormat;
use App\Catalog\Exchange\PriceListWriter;
use App\Catalog\Form\PriceListUploadType;
use App\Catalog\Message\ImportProducts;
use App\Catalog\Repository\ProductImportRepository;
use App\Catalog\Repository\ProductRepository;
use App\Shared\Audit\ActivityLog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The catalog as a file: the price list exported and imported in CSV or XML.
 */
#[Route('/catalog/products', name: 'catalog_product_')]
final class ProductExchangeController extends AbstractController
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    /**
     * The whole catalog, withdrawn products included, so that an export imported back changes nothing.
     * Streamed: written while it is sent, never held in memory whole.
     */
    #[Route('/export.{_format}', name: 'export', requirements: ['_format' => 'csv|xml'], methods: ['GET'])]
    public function export(string $_format, ProductRepository $products, PriceListWriter $writer): StreamedResponse
    {
        $format = PriceListFormat::from($_format);
        $fileName = \sprintf('produkty-%s.%s', $this->clock->now()->format('Y-m-d'), $format->value);

        return new StreamedResponse(
            static function () use ($products, $writer, $format): void {
                $out = fopen('php://output', 'w') ?: throw new \RuntimeException('Cannot write the response.');
                $writer->write($products->streamAll(), $format, $out);
                fclose($out);
            },
            Response::HTTP_OK,
            [
                'Content-Type' => $format->contentType(),
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName),
            ],
        );
    }

    #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_CATALOG_EDIT')]
    public function import(Request $request, EntityManagerInterface $entityManager, MessageBusInterface $bus, ActivityLog $activity, ProductImportRepository $imports): Response
    {
        $upload = new PriceListUpload();
        $form = $this->createForm(PriceListUploadType::class, $upload);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $upload->file) {
            $format = PriceListFormat::fromFileName($upload->file->getClientOriginalName()) ?? PriceListFormat::Csv;
            $import = new ProductImport(
                $upload->file->getClientOriginalName(),
                $format,
                (string) file_get_contents($upload->file->getPathname()),
                $activity->currentActor()?->actorName() ?? 'System',
                $this->clock->now(),
            );
            $entityManager->persist($import);
            $entityManager->flush();

            // The worker imports it; the report page shows the outcome as soon as it is there
            $bus->dispatch(new ImportProducts((int) $import->getId()));

            return $this->redirectToRoute('catalog_product_import_show', ['id' => $import->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('catalog/product/import.html.twig', [
            'form' => $form,
            'columns' => PriceListColumns::ALL,
            'required' => PriceListColumns::REQUIRED,
            'imports' => $imports->latest(),
        ]);
    }

    #[Route('/import/{id}', name: 'import_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_CATALOG_EDIT')]
    public function show(ProductImport $import): Response
    {
        return $this->render('catalog/product/import_show.html.twig', ['import' => $import]);
    }
}
