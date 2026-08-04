<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Private employee documents — never publicly accessible.
        // Files live at storage/app/private/employee-documents/{org_id}/{employee_id}/
        // and are served only through the authenticated DocumentController.
        'employee_documents' => [
            'driver'     => 'local',
            'root'       => storage_path('app/private/employee-documents'),
            'visibility' => 'private',
            'throw'      => true,
        ],

        // Private preboarding attachments — served only through PreboardingController.
        /*
         * ── Azure Blob Storage ───────────────────────────────────────────────
         *
         * One disk per document class, each mapped to its own container. The
         * containers are separate rather than one container with prefixes so
         * that access and retention can differ: CVs should expire, employee
         * records have statutory minimums, and Azure scopes both RBAC and
         * lifecycle rules at the container.
         *
         * These sit alongside the local disks rather than replacing them. The
         * cutover is a one-line change per disk once uploads have been verified
         * against the real account, which keeps "does Azure work" and "is the
         * application broken" as separate questions.
         */
        'azure_candidate_docs' => [
            'driver'    => 'azure',
            'account'   => env('AZURE_STORAGE_ACCOUNT'),
            'container' => 'hrms-candidate-docs',
            'throw'     => true,
        ],

        'azure_employee_documents' => [
            'driver'    => 'azure',
            'account'   => env('AZURE_STORAGE_ACCOUNT'),
            'container' => 'hrms-employee-documents',
            'throw'     => true,
        ],

        'azure_preboarding_docs' => [
            'driver'    => 'azure',
            'account'   => env('AZURE_STORAGE_ACCOUNT'),
            'container' => 'hrms-preboarding-docs',
            'throw'     => true,
        ],

        'azure_training_evidence' => [
            'driver'    => 'azure',
            'account'   => env('AZURE_STORAGE_ACCOUNT'),
            'container' => 'hrms-training-evidence',
            'throw'     => true,
        ],

        /*
         * Candidate CVs and application attachments.
         *
         * These were on the `public` disk, which is exposed by the storage
         * symlink — every CV was downloadable by anyone with the URL and no
         * authentication at all. Random UUID paths made guessing impractical
         * but did nothing about URLs leaking through browser history, referrer
         * headers or proxy logs, and they never expired.
         *
         * Private, and served only through an authorised download route.
         */
        'candidate_docs' => [
            'driver'     => 'local',
            'root'       => storage_path('app/private/candidate-docs'),
            'visibility' => 'private',
            'throw'      => true,
        ],

        'preboarding_docs' => [
            'driver'     => 'local',
            'root'       => storage_path('app/private/preboarding-docs'),
            'visibility' => 'private',
            'throw'      => true,
        ],

        // Training evidence files — served only through authenticated TrainingRecordController.
        'training_evidence' => [
            'driver'     => 'local',
            'root'       => storage_path('app/private/training-evidence'),
            'visibility' => 'private',
            'throw'      => true,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
