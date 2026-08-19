<?php

namespace App\Models\Support;

use Illuminate\Database\Eloquent\Model;

/** A day the desk is shut regardless of the weekday. */
class CalendarHoliday extends Model
{
    protected $table = 'support_calendar_holidays';

    protected $fillable = ['support_calendar_id', 'observed_on', 'name'];

    protected $casts = ['observed_on' => 'date'];
}
