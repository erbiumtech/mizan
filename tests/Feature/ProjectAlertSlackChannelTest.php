<?php

namespace Tests\Feature;

use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Models\ProjectEnvironmentIncident;
use App\Modules\Projects\Notifications\CertificateExpiring;
use App\Modules\Projects\Notifications\EnvironmentDown;
use App\Support\Broadcasting;
use Tests\TestCase;

/**
 * Slack joins an alert's via() only when the bot token is configured — docs/projects-listing-plan.md §11.
 *
 * The gate lives in Broadcasting::channels(), which every alert's via() already routes through:
 * SlackChannel throws a LogicException without a token, and that would be one failed queue job
 * per recipient rather than a channel that quietly is not there.
 */
class ProjectAlertSlackChannelTest extends TestCase
{
    /** Unsaved models are enough: via() reads config, never the database. */
    private function downNotification(): EnvironmentDown
    {
        return new EnvironmentDown(new ProjectEnvironment, new ProjectEnvironmentIncident);
    }

    public function test_via_includes_slack_when_the_bot_token_is_configured(): void
    {
        config(['services.slack.notifications.bot_user_oauth_token' => 'xoxb-token']);

        $this->assertContains('slack', $this->downNotification()->via(new \stdClass));
        $this->assertContains('slack', (new CertificateExpiring(new ProjectEnvironment, 7))->via(new \stdClass));
    }

    public function test_via_excludes_slack_without_a_bot_token(): void
    {
        config(['services.slack.notifications.bot_user_oauth_token' => null]);

        $via = $this->downNotification()->via(new \stdClass);

        $this->assertNotContains('slack', $via);
        $this->assertContains('mail', $via, 'mail must be untouched by the slack gate');
    }

    public function test_an_empty_env_token_counts_as_unconfigured(): void
    {
        // `SLACK_BOT_USER_OAUTH_TOKEN=` in a .env is "", not absent.
        config(['services.slack.notifications.bot_user_oauth_token' => '']);

        $this->assertNotContains('slack', $this->downNotification()->via(new \stdClass));
    }

    public function test_the_gate_preserves_channel_order_and_only_touches_slack(): void
    {
        config([
            'services.slack.notifications.bot_user_oauth_token' => 'xoxb-token',
            'broadcasting.default' => 'reverb',
            'filament.broadcasting.echo.key' => 'a-key',
        ]);

        $this->assertSame(
            ['mail', 'database', 'broadcast', 'slack'],
            Broadcasting::channels(['mail', 'database', 'broadcast', 'slack']),
        );
    }
}
