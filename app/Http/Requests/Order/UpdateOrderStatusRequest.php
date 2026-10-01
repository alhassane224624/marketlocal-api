<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends ApiFormRequest
{
    /**
     * Admin, ou vendeur ayant au moins un article dans la commande.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('order');

        if (! $user || ! $order) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        $shopId = $user->shop?->id;

        return $shopId !== null
            && $order->items()->where('shop_id', $shopId)->exists();
    }

    public function rules(): array
    {
        // « payee » est réservé au webhook Stripe : personne ne peut le définir à la main.
        // Seul l'admin peut annuler.
        $autorises = $this->user()?->role === 'admin'
            ? ['expediee', 'livree', 'annulee']
            : ['expediee', 'livree'];

        return [
            'statut' => ['required', Rule::in($autorises)],
        ];
    }
}