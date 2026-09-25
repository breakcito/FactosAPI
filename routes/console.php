<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('documents:retry-waiting')->everyMinute();
