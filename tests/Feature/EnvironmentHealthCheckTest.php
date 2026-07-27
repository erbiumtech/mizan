<?php

namespace Tests\Feature;

use App\Jobs\CheckEnvironmentHealth;
use App\Models\ProjectEnvironment;
use App\Models\ProjectEnvironmentCheck;
use App\Services\EnvironmentHealthChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\MakesProjects;
use Tests\TestCase;

class EnvironmentHealthCheckTest extends TestCase
{
    use MakesProjects;
    use RefreshDatabase;

    private function checker(): EnvironmentHealthChecker
    {
        return app(EnvironmentHealthChecker::class);
    }

    private function environment(array $attributes = []): ProjectEnvironment
    {
        return $this->makeEnvironment($this->makeProject(), array_merge([
            'url' => 'https://prod.example.test',
        ], $attributes));
    }

    public function test_a_200_is_up_and_records_one_history_row(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $environment = $this->environment();

        $result = $this->checker()->check($environment);

        $this->assertTrue($result->isUp);
        $this->assertSame(200, $result->statusCode);

        $environment->refresh();
        $this->assertSame(ProjectEnvironment::HEALTH_UP, $environment->health_status);
        $this->assertSame(200, $environment->health_code);
        $this->assertNotNull($environment->health_checked_at);
        $this->assertSame(1, ProjectEnvironmentCheck::count());
    }

    public function test_a_500_is_down_with_the_code_stored(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $environment = $this->environment();

        $result = $this->checker()->check($environment);

        $this->assertFalse($result->isUp);
        $this->assertSame(500, $result->statusCode);
        $this->assertSame(ProjectEnvironment::HEALTH_DOWN, $environment->refresh()->health_status);
    }

    /**
     * The deliberate decision from the plan: most of these URLs sit behind basic
     * auth or SSO, so "the server answered" is the signal. Do not "fix" this to
     * report down — it would make the dashboard unreadable.
     */
    #[DataProvider('authGatedCodes')]
    public function test_auth_gated_responses_count_as_up(int $code): void
    {
        Http::fake(['*' => Http::response('', $code)]);

        $result = $this->checker()->check($this->environment());

        $this->assertTrue($result->isUp, "HTTP {$code} should count as reachable");
    }

    public static function authGatedCodes(): array
    {
        return [[401], [403]];
    }

    public function test_a_redirect_is_up_and_the_target_is_not_requested(): void
    {
        Http::fake([
            'prod.example.test' => Http::response('', 302, ['Location' => 'https://elsewhere.example.test/login']),
            'elsewhere.example.test' => Http::response('', 200),
        ]);

        $result = $this->checker()->check($this->environment());

        $this->assertTrue($result->isUp);
        $this->assertSame(302, $result->statusCode);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'elsewhere.example.test'));
    }

    public function test_a_connection_failure_is_down_with_a_truncated_reason(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds'));

        $result = $this->checker()->check($this->environment());

        $this->assertFalse($result->isUp);
        $this->assertNull($result->statusCode);
        $this->assertStringContainsString('timed out', $result->error);
        $this->assertLessThanOrEqual(255, strlen($this->environment()->refresh()->health_error ?? ''));
    }

    public function test_a_head_rejection_is_retried_with_get(): void
    {
        $calls = 0;

        Http::fake(function (Request $request) use (&$calls) {
            $calls++;

            return $request->method() === 'HEAD'
                ? Http::response('', 405)
                : Http::response('ok', 200);
        });

        $result = $this->checker()->check($this->environment());

        $this->assertTrue($result->isUp);
        $this->assertSame(200, $result->statusCode);
        $this->assertSame(2, $calls, 'expected a HEAD then a GET');
    }

    public function test_unmonitored_and_urlless_environments_are_never_dispatched(): void
    {
        $this->environment(['is_monitored' => false]);
        $this->makeEnvironment($this->makeProject(), ['kind' => 'qual', 'url' => null]);

        $this->assertCount(0, ProjectEnvironment::dueForCheck());
    }

    public function test_the_job_skips_an_environment_turned_off_after_dispatch(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $environment = $this->environment();

        $environment->update(['is_monitored' => false]);

        (new CheckEnvironmentHealth($environment->refresh()))->handle($this->checker());

        $this->assertSame(0, ProjectEnvironmentCheck::count());
    }

    public function test_uptime_is_null_without_history_and_windowed_when_present(): void
    {
        $environment = $this->environment();

        $this->assertNull($environment->uptimePercent());

        // 3 up, 1 down inside the window; one ancient failure outside it.
        foreach ([true, true, true, false] as $isUp) {
            $environment->checks()->create([
                'checked_at' => now()->subHours(2),
                'is_up' => $isUp,
            ]);
        }

        $environment->checks()->create([
            'checked_at' => now()->subDays(60),
            'is_up' => false,
        ]);

        // 3 of 4 inside 30 days; 3 of 5 once the ancient failure is included.
        $this->assertSame(75.0, $environment->uptimePercent(30));
        $this->assertSame(60.0, $environment->uptimePercent(90));
    }

    public function test_pruning_drops_history_past_the_retention_window(): void
    {
        config(['projects.health.retention_days' => 30]);
        $environment = $this->environment();

        $environment->checks()->create(['checked_at' => now()->subDays(31), 'is_up' => true]);
        $environment->checks()->create(['checked_at' => now()->subDay(), 'is_up' => true]);

        $this->artisan('model:prune', ['--model' => [ProjectEnvironmentCheck::class]])->assertSuccessful();

        $this->assertSame(1, ProjectEnvironmentCheck::count());
    }
}
