<?php

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[UniqueEntity(fields: ['orderNumber'])]
#[UniqueEntity(fields: ['idempotencyKey'])]
#[ORM\Table(name: 'orders')]
class Order
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PACKED = 'packed';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_PACKED, self::STATUS_SHIPPED, self::STATUS_DELIVERED, self::STATUS_CANCELLED];
    public const SHIPPING_STANDARD = 'standard';
    public const SHIPPING_EXPRESS = 'express';
    public const SHIPPING_PICKUP = 'pickup';
    public const SHIPPING_METHODS = [self::SHIPPING_STANDARD, self::SHIPPING_EXPRESS, self::SHIPPING_PICKUP];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    private ?string $orderNumber = null;

    #[Assert\Choice(choices: self::STATUSES)]
    #[ORM\Column(length: 20)]
    private ?string $status = self::STATUS_PENDING;

    #[Assert\Choice(choices: self::SHIPPING_METHODS)]
    #[ORM\Column(length: 20)]
    private ?string $shippingMethod = null;

    #[Assert\PositiveOrZero]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $subtotal = null;

    #[Assert\PositiveOrZero]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $shippingFee = '0.00';

    #[Assert\PositiveOrZero]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $assemblyFee = '0.00';

    #[Assert\PositiveOrZero]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $discount = '0.00';

    #[Assert\PositiveOrZero]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $total = null;

    #[ORM\Column]
    private ?bool $assemblyRequested = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $estimatedDeliveryDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $trackingNumber = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $internalNote = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cancelReason = null;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[ORM\Column(length: 36, unique: true)]
    private ?string $idempotencyKey = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $customer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Address $shippingAddress = null;

    #[ORM\ManyToOne]
    private ?DiscountCode $discountCode = null;

    /**
     * @var Collection<int, OrderItem>
     */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'parentOrder', orphanRemoval: true)]
    private Collection $items;

    #[ORM\OneToOne(mappedBy: 'parentOrder', cascade: ['persist', 'remove'])]
    private ?Payment $payment = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderNumber(): ?string
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(string $orderNumber): static
    {
        $this->orderNumber = $orderNumber;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getShippingMethod(): ?string
    {
        return $this->shippingMethod;
    }

    public function setShippingMethod(string $shippingMethod): static
    {
        $this->shippingMethod = $shippingMethod;

        return $this;
    }

    public function getSubtotal(): ?string
    {
        return $this->subtotal;
    }

    public function setSubtotal(string $subtotal): static
    {
        $this->subtotal = $subtotal;

        return $this;
    }

    public function getShippingFee(): ?string
    {
        return $this->shippingFee;
    }

    public function setShippingFee(string $shippingFee): static
    {
        $this->shippingFee = $shippingFee;

        return $this;
    }

    public function getAssemblyFee(): ?string
    {
        return $this->assemblyFee;
    }

    public function setAssemblyFee(string $assemblyFee): static
    {
        $this->assemblyFee = $assemblyFee;

        return $this;
    }

    public function getDiscount(): ?string
    {
        return $this->discount;
    }

    public function setDiscount(string $discount): static
    {
        $this->discount = $discount;

        return $this;
    }

    public function getTotal(): ?string
    {
        return $this->total;
    }

    public function setTotal(string $total): static
    {
        $this->total = $total;

        return $this;
    }

    public function isAssemblyRequested(): ?bool
    {
        return $this->assemblyRequested;
    }

    public function setAssemblyRequested(bool $assemblyRequested): static
    {
        $this->assemblyRequested = $assemblyRequested;

        return $this;
    }

    public function getEstimatedDeliveryDate(): ?\DateTimeImmutable
    {
        return $this->estimatedDeliveryDate;
    }

    public function setEstimatedDeliveryDate(?\DateTimeImmutable $estimatedDeliveryDate): static
    {
        $this->estimatedDeliveryDate = $estimatedDeliveryDate;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getTrackingNumber(): ?string
    {
        return $this->trackingNumber;
    }

    public function setTrackingNumber(?string $trackingNumber): static
    {
        $this->trackingNumber = $trackingNumber;

        return $this;
    }

    public function getInternalNote(): ?string
    {
        return $this->internalNote;
    }

    public function setInternalNote(?string $internalNote): static
    {
        $this->internalNote = $internalNote;

        return $this;
    }

    public function getCancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function setCancelReason(?string $cancelReason): static
    {
        $this->cancelReason = $cancelReason;

        return $this;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(string $idempotencyKey): static
    {
        $this->idempotencyKey = $idempotencyKey;

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

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTimeImmutable $deliveredAt): static
    {
        $this->deliveredAt = $deliveredAt;

        return $this;
    }

    public function getCustomer(): ?User
    {
        return $this->customer;
    }

    public function setCustomer(?User $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getShippingAddress(): ?Address
    {
        return $this->shippingAddress;
    }

    public function setShippingAddress(?Address $shippingAddress): static
    {
        $this->shippingAddress = $shippingAddress;

        return $this;
    }

    public function getDiscountCode(): ?DiscountCode
    {
        return $this->discountCode;
    }

    public function setDiscountCode(?DiscountCode $discountCode): static
    {
        $this->discountCode = $discountCode;

        return $this;
    }

    /**
     * @return Collection<int, OrderItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(OrderItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setParentOrder($this);
        }

        return $this;
    }

    public function removeItem(OrderItem $item): static
    {
        if ($this->items->removeElement($item)) {
            // set the owning side to null (unless already changed)
            if ($item->getParentOrder() === $this) {
                $item->setParentOrder(null);
            }
        }

        return $this;
    }

    public function getPayment(): ?Payment
    {
        return $this->payment;
    }

    public function setPayment(Payment $payment): static
    {
        // set the owning side of the relation if necessary
        if ($payment->getParentOrder() !== $this) {
            $payment->setParentOrder($this);
        }

        $this->payment = $payment;

        return $this;
    }
}
