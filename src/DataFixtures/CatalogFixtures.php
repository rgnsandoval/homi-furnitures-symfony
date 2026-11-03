<?php

namespace App\DataFixtures;

use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\ProductVariant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Loads the owner's CSV catalog from fixtures-data/ when it is present, otherwise the built-in sample catalog.
 * Images come from project_images/. A missing image becomes a generated placeholder, so no image is ever broken.
 */
class CatalogFixtures extends Fixture
{
    public const VARIANT_PREFIX = 'variant-';
    public const PRODUCT_PREFIX = 'product-';

    private const ROOMS = [
        'Living Room', 'Bedroom', 'Dining Room', 'Home Office', 'Kids Room', 'Outdoor',
        'Entryway', 'Kitchen', 'Bathroom', 'Storage', 'Lighting', 'Decor',
    ];

    private const BRANDS = ['Narra Works', 'Casa Luzon', 'Bahay Living', 'Mindoro Craft', 'Sampaguita Home', 'Pasig Modern'];

    /**
     * name, room index, material, price, width, depth, height, weight, assembly required
     */
    private const PRODUCTS = [
        ['Luzon Three-Seat Sofa', 0, 'Fabric', '32500.00', '210.0', '90.0', '85.0', '55.00', false],
        ['Baguio Leather Armchair', 0, 'Leather', '18900.00', '85.0', '85.0', '90.0', '28.00', false],
        ['Taal Coffee Table', 0, 'Solid wood', '7800.00', '110.0', '60.0', '45.0', '18.00', true],
        ['Cebu TV Console', 0, 'Engineered wood', '12400.00', '160.0', '40.0', '55.0', '32.00', true],
        ['Rattan Accent Chair', 0, 'Rattan', '6900.00', '70.0', '72.0', '80.0', '9.00', false],
        ['Narra Queen Bed Frame', 1, 'Solid wood', '28500.00', '165.0', '210.0', '110.0', '70.00', true],
        ['Pine Bedside Table', 1, 'Solid wood', '3900.00', '45.0', '40.0', '55.0', '8.00', true],
        ['Sliding Door Wardrobe', 1, 'Engineered wood', '24500.00', '180.0', '60.0', '200.0', '95.00', true],
        ['Upholstered Bench', 1, 'Fabric', '5400.00', '120.0', '40.0', '45.0', '14.00', true],
        ['Six-Seat Dining Table', 2, 'Solid wood', '26500.00', '180.0', '90.0', '75.0', '48.00', true],
        ['Woven Dining Chair', 2, 'Rattan', '3600.00', '48.0', '52.0', '85.0', '5.00', false],
        ['Oak Sideboard', 2, 'Solid wood', '19800.00', '150.0', '45.0', '80.0', '42.00', true],
        ['Round Bistro Table', 2, 'Metal', '8200.00', '80.0', '80.0', '75.0', '16.00', true],
        ['Standing Desk', 3, 'Metal', '17500.00', '140.0', '70.0', '120.0', '36.00', true],
        ['Ergonomic Office Chair', 3, 'Fabric', '9800.00', '65.0', '65.0', '115.0', '15.00', true],
        ['Five-Tier Bookshelf', 3, 'Engineered wood', '6400.00', '80.0', '30.0', '180.0', '24.00', true],
        ['Compact Writing Desk', 3, 'Solid wood', '8900.00', '100.0', '50.0', '75.0', '19.00', true],
        ['Kids Bunk Bed', 4, 'Solid wood', '21500.00', '100.0', '200.0', '165.0', '65.00', true],
        ['Toy Storage Cabinet', 4, 'Engineered wood', '5200.00', '90.0', '35.0', '80.0', '20.00', true],
        ['Study Table for Kids', 4, 'Engineered wood', '4800.00', '90.0', '50.0', '70.0', '15.00', true],
        ['Teak Garden Bench', 5, 'Solid wood', '11500.00', '150.0', '60.0', '85.0', '24.00', false],
        ['Rattan Patio Set', 5, 'Rattan', '22800.00', '160.0', '160.0', '80.0', '30.00', false],
        ['Folding Balcony Table', 5, 'Metal', '3400.00', '60.0', '60.0', '72.0', '7.00', false],
        ['Slim Shoe Cabinet', 6, 'Engineered wood', '4600.00', '80.0', '25.0', '110.0', '18.00', true],
        ['Entry Console Table', 6, 'Metal', '6800.00', '100.0', '30.0', '80.0', '12.00', true],
        ['Coat Rack with Bench', 6, 'Solid wood', '5900.00', '90.0', '35.0', '170.0', '16.00', true],
        ['Kitchen Island Cart', 7, 'Solid wood', '13200.00', '110.0', '60.0', '90.0', '30.00', true],
        ['Counter Stool', 7, 'Metal', '2900.00', '40.0', '40.0', '65.0', '5.00', false],
        ['Open Pantry Shelf', 7, 'Metal', '5100.00', '90.0', '40.0', '180.0', '17.00', true],
        ['Bathroom Vanity Unit', 8, 'Engineered wood', '14800.00', '80.0', '46.0', '85.0', '33.00', true],
        ['Bamboo Towel Ladder', 8, 'Solid wood', '1900.00', '50.0', '30.0', '170.0', '3.00', false],
        ['Modular Storage Cube', 9, 'Engineered wood', '2400.00', '38.0', '38.0', '38.0', '6.00', true],
        ['Steel Utility Rack', 9, 'Metal', '3800.00', '90.0', '45.0', '180.0', '20.00', true],
        ['Blanket Storage Chest', 9, 'Solid wood', '9200.00', '100.0', '50.0', '50.0', '26.00', true],
        ['Arc Floor Lamp', 10, 'Metal', '5600.00', '40.0', '40.0', '180.0', '9.00', true],
        ['Capiz Pendant Light', 10, 'Metal', '4300.00', '45.0', '45.0', '40.0', '3.00', true],
        ['Rattan Table Lamp', 10, 'Rattan', '1800.00', '25.0', '25.0', '45.0', '2.00', false],
        ['Abaca Area Rug', 11, 'Fabric', '7200.00', '200.0', '150.0', '1.5', '8.00', false],
        ['Round Wall Mirror', 11, 'Metal', '3200.00', '70.0', '4.0', '70.0', '6.00', false],
        ['Wooden Wall Shelf', 11, 'Solid wood', '1500.00', '80.0', '20.0', '4.0', '3.00', true],
    ];

    private const COLORS = [['Natural', 'Oak'], ['Walnut', 'Dark'], ['White', 'Matte'], ['Charcoal', 'Matte']];

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $this->filesystem->mkdir($this->projectDir.'/public/uploads/products');

        if (is_file($this->projectDir.'/fixtures-data/products.csv')) {
            $this->loadCsvCatalog($manager);
        } else {
            $this->loadSampleCatalog($manager);
        }

        $manager->flush();
    }

    private function loadSampleCatalog(ObjectManager $manager): void
    {
        $slugger = new AsciiSlugger();
        $rooms = [];
        foreach (self::ROOMS as $order => $name) {
            $slug = strtolower($slugger->slug($name));
            $rooms[$order] = (new Category())->setName($name)->setSlug($slug)->setDisplayOrder($order)
                ->setDescription("Furniture for your ".strtolower($name).'.');
            $manager->persist($rooms[$order]);
        }

        $brands = [];
        foreach (self::BRANDS as $name) {
            $brands[] = $brand = (new Brand())->setName($name);
            $manager->persist($brand);
        }

        foreach (self::PRODUCTS as $index => [$name, $room, $material, $price, $width, $depth, $height, $weight, $assembly]) {
            $number = $index + 1;
            $slug = strtolower($slugger->slug($name));
            $product = (new Product())
                ->setName($name)
                ->setSku(sprintf('HF-%04d', $number))
                ->setSlug($slug)
                ->setDescription("$name, made from ".strtolower($material).' and built for everyday use.')
                ->setPrice($price)
                ->setCostPrice(bcmul($price, '0.55', 2))
                ->setSalePrice(0 === $number % 7 ? bcmul($price, '0.85', 2) : null)
                ->setStatus(Product::STATUS_ACTIVE)
                ->setIsFeatured(0 === $number % 5)
                ->setMaterial($material)
                ->setDefaultColor(self::COLORS[$index % 4][0])
                ->setWidthCm($width)->setDepthCm($depth)->setHeightCm($height)->setWeightKg($weight)
                ->setAssemblyRequired($assembly)
                ->setWarrantyMonths(12)
                ->setLeadTimeDays(2 + $index % 6)
                ->setSpecifications(['Material' => $material, 'Care' => 'Wipe with a soft, dry cloth'])
                ->setCategory($rooms[$room])
                ->setBrand($brands[$index % count($brands)])
                ->setImage($this->copyImage($slug));
            $manager->persist($product);
            $this->addReference(self::PRODUCT_PREFIX.$number, $product);

            $manager->persist((new ProductImage())->setFilename((string) $product->getImage())->setAltText($name)->setProduct($product));

            // Half of the catalog offers two options; a few variants start low or sold out for the dashboard.
            $options = 0 === $index % 2 ? 2 : 1;
            for ($option = 0; $option < $options; ++$option) {
                [$color, $finish] = self::COLORS[($index + $option) % 4];
                $stock = match (true) {
                    0 === $number % 11 => 0,
                    0 === $number % 9 => 3,
                    default => 20 + $index % 15,
                };
                $variant = (new ProductVariant())
                    ->setSku(sprintf('HF-%04d-%d', $number, $option + 1))
                    ->setName($color)
                    ->setColor($color)
                    ->setFinish($finish)
                    ->setPriceAdjustment(0 === $option ? '0.00' : '500.00')
                    ->setStock($stock)
                    ->setLowStockThreshold(5);
                $product->addVariant($variant);
                $manager->persist($variant);
                $this->addReference(self::VARIANT_PREFIX.$number.'-'.($option + 1), $variant);
            }
        }
    }

    /**
     * Columns are listed in README.md.
     */
    private function loadCsvCatalog(ObjectManager $manager): void
    {
        $categories = [];
        foreach ($this->readCsv('categories.csv') as $row) {
            $categories[$row['slug']] = (new Category())
                ->setName($row['name'])
                ->setSlug($row['slug'])
                ->setDisplayOrder((int) ($row['displayOrder'] ?? 0));
            $manager->persist($categories[$row['slug']]);
        }
        foreach ($this->readCsv('categories.csv') as $row) {
            if (!empty($row['parent'])) {
                $categories[$row['slug']]->setParent($categories[$row['parent']]);
            }
        }

        $brands = [];
        foreach ($this->readCsv('brands.csv') as $row) {
            $brands[$row['name']] = (new Brand())->setName($row['name']);
            $manager->persist($brands[$row['name']]);
        }

        $products = [];
        foreach ($this->readCsv('products.csv') as $index => $row) {
            $product = (new Product())
                ->setName($row['name'])
                ->setSku($row['sku'])
                ->setSlug($row['slug'])
                ->setDescription($row['description'])
                ->setPrice($row['price'])
                ->setCostPrice($row['costPrice'])
                ->setSalePrice($row['salePrice'] ?: null)
                ->setStatus($row['status'])
                ->setIsFeatured('1' === $row['isFeatured'])
                ->setMaterial($row['material'])
                ->setDefaultColor($row['defaultColor'] ?: null)
                ->setWidthCm($row['widthCm'] ?: null)
                ->setDepthCm($row['depthCm'] ?: null)
                ->setHeightCm($row['heightCm'] ?: null)
                ->setWeightKg($row['weightKg'] ?: null)
                ->setAssemblyRequired('1' === $row['assemblyRequired'])
                ->setWarrantyMonths('' !== $row['warrantyMonths'] ? (int) $row['warrantyMonths'] : null)
                ->setLeadTimeDays((int) $row['leadTimeDays'])
                ->setSpecifications($row['specifications'] ? json_decode($row['specifications'], true, flags: JSON_THROW_ON_ERROR) : null)
                ->setCategory($categories[$row['category_slug']])
                ->setBrand($brands[$row['brand']] ?? null)
                ->setImage($this->copyImage($row['slug']));
            $manager->persist($product);
            $manager->persist((new ProductImage())->setFilename((string) $product->getImage())->setAltText($row['name'])->setProduct($product));
            $products[$row['sku']] = $product;
            $this->addReference(self::PRODUCT_PREFIX.($index + 1), $product);
        }

        $positions = array_flip(array_keys($products));
        $counts = [];
        foreach ($this->readCsv('variants.csv') as $row) {
            $product = $products[$row['product_sku']];
            $variant = (new ProductVariant())
                ->setSku($row['sku'])
                ->setName($row['name'])
                ->setColor($row['color'] ?: null)
                ->setSize($row['size'] ?: null)
                ->setFinish($row['finish'] ?: null)
                ->setPriceAdjustment($row['priceAdjustment'] ?: '0.00')
                ->setStock((int) $row['stock'])
                ->setLowStockThreshold((int) $row['lowStockThreshold'])
                ->setIsActive('1' === $row['isActive']);
            $product->addVariant($variant);
            $manager->persist($variant);

            $position = $positions[$product->getSku()] + 1;
            $counts[$position] = ($counts[$position] ?? 0) + 1;
            $this->addReference(self::VARIANT_PREFIX.$position.'-'.$counts[$position], $variant);
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function readCsv(string $file): array
    {
        $path = $this->projectDir.'/fixtures-data/'.$file;
        if (!is_file($path)) {
            throw new \RuntimeException("fixtures-data/$file is missing.");
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');
        $rows = [];
        while (false !== ($line = fgetcsv($handle, escape: ''))) {
            if ([null] !== $line) {
                $rows[] = array_combine($header, $line);
            }
        }
        fclose($handle);

        return $rows;
    }

    private function copyImage(string $slug): string
    {
        foreach (['jpg', 'png', 'webp', 'svg'] as $extension) {
            $source = $this->projectDir."/project_images/$slug.$extension";
            if (is_file($source)) {
                $this->filesystem->copy($source, $this->projectDir."/public/uploads/products/$slug.$extension", true);

                return "$slug.$extension";
            }
        }

        $name = htmlspecialchars(ucwords(str_replace('-', ' ', $slug)), ENT_XML1);
        $this->filesystem->dumpFile(
            $this->projectDir."/public/uploads/products/$slug.svg",
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect width="400" height="300" fill="#e7e0d6"/>'
            .'<text x="200" y="155" text-anchor="middle" font-family="sans-serif" font-size="20" fill="#5d5346">'.$name.'</text></svg>',
        );

        return "$slug.svg";
    }
}
