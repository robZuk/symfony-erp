<?php

namespace App\Inventory\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'inventory_document_line')]
class StockDocumentLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private StockDocument $document;

    /** An id from the Catalog module. */
    #[ORM\Column]
    private int $productId;

    /** In thousandths of the product's unit; always positive. */
    #[ORM\Column]
    private int $quantity;

    public function __construct(StockDocument $document, int $productId, int $quantity)
    {
        if ($quantity <= 0) {
            throw new \DomainException('Ilość musi być większa od zera.');
        }

        $this->document = $document;
        $this->productId = $productId;
        $this->quantity = $quantity;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): StockDocument
    {
        return $this->document;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }
}
