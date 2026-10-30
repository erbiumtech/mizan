<?php

namespace Tests\Feature;

use App\Modules\Work\Integrations\Bitbucket\BitbucketProvider;
use App\Modules\Work\Integrations\Facts\BranchFact;
use App\Modules\Work\Integrations\Facts\CommitFact;
use App\Modules\Work\Integrations\Facts\PullRequestFact;
use App\Modules\Work\Integrations\GitLab\GitLabProvider;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\IntegrationEvent;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Bitbucket and GitLab — docs/project-management-plan.md §8.2–§8.4, Phase 8.
 *
 * "Parsing and auth; the facts and everything after them are already provider-free." So this is a
 * test of parsing and auth: each host's delivery shape becomes the same three facts GitHub's does,
 * and each host's way of proving itself is checked the way it sends it.
 */
class WorkCodeHostsTest extends TestCase
{
    private function connection(string $provider): IntegrationConnection
    {
        return (new IntegrationConnection)->forceFill([
            'provider' => $provider, 'kind' => 'code', 'label' => $provider, 'webhook_secret' => 'shh-'.$provider,
        ]);
    }

    private function event(string $type, array $payload): IntegrationEvent
    {
        return (new IntegrationEvent)->forceFill(['type' => $type, 'payload' => $payload]);
    }

    private function request(string $body, array $headers): Request
    {
        return Request::create('/hooks', 'POST', [], [], [], array_combine(
            array_map(fn (string $h): string => 'HTTP_'.strtoupper(str_replace('-', '_', $h)), array_keys($headers)),
            array_values($headers),
        ), $body);
    }

    // ---------------------------------------------------------------- GitLab

    public function test_gitlab_verifies_the_token_it_sent_back(): void
    {
        $provider = new GitLabProvider;
        $connection = $this->connection('gitlab');

        $this->assertTrue($provider->verify($connection, $this->request('{}', ['X-Gitlab-Token' => 'shh-gitlab'])));
        $this->assertFalse($provider->verify($connection, $this->request('{}', ['X-Gitlab-Token' => 'nope'])));
        $this->assertFalse($provider->verify($connection, $this->request('{}', [])));
    }

    public function test_gitlab_push_becomes_commits_and_a_branch(): void
    {
        $facts = (new GitLabProvider)->facts($this->event('Push Hook', [
            'object_kind' => 'push',
            'ref' => 'refs/heads/WEB-12-fix',
            'before' => str_repeat('0', 40),
            'after' => 'abc',
            'user_username' => 'ahmedk',
            'user_id' => 7,
            'user_email' => 'ahmed@test.local',
            'project' => ['id' => 99, 'web_url' => 'https://gitlab.example.com/g/p'],
            'commits' => [[
                'id' => 'abc123', 'message' => "WEB-12 #done\n\nfix", 'timestamp' => '2026-11-04T10:00:00Z',
                'url' => 'https://gitlab.example.com/g/p/-/commit/abc123', 'author' => ['name' => 'Ahmed', 'email' => 'ahmed@test.local'],
            ]],
        ]));

        $this->assertCount(2, $facts);
        $this->assertInstanceOf(BranchFact::class, $facts[0]);
        $this->assertSame('WEB-12-fix', $facts[0]->name);
        $this->assertFalse($facts[0]->deleted);
        $this->assertSame('99', $facts[0]->repositoryExternalId);

        $this->assertInstanceOf(CommitFact::class, $facts[1]);
        $this->assertSame('abc123', $facts[1]->sha);
        $this->assertSame('WEB-12 #done', $facts[1]->firstLine());
        $this->assertSame('ahmedk', $facts[1]->author['username']);
    }

    public function test_gitlab_merge_request_actions_map_to_the_three_outcomes(): void
    {
        $provider = new GitLabProvider;

        foreach (['open' => PullRequestFact::OPENED, 'merge' => PullRequestFact::MERGED, 'close' => PullRequestFact::CLOSED, 'update' => PullRequestFact::UPDATED] as $action => $expected) {
            $fact = $provider->facts($this->event('Merge Request Hook', [
                'object_kind' => 'merge_request',
                'project' => ['id' => 99],
                'user' => ['username' => 'ahmedk', 'id' => 7],
                'object_attributes' => ['iid' => 5, 'title' => 'WEB-12 fix', 'source_branch' => 'WEB-12-fix', 'action' => $action, 'url' => 'https://x/mr/5'],
            ]))[0];

            $this->assertInstanceOf(PullRequestFact::class, $fact);
            $this->assertSame($expected, $fact->action, "action {$action}");
            $this->assertSame(5, $fact->number);
        }
    }

    public function test_gitlab_recognises_a_merge_request_url(): void
    {
        $this->assertSame(['group/sub/project', 12], (new GitLabProvider)->parsePullRequestUrl('https://gitlab.example.com/group/sub/project/-/merge_requests/12'));
        $this->assertNull((new GitLabProvider)->parsePullRequestUrl('https://github.com/a/b/pull/1'));
    }

    // ---------------------------------------------------------------- Bitbucket

    public function test_bitbucket_verifies_an_hmac_and_refuses_an_unsigned_delivery(): void
    {
        $provider = new BitbucketProvider;
        $connection = $this->connection('bitbucket');
        $body = '{"a":1}';

        $this->assertTrue($provider->verify($connection, $this->request($body, ['X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $body, 'shh-bitbucket')])));
        $this->assertFalse($provider->verify($connection, $this->request($body, ['X-Hub-Signature' => 'sha256=0000'])));
        $this->assertFalse($provider->verify($connection, $this->request($body, [])), 'an unsigned hook is a URL anybody can post to');
    }

    public function test_bitbucket_push_becomes_commits_and_branches(): void
    {
        $facts = (new BitbucketProvider)->facts($this->event('repo:push', [
            'repository' => ['uuid' => '{r-1}', 'links' => ['html' => ['href' => 'https://bitbucket.org/w/r']]],
            'actor' => ['nickname' => 'ahmedk', 'uuid' => '{u-7}'],
            'push' => ['changes' => [[
                'created' => true,
                'closed' => false,
                'new' => ['type' => 'branch', 'name' => 'WEB-12-fix'],
                'commits' => [[
                    'hash' => 'deadbeef', 'message' => 'WEB-12 #time 1h 30m', 'date' => '2026-11-04T10:00:00+00:00',
                    'author' => ['raw' => 'Ahmed Khan <ahmed@test.local>', 'user' => ['nickname' => 'ahmedk', 'uuid' => '{u-7}']],
                    'links' => ['html' => ['href' => 'https://bitbucket.org/w/r/commits/deadbeef']],
                ]],
            ]]],
        ]));

        $this->assertCount(2, $facts);
        $this->assertInstanceOf(BranchFact::class, $facts[0]);
        $this->assertSame('r-1', $facts[0]->repositoryExternalId, 'the uuid without its braces');
        $this->assertSame('WEB-12-fix', $facts[0]->name);

        $this->assertInstanceOf(CommitFact::class, $facts[1]);
        $this->assertSame('ahmed@test.local', $facts[1]->author['email'], 'read out of the raw author');
        $this->assertSame('u-7', $facts[1]->author['id']);
    }

    public function test_bitbucket_pull_request_events_map_to_the_three_outcomes(): void
    {
        $provider = new BitbucketProvider;

        foreach (['pullrequest:created' => PullRequestFact::OPENED, 'pullrequest:fulfilled' => PullRequestFact::MERGED, 'pullrequest:rejected' => PullRequestFact::CLOSED, 'pullrequest:updated' => PullRequestFact::UPDATED] as $type => $expected) {
            $fact = $provider->facts($this->event($type, [
                'repository' => ['uuid' => '{r-1}'],
                'actor' => ['nickname' => 'ahmedk'],
                'pullrequest' => ['id' => 3, 'title' => 'WEB-12', 'source' => ['branch' => ['name' => 'WEB-12-fix']], 'updated_on' => '2026-11-04T10:00:00+00:00', 'author' => ['nickname' => 'ahmedk']],
            ]))[0];

            $this->assertSame($expected, $fact->action, $type);
            $this->assertSame(3, $fact->number);
            $this->assertSame($expected === PullRequestFact::MERGED, $fact->mergedAt !== null);
        }
    }

    public function test_bitbucket_recognises_a_pull_request_url(): void
    {
        $this->assertSame(['workspace/repo', 9], (new BitbucketProvider)->parsePullRequestUrl('https://bitbucket.org/workspace/repo/pull-requests/9/some-title'));
    }
}
