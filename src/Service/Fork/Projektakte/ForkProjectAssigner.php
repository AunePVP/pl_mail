<?php

declare(strict_types=1);

namespace App\Service\Fork\Projektakte;

use App\Domain\Enum\Fork\ForkProjectStatus;
use App\Domain\Enum\Fork\ForkPushAction;
use App\Entity\Fork\ForkMessageProject;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Fork\ForkMessageProjectRepository;
use App\Service\Mail\MailChangeRecorder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * FORK (FORK.md 27, 30): a person files a message under a Projektakte project,
 * says it belongs to none, or takes either back.
 *
 * Each of the three ends the same way, and the order is the point:
 *
 *  1. the row is written;
 *  2. the Email is announced as updated in the JMAP change log, because
 *     Projektakte learns of assignments from Email/changes and would otherwise
 *     never see this one — the property changed, the message did not;
 *  3. one flush carries both, so a client can never see the new state without
 *     the change that announces it (MailChangeRecorder does not flush);
 *  4. the mail is queued for Projektakte (FORK.md 30): the whole mail when it
 *     is newly filed, the new assignment when it moves between projects, a
 *     DELETE when it is about none or undecided again. Queued, not sent: the
 *     click never waits for Projektakte (ForkProjektaktePush).
 *
 * Nothing goes to the mail server. An assignment is plMail's and Projektakte's
 * business, not a keyword, label or flag that another client would show.
 */
final readonly class ForkProjectAssigner
{
    public function __construct(
        private EntityManagerInterface       $em,
        private ForkMessageProjectRepository $rows,
        private ForkProjektakteClient        $projektakte,
        private MailChangeRecorder           $changes,
        private ForkProjektaktePush          $push,
    ) {
    }

    /**
     * File the message under a project Projektakte knows; its name is taken
     * from Projektakte, never from the request.
     *
     * @throws ForkProjectRefused when Projektakte is down or knows no such project
     */
    public function assign(Message $message, User $user, string $number): ForkMessageProject
    {
        $project = $this->project($number);
        $row     = $this->rows->forMessage($message) ?? new ForkMessageProject($message);
        $known   = ForkProjectStatus::Assigned === $row->status && null !== $row->id;

        $row->assign($project->number, $project->name, $user);
        $this->em->persist($row);
        $this->announce($message, true === $known ? ForkPushAction::Reassign : ForkPushAction::Send);

        return $row;
    }

    /** Looked at, and about no project. Needs no answer from Projektakte. */
    public function markNone(Message $message, User $user): ForkMessageProject
    {
        $row = $this->rows->forMessage($message) ?? new ForkMessageProject($message);

        $row->markNone($user);
        $this->em->persist($row);
        $this->announce($message, ForkPushAction::Remove);

        return $row;
    }

    /** Back to undecided — no row, and null over JMAP. */
    public function clear(Message $message): void
    {
        $row = $this->rows->forMessage($message);

        if (null === $row) {
            return;
        }

        $this->em->remove($row);
        $this->announce($message, ForkPushAction::Remove);
    }

    /**
     * The project as Projektakte lists it: from the cached list, else asked
     * directly — a project created a minute ago is in neither the cache nor,
     * for an office with more than 500, necessarily in the list at all.
     */
    private function project(string $number): ForkProject
    {
        $number   = trim($number);
        $projects = $this->projektakte->projects();

        if (null === $projects) {
            throw ForkProjectRefused::unavailable();
        }

        $project = $projects[$number] ?? $this->projektakte->lookup($number);

        if (null === $project) {
            throw ForkProjectRefused::unknown();
        }

        return $project;
    }

    private function announce(Message $message, ForkPushAction $push): void
    {
        $this->changes->emailChanged((int) $message->account->id, (string) $message->id, false, $message->thread);
        $this->em->flush();
        $this->push->queue($message, $push);
    }
}
