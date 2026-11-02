<?php

declare(strict_types=1);

namespace App\Tests\Service\Rule;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ApplyImapFlagsMessage;
use App\Jmap\State\ChangeLogRepository;
use App\Jmap\State\StateManager;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Label\LabelBindingRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\InboxCategoryRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Graph\GraphLabelPolicy;
use App\Service\Label\LabelChangePropagator;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Rule\RuleActionExecutor;
use App\Service\Rule\RuleCategoryFiler;
use App\Tests\Support\Mail\BuildsThreaderWithoutDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * FORK (FORK.md): a rule's archive and markSpam put mail where the buttons do.
 *
 * Archive took Inbox off and added nothing, so the mail was in no list — not
 * the inbox, not the archive. markSpam labelled it Spam here and archived it
 * on the server, where every other client still saw it as ham in the archive.
 */
final class ForkRuleLeavesInboxTest extends TestCase
{
    use BuildsThreaderWithoutDatabase;

    /** @var list<object> */
    private array $sent = [];

    /** @var array<string, Label> */
    private array $labels = [];

    public function testArchiveRuleFilesTheMailUnderArchive(): void
    {
        [$executor, $message, $rule] = $this->rule('archive');

        $executor->execute($rule, $message);

        self::assertSame(['archive'], $this->rolesOf($message));
        self::assertSame(['archive'], $this->imapActions());
    }

    public function testMarkSpamRuleMovesTheMailIntoTheSpamFolder(): void
    {
        [$executor, $message, $rule] = $this->rule('markSpam');

        $executor->execute($rule, $message);

        self::assertSame(['spam'], $this->rolesOf($message));

        $moves = array_values(array_filter($this->sent, static fn (object $m): bool => $m instanceof ApplyImapFlagsMessage));
        self::assertCount(1, $moves);
        self::assertSame('move', $moves[0]->action);
        self::assertSame('Junk', $moves[0]->destinationPath);
    }

    public function testTrashRuleIsUnchanged(): void
    {
        [$executor, $message, $rule] = $this->rule('trash');

        $executor->execute($rule, $message);

        self::assertSame(['trash'], $this->rolesOf($message));
        self::assertSame(['trash'], $this->imapActions());
    }

    /** @return list<string|null> */
    private function rolesOf(Message $message): array
    {
        return array_values(array_map(static fn (Label $label): ?string => $label->role?->value, $message->labels->toArray()));
    }

    /** @return list<string> */
    private function imapActions(): array
    {
        return array_values(array_map(
            static fn (ApplyImapFlagsMessage $m): string => $m->action,
            array_filter($this->sent, static fn (object $m): bool => $m instanceof ApplyImapFlagsMessage),
        ));
    }

    private static function identify(object $entity, int $id): void
    {
        $reflection = new \ReflectionObject($entity);

        while (false === $reflection->hasProperty('id')) {
            $reflection = $reflection->getParentClass();
        }

        $reflection->getProperty('id')->setValue($entity, $id);
    }

    /** @return array{RuleActionExecutor, Message, MailRule} */
    private function rule(string $type): array
    {
        $user = new User();
        self::identify($user, 1);

        $account = new Account();
        self::identify($account, 1);
        $account->usr = $user;

        $inbox = new Mailbox();
        self::identify($inbox, 7);
        $inbox->account  = $account;
        $inbox->fullPath = 'INBOX';

        $junk = new Mailbox();
        self::identify($junk, 8);
        $junk->account  = $account;
        $junk->fullPath = 'Junk';

        $id = 10;

        foreach (LabelRole::cases() as $role) {
            $label = new Label();
            self::identify($label, $id++);
            $label->usr      = $user;
            $label->role     = $role;
            $label->name     = $role->value;
            $this->labels[$role->value] = $label;
        }

        $message = new Message();
        self::identify($message, 99);
        $message->account = $account;
        $message->addLocation($inbox, 42);
        $message->addLabel($this->labels['inbox']);

        $rule          = new MailRule();
        $rule->usr     = $user;
        $rule->actions = [['type' => $type]];

        $labels = $this->createStub(LabelRepository::class);
        $labels->method('findOneByRoleForUser')->willReturnCallback(fn (LabelRole $role): Label => $this->labels[$role->value]);

        $bindings = $this->createStub(LabelBindingRepository::class);
        $bindings->method('findOneForLabelAndAccount')->willReturnCallback(static function (Label $label, Account $account) use ($junk): LabelBinding {
            $binding          = new LabelBinding();
            $binding->label   = $label;
            $binding->account = $account;
            $binding->mailbox = LabelRole::Spam === $label->role ? $junk : null;
            $label->addBinding($binding);

            return $binding;
        });

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->sent[] = $message;

            return new Envelope($message);
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);

        $executor = new RuleActionExecutor(
            $labels,
            new LabelResolver($labels, $bindings, $entityManager, new StateManager($entityManager, new ChangeLogRepository($this->createStub(ManagerRegistry::class)))),
            new LabelChangePropagator($bus, $this->createStub(MailboxRepository::class), $labels, new GraphLabelPolicy(), new NullLogger(), $this->createStub(MessageRepository::class)),
            new ThreadLabelSynchronizer($entityManager),
            $this->createStub(IntegrationRepository::class),
            $bus,
            new NullLogger(),
            new RuleCategoryFiler($this->createStub(InboxCategoryRepository::class), $this->threaderWithoutDatabase(), new NullLogger()),
            new GraphLabelPolicy(),
        );

        return [$executor, $message, $rule];
    }
}
