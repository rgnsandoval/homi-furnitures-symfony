<?php

namespace App\Command;

use App\Repository\ProductVariantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:stock:check',
    description: 'Lists low and out-of-stock variants and exits 1 if there are any',
)]
class StockCheckCommand extends Command
{
    public function __construct(private ProductVariantRepository $variants)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $low = $this->variants->findLowStock();
        $out = $this->variants->findOutOfStock();

        if ([] === $low && [] === $out) {
            $io->success('All variants are above their low-stock threshold.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($out as $variant) {
            $rows[] = ['OUT', $variant->getSku(), (string) $variant, $variant->getStock(), $variant->getLowStockThreshold()];
        }
        foreach ($low as $variant) {
            $rows[] = ['LOW', $variant->getSku(), (string) $variant, $variant->getStock(), $variant->getLowStockThreshold()];
        }

        $io->table(['State', 'SKU', 'Variant', 'Stock', 'Threshold'], $rows);
        $io->warning(sprintf('%d out of stock, %d low.', count($out), count($low)));

        return Command::FAILURE;
    }
}
