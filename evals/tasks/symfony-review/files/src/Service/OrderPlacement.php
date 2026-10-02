<?php

namespace App\Service;

use App\Entity\Order;
use App\Message\ExportOrderToErp;
use App\Message\ReserveStock;
use App\Message\SendOrderConfirmation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class OrderPlacement
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @param list<array{sku: string, quantity: int, unit_price_cents: int}> $lines
     */
    public function place(string $customerEmail, array $lines): Order
    {
        $order = $this->entityManager->wrapInTransaction(function () use ($customerEmail, $lines): Order {
            $order = new Order($customerEmail);

            foreach ($lines as $line) {
                $order->addLine($line['sku'], $line['quantity'], $line['unit_price_cents']);
            }

            $this->entityManager->persist($order);
            $this->entityManager->flush();

            $this->bus->dispatch(new ReserveStock($order->getId()));
            $this->bus->dispatch(new ExportOrderToErp($order->getId()));

            return $order;
        });

        $this->bus->dispatch(new SendOrderConfirmation($order->getId()));

        return $order;
    }
}
