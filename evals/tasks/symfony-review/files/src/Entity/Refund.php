<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Refund
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Payment $payment,
        #[ORM\Column]
        private int $amountCents,
        #[ORM\Column(length: 64)]
        private string $gatewayRefundId,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
