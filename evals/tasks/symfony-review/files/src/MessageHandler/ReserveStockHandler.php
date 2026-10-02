<?php

namespace App\MessageHandler;

use App\Message\ReserveStock;
use App\Repository\OrderRepository;
use App\Service\UnknownSkuException;
use App\Service\WarehouseClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class ReserveStockHandler
{
    public function __construct(
        private OrderRepository $orders,
        private WarehouseClient $warehouse,
    ) {
    }

    public function __invoke(ReserveStock $message): void
    {
        $order = $this->orders->find($message->orderId)
            ?? throw new UnrecoverableMessageHandlingException(sprintf('Order %d does not exist', $message->orderId));

        try {
            $this->warehouse->reserve('order-'.$order->getId(), $order->getLines());
        } catch (UnknownSkuException $e) {
            throw new RecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }
    }
}
