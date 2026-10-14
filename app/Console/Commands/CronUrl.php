<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\CronController;
use Illuminate\Console\Command;

/** Affiche l'URL secrète à donner à cron-job.org (dépend de APP_KEY). */
class CronUrl extends Command
{
    protected $signature = 'cron:url {base? : URL de l\'API, ex. https://mon-api.wasmer.app}';

    protected $description = "Affiche l'URL qui déclenche les tâches planifiées";

    public function handle(): int
    {
        $base = rtrim($this->argument('base') ?: config('app.url'), '/');
        $this->line($base.'/api/cron/'.CronController::token());

        return self::SUCCESS;
    }
}
