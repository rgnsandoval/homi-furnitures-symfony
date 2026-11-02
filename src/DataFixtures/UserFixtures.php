<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public const ADMIN = 'user-admin';
    public const CUSTOMERS = ['user-customer-1', 'user-customer-2', 'user-customer-3'];

    public function __construct(private UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $admin = $this->createUser('admin@homi.test', 'Homi Admin', $this->seedPassword('ADMIN_SEED_PASSWORD'), ['ROLE_ADMIN']);
        $manager->persist($admin);
        $this->addReference(self::ADMIN, $admin);

        $customerPassword = $this->seedPassword('CUSTOMER_SEED_PASSWORD');
        $names = ['Maria Santos', 'Jose Reyes', 'Ana Cruz'];

        foreach (self::CUSTOMERS as $index => $reference) {
            $customer = $this->createUser(sprintf('customer%d@homi.test', $index + 1), $names[$index], $customerPassword, []);
            $manager->persist($customer);
            $this->addReference($reference, $customer);
        }

        $manager->flush();
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, string $fullName, string $plainPassword, array $roles): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFullName($fullName)
            ->setRoles($roles)
            ->setIsVerified(true);

        return $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
    }

    private function seedPassword(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';

        if ('' === $value) {
            throw new \RuntimeException(sprintf('Set %s in .env.local before loading fixtures (see .env.local.example).', $name));
        }

        return $value;
    }
}
