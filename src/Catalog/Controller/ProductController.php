<?php

namespace App\Catalog\Controller;

use App\Catalog\Dto\ProductFilter;
use App\Catalog\Entity\Product;
use App\Catalog\Form\ProductType;
use App\Catalog\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/catalog/products', name: 'catalog_product_')]
final class ProductController extends AbstractController
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[MapQueryString] ProductFilter $filter = new ProductFilter()): Response
    {
        return $this->render('catalog/product/index.html.twig', [
            'filter' => $filter,
            'page' => $this->products->search($filter),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_CATALOG_EDIT')]
    public function new(Request $request): Response
    {
        return $this->edit($request, new Product());
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_CATALOG_EDIT')]
    public function edit(Request $request, Product $product): Response
    {
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->products->save($product);
            $this->addFlash('success', \sprintf('Zapisano produkt %s.', $product->getSku()));

            return $this->redirectToRoute('catalog_product_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('catalog/product/form.html.twig', [
            'form' => $form,
            'product' => $product,
        ]);
    }

    /**
     * Products are never deleted, because documents refer to them. They are withdrawn instead.
     */
    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    #[IsGranted('ROLE_CATALOG_EDIT')]
    #[IsCsrfTokenValid('catalog_product_toggle')]
    public function toggle(Product $product): Response
    {
        $product->setActive(!$product->isActive());
        $this->products->save($product);
        $this->addFlash('success', \sprintf(
            $product->isActive() ? 'Przywrócono produkt %s.' : 'Wycofano produkt %s.',
            $product->getSku(),
        ));

        return $this->redirectToRoute('catalog_product_index', status: Response::HTTP_SEE_OTHER);
    }
}
