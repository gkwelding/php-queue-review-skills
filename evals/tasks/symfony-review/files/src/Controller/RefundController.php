<?php

namespace App\Controller;

use App\Entity\Payment;
use App\Message\IssueRefund;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RefundController extends AbstractController
{
    #[Route('/payments/{id}/refunds', methods: ['POST'])]
    public function create(Payment $payment, Request $request, MessageBusInterface $bus): JsonResponse
    {
        $amountCents = min($request->getPayload()->getInt('amount_cents'), $payment->getAmountCents());

        $bus->dispatch(new IssueRefund($payment->getId(), $amountCents));

        return $this->json(['status' => 'queued'], 202);
    }
}
