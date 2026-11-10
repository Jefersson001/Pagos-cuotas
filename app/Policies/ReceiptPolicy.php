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
        return $user->canOperateFinancial() && $this->view($user, $receipt)
            && ($user->isAdmin() || $receipt->approval_status === 'pending');
    }

    public function apply(User $user, Receipt $receipt): bool
    {
        return $user->canOperateFinancial() && $this->view($user, $receipt);
    }

    public function review(User $user, Receipt $receipt): bool
    {
        return ($user->isAdmin() || $user->isHeadOffice()) && $user->hasActiveAccess() && $this->view($user, $receipt);
    }
}
