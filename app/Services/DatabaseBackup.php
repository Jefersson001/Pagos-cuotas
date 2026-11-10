<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class DatabaseBackup
{
    public function directory(): string
    {
        return config('backup.directory', storage_path('app/private/backups/automatic'));
    }

    public function create(): string
    {
        $directory = $this->directory();
        File::ensureDirectoryExists($directory, 0700);
        $working = $directory.'/.working-'.Str::uuid();
        File::makeDirectory($working, 0700);
        $archive = $directory.'/ventas-cuotas-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.zip';
        $partial = $archive.'.partial';

        try {
            $driver = DB::connection()->getDriverName();
            $databaseFile = $driver === 'sqlite' ? 'database.sqlite' : 'database.sql';
            $tableCounts = $this->dumpAndRestore($working.'/'.$databaseFile, $driver);
            $files = [$databaseFile => $working.'/'.$databaseFile];
            foreach (['public' => Storage::disk('public')->path(''), 'private/receipt-proofs' => Storage::disk('local')->path('receipt-proofs')] as $prefix => $root) {
                if (! is_dir($root)) {
                    continue;
                }
                $resolvedRoot = realpath($root).DIRECTORY_SEPARATOR;
                foreach (File::allFiles($root) as $file) {
                    if (! str_starts_with($file->getRealPath(), $resolvedRoot)) {
                        throw new RuntimeException('Un adjunto apunta fuera de su carpeta.');
                    }
                    $files[$prefix.'/'.str_replace('\\', '/', $file->getRelativePathname())] = $file->getRealPath();
                }
            }
            $zip = new ZipArchive;
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('No se pudo crear el archivo de respaldo.');
            }
            $hashes = [];
            try {
                foreach ($files as $name => $path) {
                    $hashes[$name] = hash_file('sha256', $path);
                    if (! $zip->addFile($path, $name)) {
                        throw new RuntimeException('No se pudo incluir un archivo en el respaldo.');
                    }
                }
                $zip->addFromString('manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'driver' => $driver, 'restore_verified' => true, 'table_counts' => $tableCounts, 'sha256' => $hashes], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            } finally {
                $zip->close();
            }
            $this->verifyArchive($partial);
            if (! rename($partial, $archive)) {
                throw new RuntimeException('No se pudo finalizar el respaldo.');
            }

            return $archive;
        } finally {
            File::deleteDirectory($working);
            File::delete($partial);
        }
    }

    public function verifyArchive(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('El archivo de respaldo no es valido.');
        }
        try {
            $manifest = json_decode($zip->getFromName('manifest.json') ?: '', true, 512, JSON_THROW_ON_ERROR);
            if (empty($manifest['restore_verified']) || empty($manifest['sha256'])) {
                throw new RuntimeException('El respaldo no tiene una restauracion verificada.');
            }
            foreach ($manifest['sha256'] as $name => $expected) {
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new RuntimeException('Falta un archivo del respaldo.');
                }
                try {
                    $hash = hash_init('sha256');
                    hash_update_stream($hash, $stream);
                    if (! hash_equals($expected, hash_final($hash))) {
                        throw new RuntimeException('El respaldo contiene un archivo alterado.');
                    }
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, int> */
    private function dumpAndRestore(string $path, string $driver): array
    {
        if ($driver === 'sqlite') {
            DB::connection()->getPdo()->prepare('VACUUM INTO ?')->execute([$path]);
            $restored = new PDO('sqlite:'.$path);
            if ($restored->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('Fallo la restauracion de SQLite.');
            }
            $counts = [];
            foreach ($restored->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $counts[$table] = (int) $restored->query('SELECT COUNT(*) FROM "'.str_replace('"', '""', $table).'"')->fetchColumn();
            }
            $restored = null;

            return $counts;
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('El motor de base de datos no admite este respaldo.');
        }
        $config = DB::connection()->getConfig();
        $this->mysqlProcess('mysqldump', ['--single-transaction', '--quick', '--hex-blob', '--triggers', '--result-file='.$path, $config['database']], $config);
        $temporaryDatabase = 'ventas_cuotas_verify_'.strtolower(Str::random(16));
        DB::statement('CREATE DATABASE `'.$temporaryDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $restored = null;
        try {
            $input = fopen($path, 'rb');
            try {
                $this->mysqlProcess('mysql', [$temporaryDatabase], $config, $input);
            } finally {
                fclose($input);
            }
            $restored = DB::build(array_replace($config, ['database' => $temporaryDatabase, 'url' => null]));
            $counts = [];
            foreach ($restored->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
                $table = array_values((array) $row)[0];
                $quoted = '`'.str_replace('`', '``', $table).'`';
                $counts[$table] = (int) $restored->selectOne('SELECT COUNT(*) AS total FROM '.$quoted)->total;
                foreach ($restored->select('CHECK TABLE '.$quoted) as $check) {
                    if ($check->Msg_type === 'error') {
                        throw new RuntimeException('Fallo la comprobacion de una tabla restaurada.');
                    }
                }
            }

            return $counts;
        } finally {
            $restored?->disconnect();
            DB::statement('DROP DATABASE `'.$temporaryDatabase.'`');
        }
    }

    /**
     * @param  array<int, string>  $arguments
     * @param  array<string, mixed>  $config
     * @param  resource|null  $input
     */
    private function mysqlProcess(string $binary, array $arguments, array $config, mixed $input = null): void
    {
        $directory = config('backup.mysql_bin_directory', PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/mysql/bin' : '/usr/bin');
        $executable = $directory.'/'.$binary.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        $connection = ['--host='.($config['host'] ?? '127.0.0.1'), '--port='.($config['port'] ?? 3306), '--user='.$config['username'], '--default-character-set=utf8mb4'];
        $connection[] = empty($config['unix_socket']) ? '--protocol=TCP' : '--socket='.$config['unix_socket'];
        $process = new Process([$executable, '--no-defaults', ...$connection, ...$arguments], base_path(), ['MYSQL_PWD' => (string) ($config['password'] ?? '')]);
        $process->setTimeout(300);
        $process->setInput($input);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('No se pudo completar '.$binary.'. Revisa la disponibilidad y permisos de MySQL.');
        }
    }
}
