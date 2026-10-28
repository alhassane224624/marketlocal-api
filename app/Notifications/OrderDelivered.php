<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Notifier;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Acheteur : un colis (une boutique) ou toute la commande a été livré. */
class OrderDelivered extends Notification
{
    /** @param int|null $shopId null : toute la commande. */
    public function __construct(public Order $order, public ?int $shopId = null) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $items = $this->order->loadMissing('items')->items
            ->when($this->shopId, fn ($c) => $c->where('shop_id', $this->shopId));

        $mail = (new MailMessage)
            ->subject("Commande n°{$this->order->id} : colis livré")
            ->greeting('Bonjour '.$notifiable->name.',')
            ->line('Votre colis a été livré :');

        foreach ($items as $item) {
            $mail->line("• {$item->product_nom} × {$item->quantite}");
        }

        return $mail
            ->line('Un problème avec votre colis ? Signalez-le depuis votre commande.')
            ->action('Voir ma commande', Notifier::url("/commandes/{$this->order->id}"))
            ->salutation('L’équipe MarketLocal');
    }
}
