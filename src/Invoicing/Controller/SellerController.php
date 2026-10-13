<?php

namespace App\Invoicing\Controller;

use App\Invoicing\Form\SellerType;
use App\Invoicing\Service\SellerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SellerController extends AbstractController
{
    #[Route('/admin/company', name: 'invoicing_seller', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, SellerProvider $provider): Response
    {
        $seller = $provider->seller();
        $form = $this->createForm(SellerType::class, $seller);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $provider->save($seller);
            $this->addFlash('success', 'Zapisano dane firmy. Pojawią się na nowych fakturach.');

            return $this->redirectToRoute('invoicing_seller', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('invoicing/seller.html.twig', ['form' => $form]);
    }
}
