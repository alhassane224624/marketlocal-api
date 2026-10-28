<?php

namespace App\Support;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Envoi d'e-mails sans risque pour l'action en cours.
 * Pas de worker de file d'attente en production (Wasmer) : l'envoi est synchrone.
 * - différé après la validation de la transaction (pas d'e-mail pour une action annulée) ;
 * - une panne SMTP est journalisée mais ne fait jamais échouer la requête.
 */
class Notifier
{
    public static function send($notifiable, Notification $notification): void
    {
        if (! $notifiable) {
            return;
        }

        DB::afterCommit(function () use ($notifiable, $notification) {
            try {
                $notifiable->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** Lien vers une page du frontend Next.js. */
    public static function url(string $path): string
    {
        return config('app.frontend_url').$path;
    }
}
