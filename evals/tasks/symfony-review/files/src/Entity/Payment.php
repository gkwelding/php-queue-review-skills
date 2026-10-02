<?php

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Order $order,
        #[ORM\Column(length: 64)]
        private string $chargeId,
        #[ORM\Column]
        private int $amountCents,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getChargeId(): string
    {
        return $this->chargeId;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }
}
