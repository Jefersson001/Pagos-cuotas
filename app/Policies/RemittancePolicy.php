<?php

namespace App\Policies;

use App\Models\Remittance;
use App\Models\User;

class RemittancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasActiveAccess() && ($user->isAdmin() || $user->isHeadOffice() || ($user->canOperateFinancial() && (bool) $user->concessionaire_id));
    }

    public function view(User $user, Remittance $remittance): bool
    {
        return $this->viewAny($user) && Remittance::visibleTo($user)->whereKey($remittance->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && ! $user->isHeadOffice();
    }

    public function review(User $user, Remittance $remittance): bool
    {
        return ($user->isAdmin() || $user->isHeadOffice()) && $this->view($user, $remittance);
    }
}
