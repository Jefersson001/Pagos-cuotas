<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class Audit
{
    public static function log(int $actor, string $action, string $entity, int $id, array $details = [], ?int $dealershipId = null): void
    {
        $table = match ($entity) {
            'client' => 'clients','order' => 'orders','payment' => 'payments','receipt' => 'receipts','user' => 'users','payment_method' => 'payment_methods','product' => 'products',default => null
        };
        $dealershipId ??= $table ? DB::table($table)->where('id', $id)->value('dealership_id') : null;
        if (! $table && $entity !== 'user') {
            $dealershipId ??= DealershipContext::id(User::findOrFail($actor));
        }
        DB::table('audit_logs')->insert(['user_id' => $actor, 'dealership_id' => $dealershipId, 'action' => $action, 'entity' => $entity, 'entity_id' => $id, 'details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }
}
