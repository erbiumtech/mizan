<?php

namespace App\Support\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Meta's WhatsApp Cloud API.
 *
 * Two calls, in this order, because the API has no single one that takes a file
 * and a recipient:
 *
 *   1. POST /{phone_number_id}/media — upload the PDF, get a media id back. The
 *      id is good for 30 days and is what the message refers to. Uploading avoids
 *      the alternative, a publicly fetchable link, which for a payslip would mean
 *      putting somebody's salary on an unauthenticated URL.
 *   2. POST /{phone_number_id}/messages — send it.
 *
 * The second call has a catch worth knowing before wondering why nothing arrives.
 * A business may only open a conversation with a **template** that Meta has
 * approved; a plain document message is accepted solely within 24 hours of the
 * employee's last message to you. Payroll is never inside that window, so a
 * configured template is the real path and the plain document below is for
 * sandbox numbers and for testing before a template clears review.
 */
class CloudApiWhatsAppSender implements WhatsAppSender
{
    public function __construct(
        private string $phoneNumberId,
        private string $token,
        private string $apiVersion = 'v21.0',
        private ?string $template = null,
        private string $templateLanguage = 'en',
    ) {}

    public function sendDocument(string $to, WhatsAppDocument $document, string $caption): string
    {
        $filename = $document->filename;
        $mediaId = $this->upload($filename, $document->bytes());

        $payload = $this->template
            ? $this->templateMessage($to, $mediaId, $filename, $caption)
            : $this->documentMessage($to, $mediaId, $filename, $caption);

        $response = $this->post('messages', $payload);

        $id = $response->json('messages.0.id');

        if (! is_string($id) || $id === '') {
            throw new WhatsAppException(
                'WhatsApp accepted the request but returned no message id: '.$response->body()
            );
        }

        return $id;
    }

    /**
     * @return string the media id
     */
    protected function upload(string $filename, string $pdf): string
    {
        try {
            $response = Http::withToken($this->token)
                ->attach('file', $pdf, $filename, ['Content-Type' => 'application/pdf'])
                ->post($this->url('media'), [
                    'messaging_product' => 'whatsapp',
                    'type' => 'application/pdf',
                ]);
        } catch (ConnectionException $e) {
            throw new WhatsAppException('WhatsApp could not be reached: '.$e->getMessage(), previous: $e);
        }

        $this->throwUnlessSuccessful($response, 'uploading the payslip');

        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            throw new WhatsAppException('WhatsApp returned no media id: '.$response->body());
        }

        return $id;
    }

    /**
     * A template message with the PDF in its header — the only shape that reaches
     * somebody who has not messaged you first.
     *
     * The body takes the caption as its one parameter, so the approved template
     * reads like "Your payslip for {{1}} is attached." Nothing else is passed:
     * a template with a different number of parameters is rejected outright, and
     * one placeholder is what a payslip needs.
     *
     * @return array<string, mixed>
     */
    protected function templateMessage(string $to, string $mediaId, string $filename, string $caption): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $this->template,
                'language' => ['code' => $this->templateLanguage],
                'components' => [
                    [
                        'type' => 'header',
                        'parameters' => [[
                            'type' => 'document',
                            'document' => ['id' => $mediaId, 'filename' => $filename],
                        ]],
                    ],
                    [
                        'type' => 'body',
                        'parameters' => [['type' => 'text', 'text' => $caption]],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function documentMessage(string $to, string $mediaId, string $filename, string $caption): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'document',
            'document' => [
                'id' => $mediaId,
                'filename' => $filename,
                'caption' => $caption,
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    protected function post(string $path, array $payload): Response
    {
        try {
            $response = Http::withToken($this->token)
                ->asJson()
                ->post($this->url($path), $payload);
        } catch (ConnectionException $e) {
            throw new WhatsAppException('WhatsApp could not be reached: '.$e->getMessage(), previous: $e);
        }

        $this->throwUnlessSuccessful($response, 'sending the message');

        return $response;
    }

    /**
     * Meta's own words, not "request failed".
     *
     * An unregistered number, a template still in review and an expired token all
     * arrive here, and they need three different fixes — none of which anybody can
     * work out from a status code.
     */
    protected function throwUnlessSuccessful(Response $response, string $doing): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message') ?: $response->body();
        $code = $response->json('error.code');

        throw new WhatsAppException(
            "WhatsApp refused while {$doing}: {$message}".($code ? " (code {$code})" : '')
        );
    }

    protected function url(string $path): string
    {
        return "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/{$path}";
    }
}
