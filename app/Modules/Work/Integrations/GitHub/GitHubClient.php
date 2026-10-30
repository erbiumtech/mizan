<?php

namespace App\Modules\Work\Integrations\GitHub;

use App\Modules\Work\Models\IntegrationConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talking to GitHub for one connection — docs/project-management-plan.md §8.2 and §8.6.
 *
 * **A GitHub App, not OAuth**, for the connection that has one: the App's private key lives in
 * `.env` and never in a tenant row, and the connection stores only the installation id. A short
 * JWT signed with that key buys an installation token, cached until a minute before it expires.
 * The fallback is a personal access token typed in, for the company whose policy forbids
 * installing apps.
 *
 * Every call runs through the connection's circuit breaker: a failure is recorded, the fifth in a
 * row pauses the connection for ten minutes, and a success clears the count. Retries follow the
 * rate-limit headers GitHub sends rather than a fixed backoff.
 */
class GitHubClient
{
    public function __construct(private readonly IntegrationConnection $connection) {}

    public function baseUrl(): string
    {
        return rtrim($this->connection->base_url ?: 'https://api.github.com', '/');
    }

    /** @param  array<string, mixed>  $query */
    public function get(string $path, array $query = []): array
    {
        return $this->call(fn (PendingRequest $http): Response => $http->get($this->baseUrl().$path, $query));
    }

    /** @param  array<string, mixed>  $body */
    public function post(string $path, array $body = []): array
    {
        return $this->call(fn (PendingRequest $http): Response => $http->post($this->baseUrl().$path, $body));
    }

    public function delete(string $path): void
    {
        $this->call(fn (PendingRequest $http): Response => $http->delete($this->baseUrl().$path));
    }

    /**
     * One call, through the breaker.
     *
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws RuntimeException
     */
    private function call(callable $request): array
    {
        if (! $this->connection->isOpen()) {
            throw new RuntimeException($this->connection->label.' is paused after repeated failures'
                .($this->connection->paused_until ? ' until '.$this->connection->paused_until->format('H:i') : '').'.');
        }

        try {
            $response = $request($this->http());
        } catch (\Throwable $e) {
            $this->connection->recordFailure($e->getMessage());

            throw new RuntimeException('GitHub could not be reached: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $message = (string) ($response->json('message') ?? $response->body());
            $this->connection->recordFailure('HTTP '.$response->status().': '.$message);

            throw new RuntimeException('GitHub answered '.$response->status().': '.$message);
        }

        $this->connection->recordSuccess();

        return (array) $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->token())
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'Mizan'])
            ->timeout(20)
            // Rate limited: wait what GitHub says to wait, once. Anything else is a failure the
            // breaker counts, and the queue's own retry covers the inbound side.
            ->retry(
                2,
                fn (int $attempt, \Throwable $e): int => $this->retryAfter($e),
                when: fn (\Throwable $e): bool => $e instanceof RequestException && in_array($e->response->status(), [429, 403], true)
                    && $e->response->header('Retry-After') !== '',
                throw: false,
            );
    }

    private function retryAfter(\Throwable $e): int
    {
        $after = $e instanceof RequestException ? $e->response->header('Retry-After') : '';

        return $after !== '' ? min((int) $after, 60) * 1000 : 1000;
    }

    /**
     * The bearer token: the PAT, or an installation token minted from the App's key.
     *
     * @throws RuntimeException
     */
    public function token(): string
    {
        if ($this->connection->auth_kind !== IntegrationConnection::AUTH_APP) {
            $token = $this->connection->credential('token');

            if (blank($token)) {
                throw new RuntimeException($this->connection->label.' has no access token.');
            }

            return $token;
        }

        $installation = $this->connection->credential('installation_id');

        if (blank($installation)) {
            throw new RuntimeException($this->connection->label.' has no installation id.');
        }

        $key = 'work.github.installation.'.$this->connection->getKey().'.'.$installation;

        return Cache::remember($key, now()->addMinutes(50), function () use ($installation): string {
            $response = Http::withToken($this->appJwt())
                ->acceptJson()
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'Mizan'])
                ->post($this->baseUrl().'/app/installations/'.$installation.'/access_tokens');

            if ($response->failed() || blank($response->json('token'))) {
                throw new RuntimeException('GitHub would not issue an installation token: '.($response->json('message') ?? $response->status()));
            }

            return (string) $response->json('token');
        });
    }

    /**
     * A JWT for the App itself — RS256 over the App id, valid ten minutes.
     *
     * Signed with openssl directly rather than a JWT package: three fields, one algorithm, and a
     * dependency is a thing that has to be kept current.
     *
     * @throws RuntimeException
     */
    public function appJwt(): string
    {
        $appId = (string) config('work.github.app_id');
        $pem = (string) config('work.github.private_key');

        if ($appId === '' || $pem === '') {
            throw new RuntimeException('The GitHub App is not configured: set WORK_GITHUB_APP_ID and WORK_GITHUB_PRIVATE_KEY.');
        }

        $pem = str_replace('\n', "\n", $pem);

        $encode = fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        $header = $encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $payload = $encode(['iat' => time() - 60, 'exp' => time() + 540, 'iss' => $appId]);

        $signature = '';
        $key = openssl_pkey_get_private($pem);

        if ($key === false || ! openssl_sign($header.'.'.$payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The GitHub App private key could not be used to sign.');
        }

        return $header.'.'.$payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
