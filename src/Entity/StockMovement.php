<?php

namespace App\Entity;

use App\Repository\StockMovementRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: StockMovementRepository::class)]
class StockMovement
{
    public const REASON_RESTOCK = 'restock';
    public const REASON_DAMAGED = 'damaged';
    public const REASON_CORRECTION = 'correction';
    public const REASON_ORDER = 'order';
    public const REASON_RETURN = 'return';
    public const REASONS = [self::REASON_RESTOCK, self::REASON_DAMAGED, self::REASON_CORRECTION, self::REASON_ORDER, self::REASON_RETURN];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotEqualTo(0)]
    #[ORM\Column]
    private ?int $quantityChange = null;

    #[Assert\Choice(choices: self::REASONS)]
    #[ORM\Column(length: 20)]
    private ?string $movementReason = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?ProductVariant $variant = null;

    #[ORM\ManyToOne]
    private ?User $adminUser = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuantityChange(): ?int
    {
        return $this->quantityChange;
    }

    public function setQuantityChange(int $quantityChange): static
    {
        $this->quantityChange = $quantityChange;

        return $this;
    }

    public function getMovementReason(): ?string
    {
        return $this->movementReason;
    }

    public function setMovementReason(string $movementReason): static
    {
        $this->movementReason = $movementReason;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getVariant(): ?ProductVariant
    {
        return $this->variant;
    }

    public function setVariant(?ProductVariant $variant): static
    {
        $this->variant = $variant;

        return $this;
    }

    public function getAdminUser(): ?User
    {
        return $this->adminUser;
    }

    public function setAdminUser(?User $adminUser): static
    {
        $this->adminUser = $adminUser;

        return $this;
    }
}
