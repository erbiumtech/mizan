<?php

namespace Tests\Unit;

use App\Health\PublicStorageLinkCheck;
use Spatie\Health\Enums\Status;
use Tests\TestCase;

class PublicStorageLinkCheckTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/storage-link-'.uniqid());
    }

    protected function tearDown(): void
    {
        if (is_link($this->path)) {
            unlink($this->path);
        } elseif (is_dir($this->path)) {
            rmdir($this->path);
        }

        parent::tearDown();
    }

    public function test_ok_when_nothing_is_there(): void
    {
        $this->assertSame(Status::ok(), PublicStorageLinkCheck::new()->path($this->path)->run()->status);
    }

    /** file_exists() says false for a dangling link; the check must not. */
    public function test_a_dangling_link_still_fails(): void
    {
        symlink('/no/such/target-'.uniqid(), $this->path);

        $result = PublicStorageLinkCheck::new()->path($this->path)->run();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertTrue($result->meta['link']);
    }

    public function test_a_directory_fails(): void
    {
        mkdir($this->path, 0777, true);

        $this->assertSame(Status::failed(), PublicStorageLinkCheck::new()->path($this->path)->run()->status);
    }

    /** The default is the real one, which PublicStorageIsNotExposedTest keeps absent in this tree. */
    public function test_it_looks_at_public_storage_by_default(): void
    {
        $this->assertSame(Status::ok(), PublicStorageLinkCheck::new()->run()->status);
    }
}
