<?php

return [

    /*
     * Which sender to use.
     *
     * "cloud" talks to Meta's WhatsApp Cloud API. "log" writes the message to the
     * log and reports success, which is what local development and the test suite
     * run on — nothing leaves the machine and no credentials are needed.
     *
     * Left as "log" unless configured, deliberately: a half-configured install
     * that silently posts payslips to a live number is worse than one that does
     * not send at all.
     */
    'driver' => env('WHATSAPP_DRIVER', 'log'),

    'cloud' => [
        /*
         * From the WhatsApp Business account: the phone number id sending the
         * message, and a permanent access token for the system user that owns it.
         */
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'token' => env('WHATSAPP_TOKEN'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),

        /*
         * The approved template a payslip is sent as.
         *
         * This is not optional in practice. WhatsApp only allows a business to
         * open a conversation with a template it has had approved; a plain
         * document message is accepted solely inside the 24 hours after the
         * employee last wrote to you, which is never true for payroll. The
         * template must carry a document header — that header is where the payslip
         * PDF is attached.
         *
         * With no template configured the sender falls back to a plain document
         * message, which is what a sandbox number accepts and what makes the
         * feature testable before a template clears review.
         */
        'template' => env('WHATSAPP_PAYSLIP_TEMPLATE'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
    ],

    'twilio' => [
        /*
         * Account sid and auth token from the Twilio console, and the WhatsApp
         * sender — the sandbox number while testing, your own once approved. The
         * "whatsapp:" prefix is added for you if it is left off.
         */
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_WHATSAPP_FROM'),
        'api_base' => env('TWILIO_API_BASE', 'https://api.twilio.com/2010-04-01'),

        /*
         * The approved Content Template to send payslips as (an HX… sid).
         *
         * Needed for anything real. WhatsApp only lets a business open a
         * conversation with an approved template; without one, Twilio accepts the
         * message solely inside the 24 hours after the employee last wrote to you
         * and otherwise answers 63016. Unset falls back to a freeform Body +
         * MediaUrl message, which is what the sandbox takes.
         *
         * Create it once as a twilio/media template — see the JSON in
         * TwilioWhatsAppSender::templateParameters(). {{1}} is the payslip link
         * after the domain, {{2}} the caption.
         */
        'content_sid' => env('TWILIO_WHATSAPP_CONTENT_SID'),

        /*
         * The media domain baked into that template — the part before {{1}},
         * because Twilio supports variables in a media URL "only after the
         * domain". Checked against the link the app generates, so a template
         * pointing at one host while APP_URL names another is refused with an
         * explanation instead of silently failing to fetch.
         */
        'template_media_base' => env('TWILIO_WHATSAPP_MEDIA_BASE', env('APP_URL')),
    ],

    /*
     * How long the signed link Twilio fetches the payslip from stays alive.
     *
     * Twilio collects the file as it accepts the message, so this only has to
     * cover that round trip and a retry. Minutes, because it is the one
     * unauthenticated path to somebody's salary — see PayslipMediaController.
     */
    'media_url_ttl' => (int) env('WHATSAPP_MEDIA_URL_TTL', 10),

    /*
     * Prefixed to numbers stored without one.
     *
     * Employee phones are kept as they are dialled locally — "0300-0000000" — and
     * WhatsApp accepts only E.164. A number already starting with + is left alone.
     */
    'default_country_code' => env('WHATSAPP_COUNTRY_CODE', '92'),

];
