<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('appointments:send-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('dealers:reconcile-auto-tiers --apply')
    ->dailyAt('23:55')
    ->timezone('Asia/Ho_Chi_Minh')
    ->withoutOverlapping()
    ->onOneServer();
