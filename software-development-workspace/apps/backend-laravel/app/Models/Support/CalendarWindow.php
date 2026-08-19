<?php

namespace App\Models\Support;

use Illuminate\Database\Eloquent\Model;

/** One day's opening hours. 0 is Sunday, matching Carbon. */
class CalendarWindow extends Model
{
    protected $table = 'support_calendar_windows';

    protected $fillable = ['support_calendar_id', 'weekday', 'opens_at', 'closes_at'];

    protected $casts = ['weekday' => 'integer'];
}
