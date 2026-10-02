<?php

namespace App\MessageHandler;

use App\Message\GenerateShippingLabel;
use App\Service\CarrierClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateShippingLabelHandler
{
    public function __construct(
        private CarrierClient $carrier,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(GenerateShippingLabel $message): void
    {
        $shipment = $message->shipment;

        if ($shipment->getLabelUrl() !== null) {
            return;
        }

        $labelUrl = $this->carrier->createLabel(
            'shipment-'.$shipment->getId(),
            $shipment->getAddress(),
            $shipment->getWeightGrams(),
        );

        $shipment->setLabelUrl($labelUrl);
        $this->entityManager->flush();
    }
}
