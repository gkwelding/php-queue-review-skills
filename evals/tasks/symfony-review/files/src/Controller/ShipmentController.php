<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Shipment;
use App\Message\GenerateShippingLabel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ShipmentController extends AbstractController
{
    #[Route('/orders/{id}/shipments', methods: ['POST'])]
    public function create(Order $order, Request $request, EntityManagerInterface $entityManager, MessageBusInterface $bus): JsonResponse
    {
        $shipment = new Shipment($order, $request->getPayload()->getString('address'), $request->getPayload()->getInt('weight_grams'));

        $entityManager->persist($shipment);
        $entityManager->flush();

        $bus->dispatch(new GenerateShippingLabel($shipment));

        return $this->json(['id' => $shipment->getId()], 201);
    }
}
