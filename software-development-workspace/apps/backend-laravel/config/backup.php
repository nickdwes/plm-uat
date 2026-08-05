<?php

return [

    /*
     * Where backup failure alerts are sent.
     *
     * Falls back to the mail "from" address so an alert always has somewhere
     * real to go — an unroutable alert is the same as no alert.
     */
    'alert_email' => env('BACKUP_ALERT_EMAIL', env('MAIL_FROM_ADDRESS')),

];
