<?php

declare(strict_types=1);

namespace App\Form\Fork;

use App\Form\Admin\AiSettingsType;
use App\Repository\Fork\ForkAiOptionsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * FORK (FORK.md): the reasoning switch in Admin → AI, without editing AiSettingsType.
 *
 * Unmapped, because the value lives in fork_ai_options and not on AiSettings.
 * A valid submit to the save action writes it onto that row and persists it;
 * the flush is the controller's, the one that stores the AiSettings row beside
 * it. Only the save action: the Test button submits this same form, and while
 * its controller flushes nothing, the probes it runs can — a row persisted then
 * would be saved by somebody only trying an address.
 *
 * Only on the full admin form. The setup wizard's short form (`advanced: false`)
 * leaves it out, like the original's other rarely needed fields.
 */
final class ForkReasoningExtension extends AbstractTypeExtension
{
    public const string FIELD = 'forkReasoningEnabled';

    /** AiSettingsController::settings(), the one action that saves. */
    private const string SAVE_ROUTE = 'app_admin_ai_settings';

    public function __construct(
        private readonly ForkAiOptionsRepository $options,
        private readonly EntityManagerInterface  $entityManager,
        private readonly RequestStack            $requests,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [AiSettingsType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (false === $options['advanced']) {
            return;
        }

        $builder->add(self::FIELD, CheckboxType::class, [
            'label'              => 'admin.ai.field.reasoning',
            'help'               => 'admin.ai.field.reasoning_help',
            'translation_domain' => 'fork',
            'required'           => false,
            'mapped'             => false,
            'data'               => $this->options->currentOrNew()->reasoningEnabled,
        ]);

        // After the validation listener, which is also on POST_SUBMIT, so
        // isValid() is the answer for the whole form.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();

            if (false === $form->isValid()) {
                return;
            }

            if (self::SAVE_ROUTE !== $this->requests->getCurrentRequest()?->attributes->get('_route')) {
                return;
            }

            $row                   = $this->options->forWrite();
            $row->reasoningEnabled = true === $form->get(self::FIELD)->getData();

            $this->entityManager->persist($row);
        }, -10);
    }
}
