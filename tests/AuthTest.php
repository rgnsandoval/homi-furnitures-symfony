<?php

namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

class AuthTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    private function createUser(string $email = 'user@homi.test', bool $active = true): User
    {
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $user = (new User())->setEmail($email)->setFullName('Test User')->setIsActive($active);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function login(string $email, string $password = self::PASSWORD): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => $email, '_password' => $password]);
    }

    public function testRegistrationCreatesUnverifiedUserAndSendsEmail(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', [
            'registration_form[fullName]' => 'New Customer',
            'registration_form[email]' => 'new@homi.test',
            'registration_form[phone]' => '09170000000',
            'registration_form[plainPassword]' => self::PASSWORD,
            'registration_form[agreeTerms]' => true,
        ]);

        $this->assertResponseRedirects('/login');
        $this->assertEmailCount(1);

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'new@homi.test']);
        $this->assertNotNull($user);
        $this->assertFalse($user->isVerified());
        $this->assertTrue($user->isActive());
        $this->assertSame(['ROLE_USER'], $user->getRoles());
        $this->assertNotSame(self::PASSWORD, $user->getPassword());
    }

    public function testSignedEmailLinkVerifiesUser(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', [
            'registration_form[fullName]' => 'New Customer',
            'registration_form[email]' => 'verify@homi.test',
            'registration_form[plainPassword]' => self::PASSWORD,
            'registration_form[agreeTerms]' => true,
        ]);

        $html = $this->getMailerMessage()->getHtmlBody();
        $this->assertSame(1, preg_match('/href="([^"]*verify\/email[^"]*)"/', $html, $link));
        $this->client->request('GET', html_entity_decode($link[1]));
        $this->assertResponseRedirects('/');

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'verify@homi.test']);
        $this->assertTrue($user->isVerified());
    }

    public function testTamperedVerificationLinkIsRejected(): void
    {
        $user = $this->createUser('tamper@homi.test');
        $this->client->request('GET', '/verify/email?id='.$user->getId().'&signature=bad&expires=9999999999&token=bad');
        $this->assertResponseRedirects('/register');

        $this->em->clear();
        $this->assertFalse($this->em->getRepository(User::class)->find($user->getId())->isVerified());
    }

    public function testDuplicateEmailIsRejectedWithFriendlyError(): void
    {
        $this->createUser('taken@homi.test');

        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', [
            'registration_form[fullName]' => 'Other',
            'registration_form[email]' => 'taken@homi.test',
            'registration_form[plainPassword]' => self::PASSWORD,
            'registration_form[agreeTerms]' => true,
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'There is already an account with this email');
    }

    public function testLoginAndLogout(): void
    {
        $this->createUser();
        $this->login('user@homi.test');
        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Log out');

        $this->client->request('GET', '/logout');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Log in');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->createUser();
        $this->login('user@homi.test', 'wrong-password');
        $this->client->followRedirect();
        $this->assertSelectorExists('.alert-danger');
    }

    public function testLoginIsThrottledAfterFiveAttempts(): void
    {
        $this->createUser();

        for ($i = 0; $i < 5; ++$i) {
            $this->login('user@homi.test', 'wrong-password');
        }

        $this->login('user@homi.test');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'Too many failed login attempts');
    }

    public function testDeactivatedUserCannotLogIn(): void
    {
        $this->createUser('off@homi.test', false);
        $this->login('off@homi.test');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'deactivated');
    }

    public function testOpenSessionEndsWhenUserIsDeactivated(): void
    {
        $user = $this->createUser();
        $this->client->loginUser($user);
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();

        $this->em->getRepository(User::class)->find($user->getId())->setIsActive(false);
        $this->em->flush();

        $this->client->request('GET', '/');
        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertSelectorTextNotContains('body', 'You are logged in');
    }

    public function testAdminRoleIncludesUserRole(): void
    {
        $hierarchy = static::getContainer()->get(RoleHierarchyInterface::class);
        $this->assertContains('ROLE_USER', $hierarchy->getReachableRoleNames(['ROLE_ADMIN']));
    }

    public function testRememberMeCookieIsSetWhenRequested(): void
    {
        $this->createUser();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', [
            '_username' => 'user@homi.test',
            '_password' => self::PASSWORD,
            '_remember_me' => true,
        ]);

        $this->assertNotNull($this->client->getCookieJar()->get('REMEMBERME'));
    }
}
