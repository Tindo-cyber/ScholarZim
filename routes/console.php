<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A development tool that lives with the tests: registered only where the test code is installed (it is not on
// a production install, which has no dev dependencies), so a missing class is never a fatal error.
if (class_exists(\Tests\Support\Console\ScholarFitCompareCommand::class)) {
    \Illuminate\Console\Application::starting(fn ($artisan) => $artisan->resolve(\Tests\Support\Console\ScholarFitCompareCommand::class));
}
