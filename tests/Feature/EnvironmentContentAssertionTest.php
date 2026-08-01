<?php

namespace Tests\Feature;

use App\Modules\Projects\Models\ProjectEnvironmentCheck;
use App\Modules\Projects\Services\EnvironmentHealthChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\MakesProjects;
use Tests\TestCase;

class EnvironmentContentAssertionTest extends TestCase
{
    use MakesProjects;
    use RefreshDatabase;

    private function check(array $attributes)
    {
        $environment = $this->makeEnvironment($this->makeProject(), $attributes);

        return [app(EnvironmentHealthChecker::class)->check($environment), $environment];
    }

    public function test_an_expected_content_check_uses_get_and_passes_when_present(): void
    {
        Http::fake(fn () => Http::response('<html>status: OK</html>', 200));

        [$result] = $this->check(['expected_content' => 'status: OK']);

        $this->assertTrue($result->isUp);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
    }

    public function test_a_200_that_lost_the_expected_content_is_down(): void
    {
        Http::fake(fn () => Http::response('<html>Whoops, something went wrong</html>', 200));

        [$result] = $this->check(['expected_content' => 'status: OK']);

        $this->assertFalse($result->isUp);
        $this->assertSame(200, $result->statusCode);
        $this->assertSame('Content assertion failed.', $result->error);
    }

    public function test_expected_status_overrides_the_default_rule(): void
    {
        // A single mutable fake: calling Http::fake() twice would append a stub
        // and keep matching the first one.
        $status = 204;
        Http::fake(function () use (&$status) {
            return Http::response('', $status);
        });

        [$ok] = $this->check(['expected_status' => 204]);
        $this->assertTrue($ok->isUp);

        // A 200 is normally healthy, but not when 204 is demanded.
        $status = 200;
        [$mismatch] = $this->check(['expected_status' => 204, 'kind' => 'qual']);
        $this->assertFalse($mismatch->isUp);
        $this->assertStringContainsString('Expected HTTP 204', $mismatch->error);
    }

    public function test_an_auth_gated_response_is_not_up_when_a_status_is_demanded(): void
    {
        Http::fake(fn () => Http::response('', 401));

        [$result] = $this->check(['expected_status' => 200]);

        $this->assertFalse($result->isUp);
    }

    public function test_no_response_body_is_ever_persisted(): void
    {
        Http::fake(fn () => Http::response('SUPER SECRET BODY status: OK', 200));

        [, $environment] = $this->check(['expected_content' => 'status: OK']);

        $stored = json_encode(ProjectEnvironmentCheck::all()->toArray())
            .json_encode($environment->refresh()->toArray());

        $this->assertStringNotContainsString('SUPER SECRET BODY', $stored);
    }
}
