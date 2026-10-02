<?php

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: '`order`')]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var list<array{sku: string, quantity: int, unit_price_cents: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $lines = [];

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $erpReference = null;

    public function __construct(
        #[ORM\Column(length: 180)]
        private string $customerEmail,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomerEmail(): string
    {
        return $this->customerEmail;
    }

    public function addLine(string $sku, int $quantity, int $unitPriceCents): void
    {
        $this->lines[] = ['sku' => $sku, 'quantity' => $quantity, 'unit_price_cents' => $unitPriceCents];
    }

    /** @return list<array{sku: string, quantity: int, unit_price_cents: int}> */
    public function getLines(): array
    {
        return $this->lines;
    }

    public function getTotalCents(): int
    {
        return array_sum(array_map(fn (array $line) => $line['quantity'] * $line['unit_price_cents'], $this->lines));
    }

    public function getErpReference(): ?string
    {
        return $this->erpReference;
    }

    public function setErpReference(string $erpReference): void
    {
        $this->erpReference = $erpReference;
    }
}
