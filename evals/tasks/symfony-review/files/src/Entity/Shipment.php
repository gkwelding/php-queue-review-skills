<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Shipment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $labelUrl = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Order $order,
        #[ORM\Column(length: 500)]
        private string $address,
        #[ORM\Column]
        private int $weightGrams,
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

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getWeightGrams(): int
    {
        return $this->weightGrams;
    }

    public function getLabelUrl(): ?string
    {
        return $this->labelUrl;
    }

    public function setLabelUrl(string $labelUrl): void
    {
        $this->labelUrl = $labelUrl;
    }
}
