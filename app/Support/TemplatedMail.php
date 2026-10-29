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

    /**
     * The same override, applied to a message that is already built.
     *
     * {@see apply} suits a notification whose text is a flat list of lines. Most are
     * not: they add a line only when there is a receipt, only when the incident is a
     * reminder, only for each changed field. Rewriting those into a `lines` array to
     * get them templated would mean rewriting the logic that builds them, so this
     * takes the finished message instead and overrides the parts a company may
     * reword.
     *
     * The action button and any attachment are left alone on purpose: a link to the
     * payslip and the payslip itself are what the mail is *for*, not how it is
     * phrased. Only the button's label can be changed, and only where there is one.
     *
     * @param  array<string, string|null>  $values  placeholder => replacement
     */
    public static function wrap(MailMessage $mail, string $key, array $values): MailMessage
    {
        $template = EmailTemplate::for($key);

        return $template === null ? $mail : self::applyTo($mail, $template, $values);
    }

    /**
     * This template on a message whose own wording is a visible placeholder.
     *
     * What the preview and the test send show. The stand-in lines matter: where the
     * template leaves a part alone, the preview says so in those words instead of
     * showing the shipped sentence as though the template had written it, which is
     * the one thing somebody checking a template needs to be able to tell apart.
     */
    public static function previewOf(EmailTemplate $template): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('(the subject this email normally uses)')
            ->greeting('(the greeting this email normally uses)')
            ->line('(the wording this email normally uses)');

        return self::applyTo($mail, $template, $template->sampleValues());
    }

    /**
     * One template on one message, whether or not it is the active one.
     *
     * This is what the preview and the test send use: somebody editing a template
     * that is switched off still needs to see what it says, and `for()` would hand
     * back the active row or nothing.
     *
     * @param  array<string, string|null>  $values
     */
    public static function applyTo(MailMessage $mail, EmailTemplate $template, array $values): MailMessage
    {
        if ($subject = $template->render('subject', $values)) {
            $mail->subject($subject);
        }

        if ($greeting = $template->render('greeting', $values)) {
            $mail->greeting($greeting);
        }

        // As in apply(): the company's paragraphs replace ours rather than joining
        // them. Lines after the action button go too, because a company that has
        // written the whole body has said where it wants the last word.
        if (($paragraphs = $template->paragraphs($values)) !== []) {
            $mail->introLines = $paragraphs;
            $mail->outroLines = [];
        }

        if ($mail->actionUrl !== null && ($label = $template->render('action_label', $values))) {
            $mail->actionText = $label;
        }

        if ($closing = $template->render('closing', $values)) {
            $mail->outroLines[] = $closing;
        }

        return $mail;
    }
}
