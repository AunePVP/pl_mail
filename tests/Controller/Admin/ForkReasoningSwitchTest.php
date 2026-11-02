<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User\User;
use App\Repository\Fork\ForkAiOptionsRepository;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * FORK (FORK.md): the reasoning switch in Admin → AI is shown, saved, and not
 * saved by the Test button.
 */
final class ForkReasoningSwitchTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string FIELD       = 'ai_settings[forkReasoningEnabled]';

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;
    private ForkAiOptionsRepository $options;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $container        = static::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->options    = $container->get(ForkAiOptionsRepository::class);

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $admin instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->client->loginUser($admin);
        // One container for every request, so they all write inside the
        // transaction below and it can be rolled back.
        $this->client->disableReboot();

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM fork_ai_options');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheSwitchIsOnUntilSomebodyTurnsItOff(): void
    {
        $crawler = $this->client->request('GET', '/admin/ai');
        $form    = $crawler->filter('form[name="ai_settings"]')->form();

        self::assertTrue(self::checkbox($form)->hasValue(), 'reasoning is on by default');
        self::assertTrue($this->options->reasoningEnabled());
    }

    public function testTurningItOffIsSavedAndShown(): void
    {
        $crawler = $this->client->request('GET', '/admin/ai');
        $form    = $crawler->filter('form[name="ai_settings"]')->form();
        self::checkbox($form)->untick();

        $this->client->submit($form);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertFalse($this->options->reasoningEnabled());

        $crawler = $this->client->request('GET', '/admin/ai');
        self::assertFalse(self::checkbox($crawler->filter('form[name="ai_settings"]')->form())->hasValue());
    }

    public function testTheTestButtonDoesNotSaveIt(): void
    {
        $crawler = $this->client->request('GET', '/admin/ai');
        $form    = $crawler->filter('form[name="ai_settings"]')->form();
        self::checkbox($form)->untick();

        $this->client->request('POST', '/admin/ai/test', $form->getPhpValues());
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertTrue($this->options->reasoningEnabled());
    }

    private static function checkbox(Form $form): ChoiceFormField
    {
        $field = $form->get(self::FIELD);
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }
}
