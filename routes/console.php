<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\ProcessExpiredHolds;

Schedule::job( new ProcessExpiredHolds() )->everyMinute()->withoutOverlapping();

