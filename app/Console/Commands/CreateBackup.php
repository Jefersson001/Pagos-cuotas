<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('app:backup {--if-due : Crear solo si no existe una copia valida de las ultimas 20 horas}')]
#[Description('Respaldar la base de datos y adjuntos, verificando su restauracion aislada')]
class CreateBackup extends Command
{
    public function handle(DatabaseBackup $backup): int
    {
        $lock = Cache::store('file')->lock('ventas-cuotas-database-backup', 1800);
        if (! $lock->get()) {
            $this->info('Ya hay un respaldo en curso.');

            return self::SUCCESS;
        }
        try {
            $archives = collect(glob($backup->directory().'/ventas-cuotas-*.zip') ?: [])->sortByDesc(fn (string $path): int => filemtime($path))->values();
            if ($this->option('if-due') && $archives->isNotEmpty() && filemtime($archives->first()) > now()->subHours(20)->timestamp) {
                try {
                    $backup->verifyArchive($archives->first());
                    $this->info('Ya existe un respaldo reciente verificado.');

                    return self::SUCCESS;
                } catch (Throwable) {
                    $this->warn('La copia reciente no es valida. Se creara otra.');
                }
            }
            $path = $backup->create();
            foreach ($archives->skip(29) as $oldArchive) {
                File::delete($oldArchive);
            }
            $this->info('Respaldo y restauracion verificados: '.$path);
            Log::info('Respaldo automatico verificado.', ['file' => basename($path)]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('No se pudo crear el respaldo automatico.', ['reason' => $exception->getMessage()]);
            $this->error('No se pudo crear el respaldo. Las copias anteriores se conservaron. Revisa el registro de Laravel.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
