<?php

declare(strict_types=1);

namespace Tests\ApiAdmin\Unit;

use App\ApiAdmin\MCP\AtomicFileWriter;
use App\ApiAdmin\MCP\GenerationTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class GenerationTransactionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/api-admin-transaction-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory);
        app()->useStoragePath($this->directory.'/storage');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_failed_generation_restores_replaced_deleted_and_created_files(): void
    {
        AtomicFileWriter::put($this->directory.'/original.php', 'original');
        AtomicFileWriter::put($this->directory.'/old/.hidden/config.php', 'old config');
        $result = GenerationTransaction::run(function (): array {
            AtomicFileWriter::put($this->directory.'/original.php', 'modified');
            AtomicFileWriter::put($this->directory.'/new/deep/resource.php', 'created');
            AtomicFileWriter::deleteDirectory($this->directory.'/old');

            return ['success' => false, 'error' => 'validation'];
        });
        $this->assertFalse($result['success']);
        $this->assertSame('original', File::get($this->directory.'/original.php'));
        $this->assertSame('old config', File::get($this->directory.'/old/.hidden/config.php'));
        $this->assertDirectoryDoesNotExist($this->directory.'/new');
    }

    public function test_failed_nested_generation_rolls_back_entire_instructor_operation(): void
    {
        $result = GenerationTransaction::run(function (): array {
            AtomicFileWriter::put($this->directory.'/service.php', 'service');
            GenerationTransaction::run(function (): array {
                AtomicFileWriter::put($this->directory.'/resource.php', 'partial');

                return ['success' => false];
            });

            return ['success' => true];
        });
        $this->assertFalse($result['success']);
        $this->assertFileDoesNotExist($this->directory.'/service.php');
        $this->assertFileDoesNotExist($this->directory.'/resource.php');
    }

    public function test_exception_restores_config_environment_and_allows_next_transaction(): void
    {
        $key = 'API_ADMIN_GENERATION_TEST_'.bin2hex(random_bytes(4));
        config()->set('generation-test.value', 'before');

        try {
            GenerationTransaction::run(function () use ($key): array {
                AtomicFileWriter::put($this->directory.'/failure.php', 'partial');
                config()->set('generation-test.value', 'after');
                $_ENV[$key] = 'secret';
                $_SERVER[$key] = 'secret';
                putenv($key.'=secret');

                throw new RuntimeException('Generation failure');
            });
            $this->fail('Expected generation failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Generation failure', $exception->getMessage());
        }
        $this->assertSame('before', config('generation-test.value'));
        $this->assertFalse(getenv($key));
        $this->assertArrayNotHasKey($key, $_ENV);
        $this->assertArrayNotHasKey($key, $_SERVER);
        $this->assertFileDoesNotExist($this->directory.'/failure.php');
        $this->assertTrue(GenerationTransaction::run(fn (): array => ['success' => true])['success']);
    }

    public function test_atomic_replacement_and_rollback_preserve_deployment_symlinks_and_permissions(): void
    {
        AtomicFileWriter::put($this->directory.'/runtime/.env', 'old secret');
        chmod($this->directory.'/runtime/.env', 0600);
        symlink('runtime/.env', $this->directory.'/.env');
        GenerationTransaction::run(function (): array {
            AtomicFileWriter::put($this->directory.'/.env', 'new secret');
            $this->assertTrue(is_link($this->directory.'/.env'));
            $this->assertSame('new secret', File::get($this->directory.'/runtime/.env'));

            return ['success' => false];
        });
        $this->assertTrue(is_link($this->directory.'/.env'));
        $this->assertSame('old secret', File::get($this->directory.'/runtime/.env'));
        $this->assertSame(0600, fileperms($this->directory.'/runtime/.env') & 0777);
    }

    public function test_lock_excludes_other_writers_and_times_out_without_mutation(): void
    {
        File::ensureDirectoryExists(storage_path('api-admin/locks'));
        $otherWriter = fopen(storage_path('api-admin/locks/generation.lock'), 'c');
        $this->assertIsResource($otherWriter);
        flock($otherWriter, LOCK_EX);
        config()->set('api-admin.generation_lock_timeout', 0.02);

        try {
            GenerationTransaction::run(function (): array {
                AtomicFileWriter::put($this->directory.'/concurrent.php', 'unsafe');

                return ['success' => true];
            });
            $this->fail('Concurrent writer should time out');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Generation is busy', $exception->getMessage());
        } finally {
            flock($otherWriter, LOCK_UN);
            fclose($otherWriter);
        }
        $this->assertFileDoesNotExist($this->directory.'/concurrent.php');
        $this->assertTrue(GenerationTransaction::run(fn (): array => ['success' => true])['success']);
    }

    public function test_hosted_secrets_database_write_rolls_back_with_generated_files(): void
    {
        config()->set('database.default', 'generation');
        config()->set('database.connections.generation', ['driver' => 'sqlite', 'database' => ':memory:']);
        config()->set('api-admin.hosted.mode', 'hosted');
        DB::statement('CREATE TABLE generation_secrets (value TEXT)');
        GenerationTransaction::run(function (): array {
            DB::table('generation_secrets')->insert(['value' => 'encrypted secret']);
            AtomicFileWriter::put($this->directory.'/service.php', 'config');

            return ['success' => false];
        });
        $this->assertSame(0, DB::table('generation_secrets')->count());
        $this->assertFileDoesNotExist($this->directory.'/service.php');
    }

    /** @return iterable<string, array{bool}> */
    public static function failedDeletions(): iterable
    {
        yield 'file' => [false];
        yield 'directory' => [true];
    }

    #[DataProvider('failedDeletions')]
    public function test_failed_deletion_restores_partial_deletion_and_prior_writes(bool $directory): void
    {
        $target = $this->directory.'/delete-me';
        $deletedFile = $directory ? $target.'/child.php' : $target;
        AtomicFileWriter::put($deletedFile, 'original deleted content');
        AtomicFileWriter::put($this->directory.'/config.php', 'original config');
        $filesystem = File::getFacadeRoot();
        File::partialMock();
        $expectation = $directory ? File::shouldReceive('deleteDirectory') : File::shouldReceive('delete');
        $expectation->once()->with($target)->andReturnUsing(function () use ($deletedFile): bool {
            // A recursive deletion can remove some children before failing.
            unlink($deletedFile);

            return false;
        });

        try {
            GenerationTransaction::run(function () use ($directory, $target): array {
                AtomicFileWriter::put($this->directory.'/config.php', 'changed config');
                if ($directory) {
                    AtomicFileWriter::deleteDirectory($target);
                } else {
                    AtomicFileWriter::delete($target);
                }

                return ['success' => true];
            });
            $this->fail('Failed deletion must abort the generation transaction');
        } catch (RuntimeException $exception) {
            $this->assertSame($directory ? 'Cannot delete generated directory.' : 'Cannot delete generated file.', $exception->getMessage());
        } finally {
            File::swap($filesystem);
        }

        $this->assertSame('original config', File::get($this->directory.'/config.php'));
        $this->assertSame('original deleted content', File::get($deletedFile));
    }

    public function test_deleting_absent_paths_succeeds(): void
    {
        $result = GenerationTransaction::run(function (): array {
            $this->assertTrue(AtomicFileWriter::delete($this->directory.'/absent.php'));
            $this->assertTrue(AtomicFileWriter::deleteDirectory($this->directory.'/absent-directory'));

            return ['success' => true];
        });
        $this->assertTrue($result['success']);
    }
}
