<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function view(User $user, Product $product): bool
    {
        return ! $user->isHeadOffice() && ! $user->isCustomer() && ($user->isAdmin() || $user->dealership_id === $product->dealership_id);
    }

    public function create(User $user): bool
    {
        return $user->canManageBusiness();
    }

    public function update(User $user, Product $product): bool
    {
        return $this->create($user) && $this->view($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->update($user, $product);
    }
}
