<?php

namespace App\Support\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\OutputConfig;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Claude implementation of {@see StructuredModel}.
 *
 * Structured outputs rather than tool use, for the reason the plan gives: a tool
 * runner would let the model call the thing that books money, and the whole
 * design rests on it not being able to.
 */
class Claude implements StructuredModel
{
    private ?Client $client = null;

    public function isConfigured(): bool
    {
        return (bool) config('ai.enabled') && (bool) config('ai.claude.api_key');
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function extract(string $system, string $utterance, array $schema): array
    {
        if (! $this->isConfigured()) {
            throw new ModelUnavailable(
                'The command bot is switched off, or has no API key configured. Set ANTHROPIC_API_KEY and '
                .'AI_COMMANDS_ENABLED, or use the ordinary entry screens.'
            );
        }

        try {
            $message = $this->client()->messages->create(
                maxTokens: (int) config('ai.claude.max_tokens'),
                messages: [['role' => 'user', 'content' => $utterance]],
                model: (string) config('ai.claude.model'),
                /*
                 * The cache breakpoint. The system prompt is this tenant's category
                 * list and the sign rules — identical on every command they send —
                 * and the utterance sits after it in `messages`. Today's date is
                 * deliberately NOT in here: it would move the prefix at midnight and
                 * throw away every tenant's cache at once.
                 */
                cacheControl: ['type' => 'ephemeral'],
                outputConfig: OutputConfig::with(
                    effort: (string) config('ai.claude.effort'),
                    format: JSONOutputFormat::with(schema: $schema),
                ),
                system: $system,
            );
        } catch (APIException $e) {
            // Logged rather than swallowed: §9 needs to be able to say why a
            // command produced nothing, and "the model 429'd" is an answer.
            Log::warning('AI command extraction failed', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            throw new ModelUnavailable('The command bot could not be reached. Try again, or use the entry screens.', previous: $e);
        }

        return $this->decode($message);
    }

    /**
     * Pull the JSON out of the reply.
     *
     * A refusal is a real outcome and is checked before the content is read:
     * `content` is empty on a pre-output refusal, so indexing into it first
     * turns a policy decline into an array-offset error that names nothing.
     *
     * @return array<string, mixed>
     */
    private function decode(object $message): array
    {
        if (($message->stopReason ?? null) === 'refusal') {
            throw new ModelUnavailable('The command bot declined to interpret that.');
        }

        $text = '';

        foreach ($message->content ?? [] as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text ?? '';
            }
        }

        $decoded = json_decode(trim($text), true);

        if (! is_array($decoded)) {
            throw new ModelUnavailable('The command bot replied with something that was not a command.');
        }

        return $decoded;
    }

    private function client(): Client
    {
        try {
            return $this->client ??= new Client(
                apiKey: (string) config('ai.claude.api_key'),
                requestOptions: ['timeout' => (float) config('ai.claude.timeout')],
            );
        } catch (Throwable $e) {
            throw new ModelUnavailable('The command bot could not start: '.$e->getMessage(), previous: $e);
        }
    }
}
