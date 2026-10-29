<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ConcessionaireImport;
use Illuminate\Console\Command;

class ImportConcessionaires extends Command
{
    protected $signature = 'concesionarios:importar {archivo} {--user= : Usuario responsable} {--dealership= : Empresa interna} {--dry-run : Solo revisar sin guardar}';

    protected $description = 'Importar el listado comercial BERA CARS conservando sucursales y permitiendo repetir la importación';

    public function handle(ConcessionaireImport $importer): int
    {
        try {
            $owner = User::findOrFail((int) $this->option('user'));
            $result = $importer->import($this->argument('archivo'), $owner, (int) $this->option('dealership'), (bool) $this->option('dry-run'));
            $this->info(($this->option('dry-run') ? 'Revisión' : 'Importación').": {$result['total']} registros; {$result['created']} nuevos y {$result['updated']} existentes.");

            return self::SUCCESS;
        } catch (\Exception $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
