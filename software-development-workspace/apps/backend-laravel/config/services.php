<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'azure' => [

        /*
         * Step-up authentication for commercial data.
         *
         * `require_step_up_for_commercials` stays false until the Conditional
         * Access policy exists in Entra — switching it on without the policy
         * would refuse everybody with a challenge nothing can satisfy.
         */
        'commercial_auth_context'            => env('AZURE_COMMERCIAL_AUTH_CONTEXT', 'c1'),
        'require_step_up_for_commercials'    => env('AZURE_REQUIRE_STEP_UP_COMMERCIALS', false),

        'tenant_id'     => env('MICROSOFT_TENANT_ID', 'common'),
        'client_id'     => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect'      => env('AZURE_REDIRECT_URI'),
    ],

    /*
     * Read-only inventory sync — Microsoft 365 licences and Azure resources.
     *
     * Deliberately its own app registration rather than reusing the two above.
     * The mail principal can send as any mailbox; the storage principal holds
     * data-plane access to encrypted employee documents. Directory-read on
     * either of those would mean one leaked secret carries both a map of the
     * estate and the personnel files, which is a materially worse day than
     * either on its own.
     *
     * Nothing here may be granted write. If a future need appears to push
     * licence assignments back into Entra, that is a different principal with
     * a different secret — a mistake in this code should never be able to
     * de-licence a real person.
     */
    'ms_inventory' => [
        'tenant_id'       => env('MS_INVENTORY_TENANT_ID'),
        'client_id'       => env('MS_INVENTORY_CLIENT_ID'),
        'client_secret'   => env('MS_INVENTORY_CLIENT_SECRET'),
        'subscription_id' => env('MS_INVENTORY_SUBSCRIPTION_ID'),
    ],

];
