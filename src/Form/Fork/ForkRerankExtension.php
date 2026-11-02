<?php

declare(strict_types=1);

namespace App\Form\Fork;

use App\Form\Admin\AiSettingsType;
use App\Repository\Fork\ForkAiOptionsRepository;
use App\Service\Fork\Search\ForkReranker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Url;

/**
 * FORK (FORK.md): the search reranker's address in Admin → AI, the way
 * ForkReasoningExtension adds the reasoning switch — unmapped, kept in
 * fork_ai_options, saved only by the save action, and only on the full form.
 *
 * The Test button asks it too. A valid submit to the test action sends the
 * address on screen one small ranking (ForkReranker::probe()) and leaves the
 * answer on the request for templates/fork/admin/_rerank.html.twig, beside
 * the field: the original's test reports only the hosts it knows of.
 */
final class ForkRerankExtension extends AbstractTypeExtension
{
    public const string FIELD = 'forkRerankUrl';

    /** Where the Test button's answer waits for the template. */
    public const string PROBE_ATTRIBUTE = '_fork_rerank_probe';

    private const string SAVE_ROUTE = 'app_admin_ai_settings';

    private const string TEST_ROUTE = 'app_admin_ai_test';

    public function __construct(
        private readonly ForkAiOptionsRepository $options,
        private readonly EntityManagerInterface  $entityManager,
        private readonly RequestStack            $requests,
        private readonly ForkReranker            $reranker,
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

        $builder->add(self::FIELD, TextType::class, [
            'label'              => 'admin.ai.field.rerank_url',
            'help'               => 'admin.ai.field.rerank_url_help',
            'translation_domain' => 'fork',
            'required'           => false,
            'mapped'             => false,
            'data'               => $this->options->currentOrNew()->rerankUrl,
            'attr'               => ['placeholder' => 'http://192.0.2.10:4343/rerank', 'spellcheck' => 'false', 'autocomplete' => 'off'],
            'constraints'        => [
                new Url(protocols: ['http', 'https'], requireTld: false),
                new Length(max: 500),
            ],
        ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();

            if (false === $form->isValid()) {
                return;
            }

            $request = $this->requests->getCurrentRequest();
            $route   = $request?->attributes->get('_route');
            $value   = trim((string) $form->get(self::FIELD)->getData());

            if (self::TEST_ROUTE === $route && '' !== $value) {
                $request?->attributes->set(self::PROBE_ATTRIBUTE, $this->reranker->probe($value));

                return;
            }

            if (self::SAVE_ROUTE !== $route) {
                return;
            }

            $row            = $this->options->forWrite();
            $row->rerankUrl = '' === $value ? null : $value;

            $this->entityManager->persist($row);
        }, -10);
    }
}
