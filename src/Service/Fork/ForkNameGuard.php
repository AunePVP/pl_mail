<?php

declare(strict_types=1);

namespace App\Service\Fork;

use App\Domain\Enum\Ai\WritingTask;

/**
 * FORK (FORK.md): names in a draft survive "shorter", "more formal" and
 * "proofread" letter for letter — and when they do not, the writer is told.
 *
 * THE BUG
 * ───────
 * "More formal" turned "Frau Strauss" into "Frau Strauß", reproducibly, and once into
 * "Frau Straussler". The writer's reply context was the whole thread, and in it
 * the recipient signs "Strauß" while her address book name says "Strauss": the model
 * took the signature for the right spelling and "corrected" the draft. The
 * shipped prompts say "do not add content" and nothing about names, and a name
 * is the one word in a mail a model has no way to check and every reason to
 * normalise.
 *
 * TWO HALVES, BECAUSE ONE IS NOT ENOUGH
 * ─────────────────────────────────────
 * RULE goes into the system message (WritingAssistant::messagesFor()). Measured
 * on the draft that failed: 15 of 15 runs wrote "Strauß" without it, 0 of 15 with
 * it; a milder wording ("keep names as they are") still failed 11 of 15. A
 * prompt lowers the odds and proves nothing, and "Straussler" came out of nowhere
 * at all — so missing() checks the answer against the draft, without a model,
 * and the composer says which names did not come back before anybody presses
 * Insert.
 *
 * Appended structurally like PromptRules::LANGUAGE, after whatever the
 * administrator made of the task prompt: a reworded "formal" prompt in Admin →
 * AI must not quietly drop the protection.
 *
 * NOT FOR "REPLY"
 * ───────────────
 * A reply writes new text, and the names in it come from the conversation —
 * where the signature's spelling is usually the right one.
 */
final class ForkNameGuard
{
    public const string RULE = 'Copy every name in the message to work on letter for letter, exactly as '
        . 'the writer typed it, even where the conversation or a signature spells it differently. Never '
        . 'correct, complete, shorten or replace a name, and never change ss to ß or the other way round.';

    /**
     * Words that introduce a name: forms of address, salutations, and the
     * academic titles that may stand between them and the name.
     */
    private const string ADDRESS = '(?:Frau|Herrn?|Hr\.|Fr\.|Mr\.?|Mrs\.?|Ms\.?|Miss|Dear|Hallo|Hello|Hi|Hey'
        . '|Liebe[rs]?|Moin|Servus|Madame|Monsieur|Mme\.?|M\.)';

    private const string TITLE = '(?:(?:Dr|Prof|Dipl|Ing|med|rer|nat|jur|Mag)\.(?:-\p{L}+\.)?\h+)';

    /** A capitalised word that is a name's shape: Müller, O'Neill, Meyer-Lüdenscheidt. */
    private const string WORD = '\p{Lu}[\p{L}\'’-]*\p{L}';

    /** Capitalised after "Hallo" and the like, and still not a name. */
    private const array NOT_NAMES = [
        'Team', 'Zusammen', 'Alle', 'Allerseits', 'Kollegen', 'Kolleginnen', 'Damen', 'Herren', 'Leute',
        'Freunde', 'Frau', 'Herr', 'Herrn', 'Everyone', 'All', 'Sir', 'Madam', 'Folks', 'There', 'Guys',
    ];

    public static function appliesTo(WritingTask $task): bool
    {
        return WritingTask::Reply !== $task;
    }

    /** The sentence for the system message, or '' for a task it does not apply to. */
    public static function ruleFor(WritingTask $task): string
    {
        return true === self::appliesTo($task) ? ' ' . self::RULE : '';
    }

    /**
     * The names of the draft that are not in the answer, word for word.
     *
     * A name is what follows a form of address ("Frau Strauss", "Herrn Dr. Meier",
     * "Hallo Peter"), plus a second capitalised word where the line or the
     * clause ends after it ("Herr Max Müller,"). German capitalises every noun,
     * so a bare capitalised word is not a name, and a check that thought it was
     * would warn on every rewrite. Each word is looked for on its own, between
     * non-letters and in the same case — "Straussler" and "Strauß" both miss "Strauss".
     *
     * @return list<string>
     */
    public static function missing(WritingTask $task, string $draft, string $answer): array
    {
        if (false === self::appliesTo($task) || '' === trim($answer)) {
            return [];
        }

        $pattern = '/(?<![\p{L}\p{N}])(?:' . self::ADDRESS . '\h+)+' . self::TITLE . '*'
            . '(' . self::WORD . ')(?:\h+(' . self::WORD . ')(?=\h*(?:[,.;:!?]|$)))?/mu';

        if (false === preg_match_all($pattern, $draft, $matches, \PREG_SET_ORDER)) {
            return [];
        }

        $missing = [];

        foreach ($matches as $match) {
            foreach ([$match[1], $match[2] ?? ''] as $name) {
                if ('' === $name || true === in_array($name, self::NOT_NAMES, true)) {
                    continue;
                }

                $found = preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/u', $answer);

                if (0 === $found && false === in_array($name, $missing, true)) {
                    $missing[] = $name;
                }
            }
        }

        return $missing;
    }
}
