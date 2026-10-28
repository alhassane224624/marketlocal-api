<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Notifier;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Vendeur : nouvelle commande à préparer (seulement les articles de sa boutique). */
class NewOrderForShop extends Notification
{
    public function __construct(public Order $order, public int $shopId) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $items = $this->order->loadMissing('items')->items->where('shop_id', $this->shopId);
        $mail = (new MailMessage)
            ->subject("Nouvelle commande n°{$this->order->id}")
            ->greeting('Bonjour '.$notifiable->name.',')
            ->line('Vous avez reçu une nouvelle commande à préparer :');

        foreach ($items as $item) {
            $mail->line("• {$item->product_nom} × {$item->quantite}");
        }

        return $mail
            ->line($this->order->isCashOnDelivery()
                ? 'Paiement à la livraison : le client réglera en espèces.'
                : 'La commande est déjà payée par carte.')
            ->action('Voir mes commandes', Notifier::url('/mes-commandes-vendeur'))
            ->salutation('L’équipe MarketLocal');
    }
}
