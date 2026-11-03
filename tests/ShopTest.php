<?php

namespace App\Tests;

use App\Entity\Address;
use App\Entity\CartItem;
use App\Entity\Inquiry;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\WishlistItem;

class ShopTest extends BaseWebTest
{
    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    private function addToCart(int $variantId, int $quantity, string $productSlug): void
    {
        $crawler = $this->client->request('GET', '/product/'.$productSlug);
        $form = $crawler->filter('#add-to-cart')->form();
        $form['variant']->setValue((string) $variantId);
        $form['quantity'] = (string) $quantity;
        $this->client->submit($form);
    }

    public function testCatalogListsActiveProductsAndFilters(): void
    {
        $room = $this->makeCategory('living-room');
        $active = $this->makeProduct($room);
        $draft = $this->makeProduct($room, set: ['status' => Product::STATUS_DRAFT]);
        $other = $this->makeProduct();

        $crawler = $this->client->request('GET', '/shop');
        $this->assertResponseIsSuccessful();
        $text = $crawler->filter('#products')->text();
        $this->assertStringContainsString($active->getName(), $text);
        $this->assertStringNotContainsString($draft->getName().' ', $text.' ');

        $crawler = $this->client->request('GET', '/shop?room=living-room&sort=price_asc&maxPrice=not-a-number');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString($other->getName().' ', $crawler->filter('#products')->text().' ');
    }

    public function testCatalogIsPaginatedAtTwentyPerPage(): void
    {
        $room = $this->makeCategory('big-room');
        for ($i = 0; $i < 21; ++$i) {
            $this->makeProduct($room);
        }

        $crawler = $this->client->request('GET', '/shop?room=big-room');
        $this->assertCount(20, $crawler->filter('#products li'));
        $this->assertSelectorExists('.pagination a[rel=next]');

        $crawler = $this->client->request('GET', '/shop?room=big-room&page=2');
        $this->assertCount(1, $crawler->filter('#products li'));
    }

    public function testProductPageHidesSelectorForSingleVariant(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product);

        $this->client->request('GET', '/product/'.$product->getSlug());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('#variant-selector');

        $this->makeVariant($product);
        $this->em->clear();
        $this->client->request('GET', '/product/'.$product->getSlug());
        $this->assertSelectorExists('#variant-selector');
    }

    public function testDraftProductPageIsNotFound(): void
    {
        $product = $this->makeProduct(set: ['status' => Product::STATUS_DRAFT]);
        $this->client->request('GET', '/product/'.$product->getSlug());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCustomerAreasRequireLogin(): void
    {
        foreach (['/cart', '/wishlist', '/account', '/account/addresses'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseRedirects('/login', null, $url);
        }
    }

    public function testCartMergesDuplicatesAndComputesExactTotals(): void
    {
        $product = $this->makeProduct(null, '1000.10', '999.99');
        $variant = $this->makeVariant($product, 10, '100.01');
        $user = $this->makeUser();
        $this->client->loginUser($user);

        $this->addToCart($variant->getId(), 2, $product->getSlug());
        $this->addToCart($variant->getId(), 1, $product->getSlug());

        $rows = $this->em->getRepository(CartItem::class)->findBy(['owner' => $user]);
        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]->getQuantity());

        $crawler = $this->client->request('GET', '/cart');
        $this->assertSame('₱3,300.00', $crawler->filter('#subtotal')->text());
    }

    public function testCartRejectsMoreThanStock(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, 2);
        $user = $this->makeUser();
        $this->client->loginUser($user);

        $this->addToCart($variant->getId(), 3, $product->getSlug());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Only 2');
        $this->assertCount(0, $this->em->getRepository(CartItem::class)->findBy(['owner' => $user]));
    }

    public function testCartUpdateRemoveAndOwnership(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, 5);
        $user = $this->makeUser();
        $this->client->loginUser($user);
        $this->addToCart($variant->getId(), 1, $product->getSlug());

        $crawler = $this->client->request('GET', '/cart');
        $form = $crawler->selectButton('Update')->form(['quantity' => '4']);
        $this->client->submit($form);
        $this->em->clear();
        $item = $this->em->getRepository(CartItem::class)->findOneBy(['owner' => $user]);
        $this->assertSame(4, $item->getQuantity());

        $crawler = $this->client->request('GET', '/cart');
        $token = $crawler->selectButton('Remove')->form()->get('_token')->getValue();

        $this->client->loginUser($this->makeUser());
        $this->client->request('POST', '/cart/'.$item->getId().'/remove', ['_token' => $token]);
        $this->assertResponseStatusCodeSame(404);
        $this->assertNotNull($this->em->getRepository(CartItem::class)->find($item->getId()));

        $this->client->loginUser($this->em->getRepository($user::class)->find($user->getId()));
        $crawler = $this->client->request('GET', '/cart');
        $this->client->submit($crawler->selectButton('Remove')->form());
        $this->em->clear();
        $this->assertNull($this->em->getRepository(CartItem::class)->find($item->getId()));
    }

    public function testCartRequiresCsrfToken(): void
    {
        $variant = $this->makeVariant();
        $this->client->loginUser($this->makeUser());
        $this->client->request('POST', '/cart/add', ['variant' => $variant->getId(), 'quantity' => 1, '_token' => 'bad']);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testWishlistToggleKeepsOneRow(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product);
        $user = $this->makeUser();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/product/'.$product->getSlug());
        $this->client->submit($crawler->selectButton('Add to wishlist')->form());
        $this->assertCount(1, $this->em->getRepository(WishlistItem::class)->findBy(['owner' => $user]));

        $crawler = $this->client->request('GET', '/wishlist');
        $this->assertCount(1, $crawler->filter('.wishlist-item'));
        $this->client->submit($crawler->selectButton('Remove')->form());
        $this->em->clear();
        $this->assertCount(0, $this->em->getRepository(WishlistItem::class)->findBy(['owner' => $user]));
    }

    public function testWishlistFromACardReturnsOnlyToLocalPages(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product);
        $this->client->loginUser($this->makeUser());

        $crawler = $this->client->request('GET', '/shop');
        $form = $crawler->filter('#products form[action$="/toggle"]')->form();
        $this->assertSame('/shop', $form->get('_back')->getValue());
        $this->client->submit($form);
        $this->assertResponseRedirects('/shop', 303);

        foreach (['//evil.test/x', '/\\evil.test', 'https://evil.test/'] as $target) {
            $form['_back'] = $target;
            $this->client->submit($form);
            $this->assertResponseRedirects('/wishlist', 303);
        }
    }

    public function testHomeAndShopRenderProductCards(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product);

        $crawler = $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#rooms');

        $crawler = $this->client->request('GET', '/shop');
        $this->assertStringContainsString($product->getName(), $crawler->filter('#products')->text());
        $this->assertCount(1, $crawler->filter('#products [data-action="quick-view#open"]'));
    }

    public function testAddressesAreScopedAndKeepOneDefault(): void
    {
        $user = $this->makeUser();
        $this->client->loginUser($user);

        foreach (['First', 'Second'] as $label) {
            $crawler = $this->client->request('GET', '/account/addresses/new');
            $this->client->submit($crawler->selectButton('Save')->form([
                'address[label]' => $label,
                'address[recipient]' => 'Test User',
                'address[phone]' => '0917',
                'address[line1]' => '1 Mabini St',
                'address[city]' => 'Manila',
                'address[province]' => 'Metro Manila',
                'address[postalCode]' => '1000',
                'address[isDefault]' => 'Second' === $label,
            ]));
            $this->assertResponseRedirects('/account/addresses');
        }

        $this->em->clear();
        $defaults = $this->em->getRepository(Address::class)->findBy(['isDefault' => true]);
        $this->assertCount(1, $defaults);
        $this->assertSame('Second', $defaults[0]->getLabel());

        $this->client->loginUser($this->makeUser());
        $this->client->request('GET', '/account/addresses/'.$defaults[0]->getId());
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/account/addresses/'.$defaults[0]->getId().'/edit');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAddressUsedByOrderCannotBeDeleted(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user);
        $id = $order->getShippingAddress()->getId();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/account/addresses/'.$id);
        $this->client->submit($crawler->selectButton('Delete')->form());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'used by an order');
    }

    public function testReviewIsPendingWithPhotoAndOnePerAuthor(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product);
        $user = $this->makeUser();
        $this->client->loginUser($user);

        $path = sys_get_temp_dir().'/review-'.uniqid().'.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $crawler = $this->client->request('GET', '/product/'.$product->getSlug());
        $form = $crawler->selectButton('Submit review')->form(['review_form[rating]' => '5', 'review_form[body]' => 'Lovely sofa']);
        $form['review_form[photoFile]']->upload($path);
        $this->client->submit($form);
        $this->assertResponseRedirects();
        unlink($path);

        $review = $this->em->getRepository(Review::class)->findOneBy(['author' => $user]);
        $this->assertFalse($review->isApproved());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $review->getPhoto());
        $stored = static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/reviews/'.$review->getPhoto();
        $this->assertFileExists($stored);
        unlink($stored);

        $crawler = $this->client->request('GET', '/product/'.$product->getSlug());
        $this->assertCount(0, $crawler->selectButton('Submit review'));
        $this->assertSelectorTextNotContains('body', 'Lovely sofa');
    }

    public function testReviewRejectsNonImageUpload(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product);
        $this->client->loginUser($this->makeUser());

        $path = sys_get_temp_dir().'/review-'.uniqid().'.png';
        file_put_contents($path, '<?php echo "not an image";');

        $crawler = $this->client->request('GET', '/product/'.$product->getSlug());
        $form = $crawler->selectButton('Submit review')->form(['review_form[rating]' => '5', 'review_form[body]' => 'Bad file']);
        $form['review_form[photoFile]']->upload($path);
        $this->client->submit($form);
        unlink($path);

        $this->assertResponseStatusCodeSame(422);
        $this->assertCount(0, $this->em->getRepository(Review::class)->findAll());
    }

    public function testContactFormStoresInquiry(): void
    {
        $crawler = $this->client->request('GET', '/contact');
        $this->client->submit($crawler->selectButton('Send message')->form([
            'contact_form[name]' => 'Ana',
            'contact_form[email]' => 'ana@homi.test',
            'contact_form[subject]' => 'Delivery',
            'contact_form[message]' => 'Do you deliver to Cebu?',
        ]));

        $this->assertResponseRedirects('/contact');
        $this->assertCount(1, $this->em->getRepository(Inquiry::class)->findAll());
    }

    public function testContactHoneypotStoresNothing(): void
    {
        $crawler = $this->client->request('GET', '/contact');
        $this->client->submit($crawler->selectButton('Send message')->form([
            'contact_form[name]' => 'Bot',
            'contact_form[email]' => 'bot@homi.test',
            'contact_form[subject]' => 'Spam',
            'contact_form[message]' => 'Spam',
            'contact_form[website]' => 'http://spam.test',
        ]));

        $this->assertResponseRedirects('/contact');
        $this->assertCount(0, $this->em->getRepository(Inquiry::class)->findAll());
    }

    public function testContactFormIsRateLimited(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $crawler = $this->client->request('GET', '/contact');
            $this->client->submit($crawler->selectButton('Send message')->form([
                'contact_form[name]' => 'Ana',
                'contact_form[email]' => 'ana@homi.test',
                'contact_form[subject]' => 'Hello '.$i,
                'contact_form[message]' => 'Message',
            ]));
        }

        $this->assertCount(5, $this->em->getRepository(Inquiry::class)->findAll());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Too many messages');
    }

    public function testGuideCalculatesFit(): void
    {
        $fits = $this->makeProduct(set: ['widthCm' => '100.0', 'depthCm' => '50.0']);
        $this->makeVariant($fits);
        $tooBig = $this->makeProduct(set: ['widthCm' => '300.0', 'depthCm' => '100.0']);
        $this->makeVariant($tooBig);

        $crawler = $this->client->request('GET', '/guide?roomWidth=2.5&roomDepth=2');
        $this->assertSelectorTextContains('#fit-result', '150.0 cm wide and 80.0 cm deep');
        $text = $crawler->filter('#suggestions')->text();
        $this->assertStringContainsString($fits->getName(), $text);
        $this->assertStringNotContainsString($tooBig->getName(), $text);
    }

    public function testProfileCanBeEdited(): void
    {
        $user = $this->makeUser();
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->selectButton('Save profile')->form(['profile_form[fullName]' => 'Renamed']));
        $this->em->clear();
        $this->assertSame('Renamed', $this->em->getRepository($user::class)->find($user->getId())->getFullName());
    }

    public function testMoneyFilterFormatsLargeAmountsExactly(): void
    {
        $extension = new \App\Twig\MoneyExtension();
        $this->assertSame('₱1,234,567.89', $extension->peso('1234567.89'));
        $this->assertSame('₱0.00', $extension->peso(null));
        $this->assertSame('-₱1,000.50', $extension->peso('-1000.5'));
    }
}
