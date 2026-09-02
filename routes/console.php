<?php

use App\Jobs\ExpirePurchaseApprovalsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// AD-008: the scheduler is a required process, not an ops afterthought. Without it running,
// pending purchase approvals never auto-expire (NFR-009) — the job's own conditional-update guard
// makes a late run safe, just delayed.
Schedule::job(new ExpirePurchaseApprovalsJob)->everyFifteenMinutes();
