<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FORK (FORK.md): the prelogin must not tell a known address from an unknown
 * one by how it is spelled.
 *
 * The placeholder salt is keyed on the lower-cased address, and the lookup
 * used to be case-sensitive. A known address in another case therefore got the
 * placeholder while its exact spelling got the stored salt, and an unknown
 * address got the same answer for both spellings: two requests were an oracle.
 */
final class ForkPreloginCaseTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testAKnownAddressGetsItsSaltWhateverTheCase(): void
    {
        $user = new User();
        $user->email = 'prelogin-' . bin2hex(random_bytes(6)) . '@plmail.test';
        $user->nameFirst = 'Prelogin';
        $user->nameLast = 'Case';
        $user->roles = [User::ROLE_USER];
        $user->useClientDerivedPassword('unused-hash', base64_encode(random_bytes(16)), 600000);

        $this->em->persist($user);
        $this->em->flush();

        self::assertSame($user->kdfSalt, $this->prelogin($user->email)['salt']);
        self::assertSame($user->kdfSalt, $this->prelogin(strtoupper($user->email))['salt'], 'another spelling of a known address got the placeholder');
    }

    public function testAnUnknownAddressAnswersTheSameWhateverTheCase(): void
    {
        $email = 'nobody-' . bin2hex(random_bytes(6)) . '@plmail.test';

        self::assertSame($this->prelogin($email), $this->prelogin(strtoupper($email)));
    }

    /**
     * @return array{salt: string, iterations: int, migrate: bool}
     */
    private function prelogin(string $email): array
    {
        $this->client->request('POST', '/login/prelogin', ['email' => $email]);

        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
