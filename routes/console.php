<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Annule les commandes non payées expirées et restitue le stock (toutes les 15 min).
// En production : un vrai cron doit appeler `php artisan schedule:run` chaque minute.
// En local : `php artisan schedule:work` (à laisser tourner dans un terminal).
Schedule::command('orders:cancel-expired')->everyFifteenMinutes();
