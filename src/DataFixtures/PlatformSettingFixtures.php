<?php

namespace App\DataFixtures;

use App\Entity\PlatformSetting;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class PlatformSettingFixtures extends Fixture
{
    private const SETTINGS = [
        'min_order_amount' => ['1000', 'Smallest subtotal accepted at checkout, in PHP'],
        'shipping_fee' => ['500', 'Standard delivery fee, in PHP'],
        'express_shipping_fee' => ['1200', 'Express delivery fee, in PHP'],
        'free_shipping_threshold' => ['30000', 'Subtotal at which delivery becomes free, in PHP'],
        'assembly_fee' => ['800', 'Assembly service fee, in PHP'],
        'return_window_days' => ['7', 'Days after delivery a return may be requested'],
        'pending_order_escalation_hours' => ['24', 'Hours before a pending order is flagged on the dashboard'],
        'shop_open' => ['1', 'Set to 0 to stop accepting orders'],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::SETTINGS as $key => [$value, $description]) {
            $manager->persist((new PlatformSetting())->setSettingKey($key)->setSettingValue($value)->setDescription($description));
        }

        $manager->flush();
    }
}
