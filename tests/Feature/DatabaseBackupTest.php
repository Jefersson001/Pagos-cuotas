<?php

namespace Tests\Feature;

use App\Services\DatabaseBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class DatabaseBackupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $path = Storage::disk('local')->path('fixture.sqlite');
        touch($path);
        config(['database.default' => 'backup_fixture', 'database.connections.backup_fixture' => ['driver' => 'sqlite', 'database' => $path], 'backup.directory' => Storage::disk('local')->path('backups/automatic')]);
        DB::statement('CREATE TABLE markers (id INTEGER PRIMARY KEY, value TEXT)');
        DB::table('markers')->insert(['id' => 1, 'value' => 'Datos que deben restaurarse']);
    }

    protected function tearDown(): void
    {
        DB::purge('backup_fixture');
        parent::tearDown();
    }

    public function test_archive_restores_database_and_includes_attachments_without_secrets(): void
    {
        Storage::disk('public')->put('products/photo.png', 'image-content');
        Storage::disk('local')->put('receipt-proofs/proof.pdf', 'proof-content');
        Storage::disk('local')->put('.env', 'secret-do-not-back-up');
        $backup = app(DatabaseBackup::class);
        $path = $backup->create();
        $backup->verifyArchive($path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($manifest['restore_verified']);
        $this->assertSame(['markers' => 1], $manifest['table_counts']);
        $this->assertSame('image-content', $zip->getFromName('public/products/photo.png'));
        $this->assertSame('proof-content', $zip->getFromName('private/receipt-proofs/proof.pdf'));
        $this->assertFalse($zip->getFromName('.env'));
        $restoredPath = Storage::disk('local')->path('restored.sqlite');
        file_put_contents($restoredPath, $zip->getFromName('database.sqlite'));
        $zip->close();
        $restored = new PDO('sqlite:'.$restoredPath);
        $this->assertSame('Datos que deben restaurarse', $restored->query('SELECT value FROM markers')->fetchColumn());
        $this->assertSame(1, DB::table('markers')->count());
    }

    public function test_due_command_skips_a_recent_valid_copy_and_replaces_a_corrupted_one(): void
    {
        $this->artisan('app:backup')->assertSuccessful();
        $directory = app(DatabaseBackup::class)->directory();
        $archives = glob($directory.'/*.zip');
        $this->assertCount(1, $archives);
        $this->artisan('app:backup', ['--if-due' => true])->assertSuccessful();
        $this->assertCount(1, glob($directory.'/*.zip'));
        $zip = new ZipArchive;
        $zip->open($archives[0]);
        $zip->addFromString('database.sqlite', 'corrupted');
        $zip->close();
        $this->artisan('app:backup', ['--if-due' => true])->assertSuccessful();
        $this->assertCount(2, glob($directory.'/*.zip'));
        $this->assertSame(1, DB::table('markers')->count());
    }

    public function test_modified_backup_is_rejected(): void
    {
        $backup = app(DatabaseBackup::class);
        $path = $backup->create();
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('database.sqlite', 'corrupted');
        $zip->close();

        $this->expectException(RuntimeException::class);
        $backup->verifyArchive($path);
    }

    public function test_failure_preserves_existing_backup(): void
    {
        $backup = app(DatabaseBackup::class);
        $path = $backup->create();
        $hash = hash_file('sha256', $path);
        $blockedPath = Storage::disk('local')->path('not-a-directory');
        file_put_contents($blockedPath, 'blocked');
        config(['backup.directory' => $blockedPath]);
        $this->artisan('app:backup')->assertFailed();
        $this->assertSame($hash, hash_file('sha256', $path));
        $backup->verifyArchive($path);
    }
}
