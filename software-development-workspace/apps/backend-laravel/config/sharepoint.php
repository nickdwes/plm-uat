<?php

return [
    'tenant_id'     => env('SHAREPOINT_TENANT_ID'),
    'client_id'     => env('SHAREPOINT_CLIENT_ID'),
    'client_secret' => env('SHAREPOINT_CLIENT_SECRET'),
    'site_url'      => env('SHAREPOINT_SITE_URL', 'https://knscoke.sharepoint.com/sites/compliance'),
    'library'       => env('SHAREPOINT_LIBRARY', 'Shared Documents'),
];
