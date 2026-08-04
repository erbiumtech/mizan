<?php

namespace App\Support;

use App\Modules\Core\Models\EmailTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Applies a company's wording to a notification, where it has written any.
 *
 * Each notification keeps its own text and calls this to have it overridden. Nothing
 * is required of a company: with no template, or with a template that sets only the
 * subject, everything not overridden stays exactly as the notification wrote it.
 *
 * The alternative — moving the text into the database and having notifications read it
 * from there — makes every email depend on a row existing, and the first company
 * without one gets a blank message.
 */
class TemplatedMail
{
    /**
     * @param  array<string, string|null>  $values  placeholder => replacement
     * @param  array<int, string>  $lines  the notification's own paragraphs
     */
    public static function apply(
        MailMessage $mail,
        string $key,
        array $values,
        string $subject,
        ?string $greeting,
        array $lines,
    ): MailMessage {
        $template = EmailTemplate::for($key);

        $mail->subject($template?->render('subject', $values) ?? $subject);

        if ($greeting = $template?->render('greeting', $values) ?? $greeting) {
            $mail->greeting($greeting);
        }

        // The template's paragraphs replace the notification's rather than joining
        // them: a company rewording an email means to say something instead, not as
        // well.
        $paragraphs = $template ? $template->paragraphs($values) : [];

        foreach ($paragraphs !== [] ? $paragraphs : $lines as $line) {
            $mail->line($line);
        }

        if ($closing = $template?->render('closing', $values)) {
            $mail->line($closing);
        }

        return $mail;
    }
}
