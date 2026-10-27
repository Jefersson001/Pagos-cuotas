<?php

namespace App\Policies;

use App\Models\Receipt;
use App\Models\User;

class ReceiptPolicy
{
    public function view(User $user, Receipt $receipt): bool
    {
        return $user->canAccessRecord($receipt);
    }

    public function update(User $user, Receipt $receipt): bool
    {
        return $this->view($user, $receipt);
    }
}
