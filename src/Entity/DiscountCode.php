<?php

namespace App\Entity;

use App\Repository\DiscountCodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: DiscountCodeRepository::class)]
#[UniqueEntity(fields: ['code'])]
class DiscountCode
{
    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';
    public const TYPES = [self::TYPE_PERCENT, self::TYPE_FIXED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 40)]
    #[ORM\Column(length: 40, unique: true)]
    private ?string $code = null;

    #[Assert\Choice(choices: self::TYPES)]
    #[ORM\Column(length: 20)]
    private ?string $discountType = null;

    #[Assert\NotBlank]
    #[Assert\Positive]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $discountValue = null;

    #[Assert\PositiveOrZero]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $minSpend = null;

    #[Assert\Positive]
    #[ORM\Column(nullable: true)]
    private ?int $usageLimit = null;

    #[Assert\PositiveOrZero]
    #[ORM\Column]
    private ?int $timesUsed = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    private ?bool $isActive = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getDiscountType(): ?string
    {
        return $this->discountType;
    }

    public function setDiscountType(string $discountType): static
    {
        $this->discountType = $discountType;

        return $this;
    }

    public function getDiscountValue(): ?string
    {
        return $this->discountValue;
    }

    public function setDiscountValue(string $discountValue): static
    {
        $this->discountValue = $discountValue;

        return $this;
    }

    public function getMinSpend(): ?string
    {
        return $this->minSpend;
    }

    public function setMinSpend(?string $minSpend): static
    {
        $this->minSpend = $minSpend;

        return $this;
    }

    public function getUsageLimit(): ?int
    {
        return $this->usageLimit;
    }

    public function setUsageLimit(?int $usageLimit): static
    {
        $this->usageLimit = $usageLimit;

        return $this;
    }

    public function getTimesUsed(): ?int
    {
        return $this->timesUsed;
    }

    public function setTimesUsed(int $timesUsed): static
    {
        $this->timesUsed = $timesUsed;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

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
}
