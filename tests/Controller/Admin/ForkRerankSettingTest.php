<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User\User;
use App\Repository\Fork\ForkAiOptionsRepository;
use App\Repository\User\UserRepository;
use App\Service\Fork\Search\ForkReranker;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * FORK (FORK.md): the search reranker's address in Admin → AI — saved, tested
 * by the Test button without being saved, and preferred over FORK_RERANK_URL.
 */
final class ForkRerankSettingTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string FIELD       = 'ai_settings[forkRerankUrl]';
    private const string URL         = 'http://192.0.2.10:4343/rerank';

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;
    private ForkAiOptionsRepository $options;

    /** @var list<string> */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $container->set('http_client', new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->asked[] = $url;

            return self::URL === $url
                ? new MockResponse(json_encode(['results' => [['index' => 0, 'relevance_score' => 0.93], ['index' => 1, 'relevance_score' => 0.01]]]))
                : new MockResponse('', ['http_code' => 404]);
        }));

        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->options    = $container->get(ForkAiOptionsRepository::class);

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $admin instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->client->loginUser($admin);

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

    public function testTheAddressIsSavedShownAndUsed(): void
    {
        $form = $this->form();
        $form[self::FIELD] = self::URL;

        $this->client->submit($form);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame(self::URL, $this->options->rerankUrl());
        self::assertSame(self::URL, self::getContainer()->get(ForkReranker::class)->url());
        self::assertSame(self::URL, $this->form()[self::FIELD]->getValue());

        $form = $this->form();
        $form[self::FIELD] = '';
        $this->client->submit($form);

        $this->em->clear();
        self::assertNull($this->options->rerankUrl());
    }

    /** Both fork fields on a first save are one row, not two against the singleton index. */
    public function testWithTheReasoningSwitchItIsOneRow(): void
    {
        $form = $this->form();
        $form[self::FIELD] = self::URL;
        $reasoning = $form->get('ai_settings[forkReasoningEnabled]');
        self::assertInstanceOf(ChoiceFormField::class, $reasoning);
        $reasoning->untick();

        $this->client->submit($form);
        self::assertResponseIsSuccessful();

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM fork_ai_options'));
        self::assertFalse($this->options->reasoningEnabled());
        self::assertSame(self::URL, $this->options->rerankUrl());
    }

    public function testSomethingThatIsNoAddressIsNotSaved(): void
    {
        $form = $this->form();
        $form[self::FIELD] = 'tower.local:4343';

        $this->client->submit($form);

        $this->em->clear();
        self::assertNull($this->options->rerankUrl());
    }

    public function testTheTestButtonAsksTheAddressAndDoesNotSaveIt(): void
    {
        $form = $this->form();
        $form[self::FIELD] = self::URL;

        $this->client->request('POST', '/admin/ai/test', $form->getPhpValues());

        self::assertResponseIsSuccessful();
        self::assertContains(self::URL, $this->asked);
        self::assertSelectorExists('[data-fork-rerank-probe="ok"]');

        $this->em->clear();
        self::assertNull($this->options->rerankUrl());
    }

    public function testATestOfAnAddressThatDoesNotRankSaysSo(): void
    {
        $form = $this->form();
        $form[self::FIELD] = 'http://192.0.2.11:4343/rerank';

        $this->client->request('POST', '/admin/ai/test', $form->getPhpValues());

        self::assertSelectorExists('[data-fork-rerank-probe="failed"]');
    }

    /** The page's address wins over the variable; without one, the variable still works. */
    public function testThePagesAddressWinsOverTheVariable(): void
    {
        $reranker = new ForkReranker(new MockHttpClient(), new NullLogger(), $this->options, 'http://192.0.2.99/rerank');

        self::assertSame('http://192.0.2.99/rerank', $reranker->url());

        $form = $this->form();
        $form[self::FIELD] = self::URL;
        $this->client->submit($form);
        $this->em->clear();

        self::assertSame(self::URL, $reranker->url());
    }

    private function form(): Form
    {
        $crawler = $this->client->request('GET', '/admin/ai');

        return $crawler->filter('form[name="ai_settings"]')->form();
    }
}
