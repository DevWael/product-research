<?php

declare(strict_types=1);

namespace ProductResearch\AI\Providers;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Gemini\Gemini;

/**
 * Gemini-compatible provider that handles models without system_instruction
 * and structured output (response_schema) support.
 *
 * Some models served via the Gemini API (e.g. Gemma 3) do not support:
 * - `system_instruction` field
 * - `response_schema` / `response_mime_type` in generationConfig
 *
 * For these models, the system prompt is prepended to the conversation as a
 * user message, and structured output is achieved by instructing the model
 * to respond in JSON matching the given schema.
 */
final class GeminiCompat extends Gemini
{
    /**
     * Model prefixes that do NOT support system_instruction / response_schema.
     *
     * @var array<string>
     */
    private const LIMITED_MODEL_PREFIXES = [
        'gemma',
    ];

    /**
     * Whether the current model is limited (no system_instruction / response_schema).
     */
    private function isLimitedModel(): bool
    {
        foreach (self::LIMITED_MODEL_PREFIXES as $prefix) {
            if (str_starts_with($this->model, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Override to prepend system prompt into conversation for limited models.
     *
     * @param array<Message> $messages
     */
    public function chat(array $messages): Message
    {
        if ($this->isLimitedModel() && $this->system !== null) {
            $systemText   = $this->system;
            $this->system = null;

            array_unshift(
                $messages,
                new UserMessage("[System Instructions]\n" . $systemText)
            );
        }

        return parent::chat($messages);
    }

    /**
     * Override structured output for limited models.
     *
     * Instead of using response_schema (unsupported), append the JSON schema
     * to the last user message and let chat() handle the rest.
     * The Agent-level processResponse() will extract and deserialize the JSON.
     *
     * @param array<Message> $messages
     * @param array<string, mixed> $response_format
     */
    public function structured(array $messages, string $class, array $response_format): Message
    {
        if (! $this->isLimitedModel()) {
            return parent::structured($messages, $class, $response_format);
        }

        // Inject the schema into the last user message as a prompt instruction.
        $schemaJson  = json_encode($response_format, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $lastMessage = end($messages);

        if ($lastMessage instanceof Message) {
            $lastMessage->setContent(
                $lastMessage->getContent() .
                "\n\nRespond with ONLY a valid JSON object matching this schema exactly. " .
                "No markdown, no code fences, no explanation — just the raw JSON.\n\n" .
                "Schema:\n" . $schemaJson
            );
        }

        // Ensure generationConfig exists with low temperature for deterministic output.
        if (! isset($this->parameters['generationConfig'])) {
            $this->parameters['generationConfig'] = ['temperature' => 0];
        }

        // Do NOT set response_schema or response_mime_type — unsupported by Gemma.
        unset(
            $this->parameters['generationConfig']['response_schema'],
            $this->parameters['generationConfig']['response_mime_type']
        );

        return $this->chat($messages);
    }
}
