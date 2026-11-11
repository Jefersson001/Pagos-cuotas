<?php

namespace Tests;

use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;

abstract class TestCase extends BaseTestCase
{
    protected function approvalProof(): UploadedFile
    {
        return UploadedFile::fake()->create('ingreso-casa-matriz.pdf', 20, 'application/pdf');
    }

    protected function approvePendingReceipts(): void
    {
        $pending = Receipt::where('approval_status', 'pending')->whereNull('voided_at')->get();
        if ($pending->isEmpty()) {
            return;
        }
        $admin = User::whereHas('role', fn ($roles) => $roles->where('name', 'admin'))->first()
            ?? User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id'), 'dealership_id' => null]);
        foreach ($pending as $receipt) {
            app(ReceiptService::class)->approve($admin, $receipt, null, $this->approvalProof());
        }
    }
}
