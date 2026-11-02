<?php

namespace App\Tests;

use App\DataFixtures\UserFixtures;
use App\Entity\Review;
use Doctrine\Persistence\ObjectManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class HardeningTest extends BaseWebTest
{
    private function stockCheck(): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:stock:check'));
        $tester->execute([]);

        return $tester;
    }

    public function testStockCheckPassesWhenStockIsHealthy(): void
    {
        $this->makeVariant(null, 50);
        $tester = $this->stockCheck();
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testStockCheckFailsAndListsProblems(): void
    {
        $low = $this->makeVariant(null, 2);
        $out = $this->makeVariant(null, 0);
        $tester = $this->stockCheck();

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString((string) $low->getSku(), $tester->getDisplay());
        $this->assertStringContainsString((string) $out->getSku(), $tester->getDisplay());
    }

    public function testFixturesFailClearlyWithoutSeedPasswords(): void
    {
        $saved = [$_ENV['ADMIN_SEED_PASSWORD'] ?? null, $_SERVER['ADMIN_SEED_PASSWORD'] ?? null];
        unset($_ENV['ADMIN_SEED_PASSWORD'], $_SERVER['ADMIN_SEED_PASSWORD']);

        try {
            $fixture = new UserFixtures(static::getContainer()->get('security.user_password_hasher'));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('ADMIN_SEED_PASSWORD');
            $fixture->load($this->createMock(ObjectManager::class));
        } finally {
            if (null !== $saved[0]) {
                $_ENV['ADMIN_SEED_PASSWORD'] = $saved[0];
            }
            if (null !== $saved[1]) {
                $_SERVER['ADMIN_SEED_PASSWORD'] = $saved[1];
            }
        }
    }

    public function testPaymentQueuesPaginateIndependently(): void
    {
        $user = $this->makeUser();
        for ($i = 0; $i < 21; ++$i) {
            $this->makeOrder($user, paymentStatus: \App\Entity\Payment::STATUS_PAID, method: \App\Entity\Payment::METHOD_GCASH);
        }

        $this->client->loginUser($this->makeAdmin());
        $crawler = $this->client->request('GET', '/admin/payments/queue');
        $this->assertCount(20, $crawler->filter('#paid-list tr'));
        $this->assertStringContainsString('paidPage=2', $crawler->filter('nav.pagination a[rel=next]')->attr('href'));

        $crawler = $this->client->request('GET', '/admin/payments/queue?paidPage=2');
        $this->assertCount(1, $crawler->filter('#paid-list tr'));
    }

    public function testProductReviewsArePaginated(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product);
        for ($i = 0; $i < 21; ++$i) {
            $this->save((new Review())->setRating(5)->setBody('Review '.$i)->setIsApproved(true)->setAuthor($this->makeUser())->setProduct($product));
        }

        $crawler = $this->client->request('GET', '/product/'.$product->getSlug());
        $this->assertCount(20, $crawler->filter('.review'));
        $crawler = $this->client->request('GET', '/product/'.$product->getSlug().'?page=2');
        $this->assertCount(1, $crawler->filter('.review'));
    }

    public function testNoMoneyCodeUsesFloats(): void
    {
        $root = static::getContainer()->getParameter('kernel.project_dir');
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('php' === $file->getExtension() && preg_match('/\(float\)|\bfloatval\(|\bround\(|\bnumber_format\(/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testQueriesLiveOnlyInRepositories(): void
    {
        $root = static::getContainer()->getParameter('kernel.project_dir');
        $offenders = [];
        foreach (['Controller', 'Service'] as $folder) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src/'.$folder, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (preg_match('/createQueryBuilder|createQuery\(|createNativeQuery|getConnection\(\)/', (string) file_get_contents($file->getPathname()))) {
                    $offenders[] = $file->getFilename();
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
