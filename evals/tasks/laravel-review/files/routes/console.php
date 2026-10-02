<?php

use App\Jobs\ArchiveOldOrders;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ArchiveOldOrders)->dailyAt('02:00');
