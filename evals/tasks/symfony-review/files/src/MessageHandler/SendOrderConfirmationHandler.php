<?php

namespace App\MessageHandler;

use App\Message\SendOrderConfirmation;
use App\Repository\OrderRepository;
use App\Service\CustomerNotifier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class SendOrderConfirmationHandler
{
    public function __construct(
        private OrderRepository $orders,
        private CustomerNotifier $notifier,
    ) {
    }

    public function __invoke(SendOrderConfirmation $message): void
    {
        $order = $this->orders->find($message->orderId)
            ?? throw new UnrecoverableMessageHandlingException(sprintf('Order %d does not exist', $message->orderId));

        $this->notifier->orderConfirmed($order->getCustomerEmail(), $order->getId(), $order->getTotalCents());
    }
}
