<?php

namespace App\Modules\Work\Integrations;

use App\Modules\Work\Models\IntegrationConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * One connection's HTTP calls to its provider, through the circuit breaker — §8.6.
 *
 * GitHub has its own client because of the App JWT; every other provider is a base URL, a way
 * of carrying the credential, and this. A failure is recorded on the connection, the fifth in a
 * row pauses it for ten minutes, and a success clears the count. Retries only on a rate limit,
 * and only for as long as the provider says to wait.
 */
class RestClient
{
    /**
     * @param  callable(PendingRequest): PendingRequest  $authenticate  adds the credential to a request
     */
    public function __construct(
        private readonly IntegrationConnection $connection,
        private readonly string $baseUrl,
        private $authenticate,
    ) {}

    /** @param  array<string, mixed>  $query */
    public function get(string $path, array $query = []): array
    {
        return $this->call(fn (PendingRequest $http): Response => $http->get($this->url($path), $query));
    }

    /** @param  array<string, mixed>  $body */
    public function post(string $path, array $body = []): array
    {
        return $this->call(fn (PendingRequest $http): Response => $http->post($this->url($path), $body));
    }

    /** @param  array<string, mixed>  $body */
    public function put(string $path, array $body = []): array
    {
        return $this->call(fn (PendingRequest $http): Response => $http->put($this->url($path), $body));
    }

    /** @param  array<string, mixed>  $body */
    public function patch(string $path, array $body = []): array
    {
        return $this->call(fn (PendingRequest $http): Response => $http->patch($this->url($path), $body));
    }

    public function delete(string $path): void
    {
        $this->call(fn (PendingRequest $http): Response => $http->delete($this->url($path)));
    }

    private function url(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : rtrim($this->baseUrl, '/').$path;
    }

    /**
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
        } catch (Throwable $e) {
            $this->connection->recordFailure($e->getMessage());

            throw new RuntimeException($this->connection->providerLabel().' could not be reached: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $message = (string) ($response->json('message') ?? $response->json('error.message') ?? $response->json('error') ?? $response->body());
            $this->connection->recordFailure('HTTP '.$response->status().': '.$message);

            throw new RuntimeException($this->connection->providerLabel().' answered '.$response->status().': '.$message);
        }

        $this->connection->recordSuccess();

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function http(): PendingRequest
    {
        $http = Http::acceptJson()
            ->withHeaders(['User-Agent' => 'Mizan'])
            ->timeout(20)
            ->retry(
                2,
                fn (int $attempt, Throwable $e): int => $this->retryAfter($e),
                when: fn (Throwable $e): bool => $e instanceof RequestException && $e->response->status() === 429,
                throw: false,
            );

        return ($this->authenticate)($http);
    }

    private function retryAfter(Throwable $e): int
    {
        $after = $e instanceof RequestException ? $e->response->header('Retry-After') : '';

        return $after !== '' ? min((int) $after, 60) * 1000 : 1000;
    }
}
