<?php

namespace App\MessageHandler;

use App\Message\ExportOrderToErp;
use App\Repository\OrderRepository;
use App\Service\ErpClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class ExportOrderToErpHandler
{
    public function __construct(
        private OrderRepository $orders,
        private ErpClient $erp,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ExportOrderToErp $message): void
    {
        $order = $this->orders->find($message->orderId)
            ?? throw new UnrecoverableMessageHandlingException(sprintf('Order %d does not exist', $message->orderId));

        if ($order->getErpReference() !== null) {
            return;
        }

        $reference = $this->erp->upsertSalesOrder('web-'.$order->getId(), [
            'customer_email' => $order->getCustomerEmail(),
            'lines' => $order->getLines(),
            'total_cents' => $order->getTotalCents(),
        ]);

        $order->setErpReference($reference);
        $this->entityManager->flush();
    }
}
