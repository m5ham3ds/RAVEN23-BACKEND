<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::command('notifications:outbox-dispatch')
    ->everyMinute()
    ->withoutOverlapping(5);

if ((bool) config('filesystems.private_media_orphan_cleanup_enabled', false)) {
    Schedule::command('media:cleanup-orphans --apply')
        ->dailyAt('03:30')
        ->withoutOverlapping();
}
