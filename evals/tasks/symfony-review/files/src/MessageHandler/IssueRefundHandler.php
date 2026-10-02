<?php

namespace App\MessageHandler;

use App\Entity\Refund;
use App\Message\IssueRefund;
use App\Repository\PaymentRepository;
use App\Service\CustomerNotifier;
use App\Service\PaymentGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class IssueRefundHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentGateway $gateway,
        private CustomerNotifier $notifier,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(IssueRefund $message): void
    {
        $payment = $this->payments->find($message->paymentId)
            ?? throw new UnrecoverableMessageHandlingException(sprintf('Payment %d does not exist', $message->paymentId));

        $refundId = $this->gateway->refund($payment->getChargeId(), $message->amountCents);

        $this->entityManager->persist(new Refund($payment, $message->amountCents, $refundId));
        $this->entityManager->flush();

        $this->notifier->refundIssued($payment->getOrder()->getCustomerEmail(), $message->amountCents);
    }
}
