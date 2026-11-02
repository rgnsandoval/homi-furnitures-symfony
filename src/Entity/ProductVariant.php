<?php

namespace App\Entity;

use App\Repository\ProductVariantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: ProductVariantRepository::class)]
#[UniqueEntity(fields: ['sku'])]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    #[ORM\Column(length: 60, unique: true)]
    private ?string $sku = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[ORM\Column(length: 120)]
    private ?string $name = null;

    #[Assert\Length(max: 40)]
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $color = null;

    #[Assert\Length(max: 40)]
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $size = null;

    #[Assert\Length(max: 40)]
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $finish = null;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $priceAdjustment = '0.00';

    #[Assert\PositiveOrZero]
    #[ORM\Column]
    private ?int $stock = 0;

    #[Assert\PositiveOrZero]
    #[ORM\Column]
    private ?int $lowStockThreshold = 5;

    #[ORM\Column]
    private ?bool $isActive = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[Assert\NotNull]
    #[ORM\ManyToOne(inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(string $sku): static
    {
        $this->sku = $sku;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getSize(): ?string
    {
        return $this->size;
    }

    public function setSize(?string $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getFinish(): ?string
    {
        return $this->finish;
    }

    public function setFinish(?string $finish): static
    {
        $this->finish = $finish;

        return $this;
    }

    public function getPriceAdjustment(): ?string
    {
        return $this->priceAdjustment;
    }

    public function setPriceAdjustment(string $priceAdjustment): static
    {
        $this->priceAdjustment = $priceAdjustment;

        return $this;
    }

    public function getStock(): ?int
    {
        return $this->stock;
    }

    public function setStock(int $stock): static
    {
        $this->stock = $stock;

        return $this;
    }

    public function getLowStockThreshold(): ?int
    {
        return $this->lowStockThreshold;
    }

    public function setLowStockThreshold(int $lowStockThreshold): static
    {
        $this->lowStockThreshold = $lowStockThreshold;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }
}
