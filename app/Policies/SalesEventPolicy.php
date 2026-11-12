<?php

namespace App\Policies;

use App\Models\SalesEvent;
use App\Models\User;

class SalesEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasActiveAccess() && ($user->canOperateFinancial() || $user->isHeadOffice());
    }

    public function view(User $user, SalesEvent $salesEvent): bool
    {
        return $this->viewAny($user) && SalesEvent::visibleTo($user)->whereKey($salesEvent->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->canManageSalesEvents();
    }

    public function update(User $user, SalesEvent $salesEvent): bool
    {
        return $user->canManageSalesEvents() && $this->view($user, $salesEvent);
    }

    public function delete(User $user, SalesEvent $salesEvent): bool
    {
        return $this->update($user, $salesEvent);
    }
}
