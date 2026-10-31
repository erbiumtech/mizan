<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The spec and the routes, held to each other — docs/project-management-plan.md §11 and §15.
 *
 * "Nothing today checks `openapi.yaml` against the mounted routes, so the two have been free to
 * drift since the file was written." Every route mounted under `/api/v1` has to be in the spec at
 * its method, and every path and method in the spec has to be mounted. Its first run was an audit:
 * twelve paths across Accounting, CRM and Performance were mounted and undocumented, and the spec
 * now carries them at path-and-method level.
 *
 * Compared as templates with the parameter names stripped — `/accounts/{account}` and
 * `/accounts/{id}` are the same path — because a parameter's name is the implementation's and its
 * position is the contract's.
 */
class ApiContractTest extends TestCase
{
    private const SPEC = 'docs/api/openapi.yaml';

    public function test_every_mounted_v1_route_is_in_the_spec_and_every_spec_path_is_mounted(): void
    {
        $spec = $this->specOperations();
        $mounted = $this->mountedOperations();

        $undocumented = array_values(array_diff($mounted, $spec));
        $unmounted = array_values(array_diff($spec, $mounted));

        $this->assertSame([], $undocumented, implode("\n", [
            'These routes are mounted under /api/v1 and absent from '.self::SPEC.'. A client cannot discover',
            'them, and the next change to their shape is a change nobody wrote down. Add each at its method.',
            '',
            ...$undocumented,
        ]));

        $this->assertSame([], $unmounted, implode("\n", [
            'These operations are in '.self::SPEC.' and nothing serves them. Either the route moved and the',
            'spec did not follow, or the spec promises an endpoint that was never built.',
            '',
            ...$unmounted,
        ]));
    }

    public function test_the_spec_parses_and_names_the_versioned_server_first(): void
    {
        $spec = Yaml::parseFile(base_path(self::SPEC));

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertStringEndsWith('/api/v1', $spec['servers'][0]['url'], 'new work targets /v1; the bare prefix is the deprecated one');
        $this->assertNotEmpty($spec['paths']);
    }

    /** @return array<int, string> `METHOD /path/{}` */
    private function specOperations(): array
    {
        $spec = Yaml::parseFile(base_path(self::SPEC));
        $operations = [];

        foreach ($spec['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                $operations[] = strtoupper($method).' '.$this->template($path);
            }
        }

        sort($operations);

        return array_values(array_unique($operations));
    }

    /** @return array<int, string> */
    private function mountedOperations(): array
    {
        $operations = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            $path = '/'.substr($uri, strlen('api/v1/'));

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $operations[] = $method.' '.$this->template($path);
            }
        }

        sort($operations);

        return array_values(array_unique($operations));
    }

    /** `/accounts/{account}` → `/accounts/{}`: the position is the contract, the name is not. */
    private function template(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{}', rtrim($path, '/')) ?? $path;
    }
}
