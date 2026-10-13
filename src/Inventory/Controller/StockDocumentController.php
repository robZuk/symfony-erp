<?php

namespace App\Inventory\Controller;

use App\Inventory\Dto\DocumentFilter;
use App\Inventory\Dto\DocumentHeader;
use App\Inventory\Entity\DocumentType;
use App\Inventory\Entity\StockDocument;
use App\Inventory\Form\StockDocumentLineType;
use App\Inventory\Form\StockDocumentType;
use App\Inventory\Repository\StockDocumentRepository;
use App\Inventory\Repository\WarehouseRepository;
use App\Inventory\Service\StockPosting;
use App\Shared\Catalog\ProductDirectory;
use App\Shared\Inventory\InsufficientStock;
use App\Shared\Partner\PartnerDirectory;
use App\Shared\Twig\QuantityExtension;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/inventory/documents', name: 'inventory_document_')]
final class StockDocumentController extends AbstractController
{
    private const string CSRF_TOKEN = 'inventory_document';

    public function __construct(
        private readonly StockDocumentRepository $documents,
        private readonly ProductDirectory $products,
        private readonly PartnerDirectory $partners,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        WarehouseRepository $warehouses,
        #[MapQueryString] DocumentFilter $filter = new DocumentFilter(),
    ): Response {
        $page = $this->documents->search($filter);

        $partnerIds = [];
        foreach ($page->items as $document) {
            if (null !== $document->getPartnerId()) {
                $partnerIds[] = $document->getPartnerId();
            }
        }

        return $this->render('inventory/document/index.html.twig', [
            'filter' => $filter,
            'page' => $page,
            'warehouses' => $warehouses->findAllOrdered(),
            'partners' => $this->partners->findMany($partnerIds),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_INVENTORY_EDIT')]
    public function new(Request $request, #[MapQueryParameter] DocumentType $type = DocumentType::Receipt): Response
    {
        $now = $this->clock->now();
        $header = new DocumentHeader($type, $now);
        $form = $this->createForm(StockDocumentType::class, $header);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $header->warehouse && null !== $header->documentDate) {
            $document = new StockDocument($type, $header->warehouse, $header->documentDate, $now, $header->targetWarehouse);
            $document->setPartnerId($header->partnerId);
            $document->setNote($header->note);
            $this->documents->save($document);

            return $this->redirectToShow($document);
        }

        return $this->render('inventory/document/new.html.twig', [
            'form' => $form,
            'type' => $type,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(StockDocument $document): Response
    {
        return $this->renderShow($document, $this->createForm(StockDocumentLineType::class));
    }

    #[Route('/{id}/lines', name: 'add_line', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_INVENTORY_EDIT')]
    public function addLine(Request $request, StockDocument $document): Response
    {
        $form = $this->createForm(StockDocumentLineType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderShow($document, $form);
        }

        /** @var array{productId: int, quantity: int} $line */
        $line = $form->getData();

        try {
            $document->addLine($line['productId'], $line['quantity']);
            $this->documents->save($document);
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToShow($document);
    }

    #[Route('/{id}/lines/{lineId}/delete', name: 'remove_line', methods: ['POST'], requirements: ['id' => '\d+', 'lineId' => '\d+'])]
    #[IsGranted('ROLE_INVENTORY_EDIT')]
    #[IsCsrfTokenValid(self::CSRF_TOKEN)]
    public function removeLine(StockDocument $document, int $lineId): Response
    {
        foreach ($document->getLines() as $line) {
            if ($line->getId() !== $lineId) {
                continue;
            }

            try {
                $document->removeLine($line);
                $this->documents->save($document);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToShow($document);
    }

    #[Route('/{id}/post', name: 'post', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_INVENTORY_EDIT')]
    #[IsCsrfTokenValid(self::CSRF_TOKEN)]
    public function post(StockDocument $document, StockPosting $posting, QuantityExtension $quantity): Response
    {
        try {
            $posting->post($document);
            $this->addFlash('success', \sprintf('Zaksięgowano dokument %s.', $document->getNumber()));
        } catch (InsufficientStock $exception) {
            $products = $this->products->findMany(array_keys($exception->shortages));
            foreach ($exception->shortages as $productId => $shortage) {
                $this->addFlash('error', \sprintf(
                    'Brak towaru %s: potrzeba %s, dostępne %s.',
                    isset($products[$productId]) ? $products[$productId]->sku : '#'.$productId,
                    $quantity->format($shortage['requested']),
                    $quantity->format($shortage['available']),
                ));
            }
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToShow($document);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_INVENTORY_EDIT')]
    #[IsCsrfTokenValid(self::CSRF_TOKEN)]
    public function delete(StockDocument $document): Response
    {
        try {
            $this->documents->remove($document);
            $this->addFlash('success', 'Usunięto szkic dokumentu.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToShow($document);
        }

        return $this->redirectToRoute('inventory_document_index', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * A posted document cannot be edited, so a mistake is fixed with a second document
     * that moves the same goods the other way. It starts as a draft, to be reviewed and posted.
     */
    #[Route('/{id}/correct', name: 'correct', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_INVENTORY_EDIT')]
    #[IsCsrfTokenValid(self::CSRF_TOKEN)]
    public function correct(StockDocument $document): Response
    {
        if ($document->isDraft()) {
            $this->addFlash('error', 'Szkic można po prostu poprawić albo usunąć.');

            return $this->redirectToShow($document);
        }

        $now = $this->clock->now();
        $target = $document->getTargetWarehouse();
        // A transfer is undone by sending the goods back; a receipt or an issue by its opposite in the same warehouse
        $correction = null === $target
            ? new StockDocument($document->getType()->opposite(), $document->getWarehouse(), $now, $now)
            : new StockDocument(DocumentType::Transfer, $target, $now, $now, $document->getWarehouse());
        $correction->setPartnerId($document->getPartnerId());
        $correction->setNote(\sprintf('Korekta dokumentu %s', $document->getNumber()));

        foreach ($document->getLines() as $line) {
            $correction->addLine($line->getProductId(), $line->getQuantity());
        }

        $this->documents->save($correction);
        $this->addFlash('success', 'Utworzono szkic korekty. Sprawdź pozycje i zaksięguj.');

        return $this->redirectToShow($correction);
    }

    /**
     * @param FormInterface<array{productId: int, quantity: int}|null> $lineForm
     */
    private function renderShow(StockDocument $document, FormInterface $lineForm): Response
    {
        $productIds = [];
        foreach ($document->getLines() as $line) {
            $productIds[] = $line->getProductId();
        }

        return $this->render('inventory/document/show.html.twig', [
            'document' => $document,
            'products' => $this->products->findMany($productIds),
            'partner' => null === $document->getPartnerId() ? null : $this->partners->find($document->getPartnerId()),
            'line_form' => $lineForm,
            'csrf_token_id' => self::CSRF_TOKEN,
        ]);
    }

    private function redirectToShow(StockDocument $document): Response
    {
        return $this->redirectToRoute('inventory_document_show', ['id' => $document->getId()], Response::HTTP_SEE_OTHER);
    }
}
