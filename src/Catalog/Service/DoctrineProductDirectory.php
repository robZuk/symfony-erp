<?php

namespace App\Catalog\Service;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductKind;
use App\Catalog\Repository\ProductRepository;
use App\Shared\Catalog\ProductDirectory;
use App\Shared\Catalog\ProductSummary;
use App\Shared\Pagination\Paginator;

final class DoctrineProductDirectory implements ProductDirectory
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function find(int $id): ?ProductSummary
    {
        $product = $this->products->find($id);

        return null === $product ? null : self::summarize($product);
    }

    public function findMany(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $summaries = [];
        foreach ($this->products->findBy(['id' => array_unique($ids)]) as $product) {
            $summaries[(int) $product->getId()] = self::summarize($product);
        }

        return $summaries;
    }

    public function stockKept(): array
    {
        $products = $this->products->findBy(['active' => true, 'kind' => ProductKind::Goods], ['name' => 'ASC']);

        return array_map(self::summarize(...), $products);
    }

    public function sellable(): array
    {
        return array_map(self::summarize(...), $this->products->findBy(['active' => true], ['name' => 'ASC']));
    }

    public function idsMatching(string $term): array
    {
        /** @var list<int> $ids */
        $ids = $this->products->createQueryBuilder('p')
            ->select('p.id')
            ->where('LOWER(p.name) LIKE :term OR LOWER(p.sku) LIKE :term')
            ->setParameter('term', Paginator::containsPattern($term))
            ->getQuery()
            ->getSingleColumnResult();

        return $ids;
    }

    private static function summarize(Product $product): ProductSummary
    {
        return new ProductSummary(
            (int) $product->getId(),
            $product->getSku(),
            $product->getName(),
            $product->getUnit()->label(),
            ProductKind::Goods === $product->getKind(),
            $product->isActive(),
            $product->getNetPrice(),
            $product->getVatRate(),
        );
    }
}
