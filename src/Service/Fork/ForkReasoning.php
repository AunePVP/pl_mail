<?php

declare(strict_types=1);

namespace App\Service\Fork;

use App\Domain\Enum\Ai\AiProvider;
use App\Repository\Fork\ForkAiOptionsRepository;

/**
 * FORK (FORK.md): what a chat request carries to switch the model's reasoning off.
 *
 * Nothing at all while reasoning is on, which is how the original asks — so a
 * strict server that refuses a field it does not know (OpenAiClient's warning)
 * is only ever sent one when an administrator chose to.
 *
 * Off is spelled per dialect:
 *  - Ollama: `think: false`, its own field for thinking models.
 *  - OpenAI-compatible: `chat_template_kwargs.enable_thinking: false`, which
 *    vLLM, SGLang and llama.cpp's server hand to the chat template. It is what
 *    Qwen3 and most other hybrid reasoning models read. A hosted API that does
 *    not know the field may answer 400; the switch is for servers that do.
 *
 * Not final, so the clients' tests can stand in for it without a database.
 */
class ForkReasoning
{
    public function __construct(
        private readonly ForkAiOptionsRepository $options,
    ) {
    }

    /** @return array<string, mixed> merged into the chat request's body */
    public function fieldsFor(AiProvider $provider): array
    {
        return self::fields($provider, $this->options->reasoningEnabled());
    }

    /** @return array<string, mixed> */
    public static function fields(AiProvider $provider, bool $reasoningEnabled): array
    {
        if (true === $reasoningEnabled) {
            return [];
        }

        return match ($provider) {
            AiProvider::Ollama => ['think' => false],
            AiProvider::OpenAi => ['chat_template_kwargs' => ['enable_thinking' => false]],
        };
    }
}
