<?php

/**
 * The AI command bot — `docs/ai-command-bot-plan.md`.
 *
 * Off by default, and that is the important line in this file. A tenant with no
 * key configured gets no command bar rather than a command bar that fails when
 * somebody types into it (§18.1's "smaller, never broken", applied to a feature
 * rather than a module).
 */

return [
    // Nothing reaches the model unless this is on AND a key is present.
    'enabled' => (bool) env('AI_COMMANDS_ENABLED', false),

    'driver' => env('AI_DRIVER', 'claude'),

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),

        /*
         * Opus 5 at low effort.
         *
         * This is a short extraction against a closed category list, which is the
         * shape `low` exists for — it is the cost and latency lever here, not the
         * model choice. Moving to a smaller model is a decision to take with
         * measured numbers rather than a default to assume.
         */
        'model' => env('AI_MODEL', 'claude-opus-5'),
        'effort' => env('AI_EFFORT', 'low'),

        // Small by construction: the reply is four fields and two flags.
        'max_tokens' => (int) env('AI_MAX_TOKENS', 1024),

        // A command that has not come back in this long is a command the user
        // has already given up on. Fail fast rather than hold the request open.
        'timeout' => (int) env('AI_TIMEOUT', 20),
    ],

    /*
     * Voice — docs/ai-command-bot-plan.md §5.
     *
     * The browser's own recogniser, not a transcription API. Claude has no speech-to-text endpoint, so
     * this was a separate decision, and the browser wins the first implementation on the thing that
     * matters most for a bookkeeping app: **the audio never leaves the device.** It also needs no key, no
     * dependency and no per-minute cost.
     *
     * What it costs is accuracy, and §5 is honest that this is the weak part: `ur-PK` support ranges from
     * poor to absent depending on the browser. That is precisely why the transcript is shown and editable
     * rather than parsed on arrival — a speech error and a parse error have to be separable by the person
     * using it, and they cannot be if the machine acts on what it thought it heard.
     */
    'voice' => [
        'enabled' => (bool) env('AI_VOICE_ENABLED', true),

        /*
         * Offered dictation languages, first is the default.
         *
         * `en-PK` leads rather than `ur-PK` on purpose. Roman-Urdu spoken into an English recogniser is
         * what most people actually produce — "kiraya pachees hazaar" comes back as recognisable Latin
         * text the alias table already understands — while an Urdu recogniser that is unavailable fails
         * with nothing to show for it. The Urdu option is there for the browsers that do support it.
         */
        'locales' => [
            'en-PK' => 'English',
            'ur-PK' => 'اردو',
        ],
    ],

    'commands' => [
        /*
         * Above this, the confirmation cannot be accepted by pressing Enter —
         * the amount has to be clicked. §6: the cost of a wrong entry is
         * asymmetric, and it scales with the figure.
         */
        'confirm_threshold' => (float) env('AI_CONFIRM_THRESHOLD', 100000),

        // Utterances retained for correction and for the alias table (§9).
        'retain_utterances_days' => (int) env('AI_RETAIN_UTTERANCES_DAYS', 365),
    ],
];
