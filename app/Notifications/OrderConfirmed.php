<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Notifier;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Acheteur : commande confirmée (payée par carte, ou enregistrée en paiement à la livraison). */
class OrderConfirmed extends Notification
{
    public function __construct(public Order $order) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $order = $this->order->loadMissing('items');
        $mail = (new MailMessage)
            ->subject("Commande n°{$order->id} confirmée")
            ->greeting('Bonjour '.$notifiable->name.',')
            ->line($order->isCashOnDelivery()
                ? 'Votre commande est enregistrée. Vous la réglerez en espèces à la livraison.'
                : 'Votre paiement a bien été reçu : votre commande est confirmée.');

        foreach ($order->items as $item) {
            $mail->line("• {$item->product_nom} × {$item->quantite}");
        }

        return $mail
            ->line('Total : '.number_format((float) $order->total, 2, ',', ' ').' MAD (livraison comprise)')
            ->action('Suivre ma commande', Notifier::url("/commandes/{$order->id}"))
            ->salutation('L’équipe MarketLocal');
    }
}
