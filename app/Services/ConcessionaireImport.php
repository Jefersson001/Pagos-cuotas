<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Dealership;
use App\Models\User;
use App\Support\XlsxReader;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConcessionaireImport
{
    public function __construct(private XlsxReader $reader) {}

    /** @return array{created: int, updated: int, total: int} */
    public function import(string $path, User $owner, int $companyId, bool $dryRun = false): array
    {
        $company = Dealership::findOrFail($companyId);
        if (! $company->active || ! $owner->active || ($owner->dealership_id !== $companyId && ! ($owner->isAdmin() && $owner->dealership_id === null))) {
            throw new RuntimeException('La empresa y el responsable deben estar activos y relacionados.');
        }
        $book = $this->reader->read($path);
        if (mb_strtoupper(trim($book['rows'][2]['C'] ?? '')) !== 'CLIENTE' || mb_strtoupper(trim($book['rows'][2]['R'] ?? '')) !== 'RIF') {
            throw new RuntimeException('El archivo no coincide con las columnas de BERA CARS - LISTADO.');
        }
        $records = [];
        foreach ($book['rows'] as $number => $row) {
            if ($number < 3 || trim($row['C'] ?? '') === '') {
                continue;
            }
            $text = fn (string $column) => trim(str_replace("\u{00A0}", ' ', $row[$column] ?? ''));
            if (! ctype_digit($text('A')) || (int) $text('A') < 1) {
                throw new RuntimeException("Número de listado inválido en la fila {$number}.");
            }
            $reference = 'BERA-CARS-'.str_pad($text('A'), 4, '0', STR_PAD_LEFT);
            if (isset($records[$reference])) {
                throw new RuntimeException("Número de listado repetido en la fila {$number}.");
            }
            if ($text('D') !== '' && ! ctype_digit($text('D'))) {
                throw new RuntimeException("Cantidad de neveras inválida en la fila {$number}.");
            }
            $records[$reference] = [
                'list_number' => (int) $text('A'), 'business_type' => $text('B') ?: 'CONCESIONARIO', 'name' => $text('C'),
                'refrigerators' => $text('D') === '' ? null : (int) $text('D'),
                'refrigerators_assigned_at' => $this->date($text('E'), $book['date1904']),
                'listed_started_at' => $this->date($text('F'), $book['date1904']),
                'condition' => $text('G') ?: null, 'commercial_registry' => $text('H') ?: null,
                'registry_jurisdiction' => $text('I') ?: null, 'registry_date' => $this->date($text('J'), $book['date1904']),
                'registry_number' => $text('K') ?: null, 'registry_volume' => $text('L') ?: null,
                'started_at' => $this->date($text('M'), $book['date1904']), 'legal_representative' => $text('N') ?: null,
                'address' => $text('O') ?: null, 'city' => $text('P') ?: null, 'state' => $text('Q') ?: null,
                'document' => $text('R'), 'phone' => $text('S'), 'email' => $text('T') ?: null,
                'active' => ! in_array(mb_strtoupper($text('G')), ['INACTIVO', 'INACTIVA'], true),
            ];
        }
        if (! $records) {
            throw new RuntimeException('El listado no contiene registros para importar.');
        }

        return DB::transaction(function () use ($records, $owner, $companyId, $dryRun) {
            Dealership::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $created = 0;
            $updated = 0;
            foreach ($records as $reference => $values) {
                $record = Client::where('dealership_id', $companyId)->where('import_reference', $reference)->lockForUpdate()->first();
                $record ? $updated++ : $created++;
                if ($dryRun) {
                    continue;
                }
                $record ??= new Client(['user_id' => $owner->id, 'dealership_id' => $companyId, 'import_reference' => $reference]);
                $before = $record->getAttributes();
                $record->fill($values)->save();
                Audit::log($owner->id, 'import', 'client', $record->id, ['before' => $before, 'after' => $record->getAttributes()], $companyId);
            }

            return ['created' => $created, 'updated' => $updated, 'total' => count($records)];
        }, 3);
    }

    private function date(string $value, bool $date1904): ?string
    {
        if ($value === '') {
            return null;
        }
        if (is_numeric($value) && (float) $value > 0 && (float) $value < 100000) {
            return Carbon::parse($date1904 ? '1904-01-01' : '1899-12-30')->addDays((int) $value)->toDateString();
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (\Exception) {
                continue;
            }
        }
        throw new RuntimeException('Fecha no válida en el Excel: '.$value);
    }
}
