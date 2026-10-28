<?php

namespace App\Notifications;

use App\Models\Shop;
use App\Support\Notifier;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Vendeur : sa demande de boutique a été validée ou refusée par l'admin. */
class ShopReviewed extends Notification
{
    public function __construct(public Shop $shop) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting('Bonjour '.$notifiable->name.',');

        if ($this->shop->statut === 'valide') {
            return $mail
                ->subject("Votre boutique « {$this->shop->nom} » est validée")
                ->line('Bonne nouvelle : votre boutique est validée. Vos produits peuvent maintenant être vendus sur MarketLocal.')
                ->action('Gérer ma boutique', Notifier::url('/ma-boutique'))
                ->salutation('L’équipe MarketLocal');
        }

        return $mail
            ->subject("Votre boutique « {$this->shop->nom} » n’a pas été validée")
            ->line('Votre demande de boutique n’a pas été validée pour la raison suivante :')
            ->line('« '.$this->shop->motif_refus.' »')
            ->line('Vous pouvez corriger les informations de votre boutique : la demande sera renvoyée automatiquement.')
            ->action('Modifier ma boutique', Notifier::url('/ma-boutique'))
            ->salutation('L’équipe MarketLocal');
    }
}
