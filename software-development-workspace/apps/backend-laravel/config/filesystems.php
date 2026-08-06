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

        /*
         * Selected by DOCUMENT_DISK: 'azure' in production, 'local' to
         * fall back without a code change. The originals are retained
         * under `employee_documents_local` until the Azure copies have proven
         * themselves in production, so a rollback is one variable.
         */
        'employee_documents' => env('DOCUMENT_DISK', 'local') === 'azure'
            ? [
                'driver'    => 'azure',
                'account'   => env('AZURE_STORAGE_ACCOUNT'),
                'container' => 'hrms-employee-documents',
                'throw'     => true,
            ]
            : [
                'driver'     => 'local',
                'root'       => storage_path('app/private/employee-documents'),
                'visibility' => 'private',
                'throw'      => true,
            ],

        'employee_documents_local' => [
            'driver'     => 'local',
            'root'       => storage_path('app/private/employee-documents'),
            'visibility' => 'private',
            'throw'      => true,
        ],

        /*
         * ── Azure Blob Storage ───────────────────────────────────────────────
         *
         * One disk per document class, each mapped to its own container. The
         * containers are separate rather than one container with prefixes so
         * that access and retention can differ: CVs should expire, employee
         * records have statutory minimums, and Azure scopes both RBAC and
         * lifecycle rules at the container.
         *
         * These are addressed directly only by the migration command, which
         * needs to name source and destination at the same time. Application
         * code uses the disks above, whose backing is chosen by DOCUMENT_DISK.
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
         * Travel receipts.
         *
         * Private, like every other document class. A receipt names a person,
         * a place and a date, and often carries a card number — none of which
         * belongs on a URL that needs no authentication.
         */
        'travel_evidence' => env('DOCUMENT_DISK', 'local') === 'azure'
            ? [
                'driver'    => 'azure',
                'account'   => env('AZURE_STORAGE_ACCOUNT'),
                'container' => 'hrms-employee-documents',
                'prefix'    => 'travel-evidence',
                'throw'     => true,
            ]
            : [
                'driver'     => 'local',
                'root'       => storage_path('app/private/travel-evidence'),
                'visibility' => 'private',
                'throw'      => true,
            ],

        /*
         * Customer paperwork — signed contracts, award letters, certificates.
         *
         * Private for the same reason as every other document class here: a
         * signed contract carries commercial terms and signatures, and a
         * predictable URL that needs no authentication is how the candidate
         * CVs got out.
         */
        'account_documents' => env('DOCUMENT_DISK', 'local') === 'azure'
            ? [
                'driver'    => 'azure',
                'account'   => env('AZURE_STORAGE_ACCOUNT'),
                'container' => 'hrms-employee-documents',
                'prefix'    => 'account-documents',
                'throw'     => true,
            ]
            : [
                'driver'     => 'local',
                'root'       => storage_path('app/private/account-documents'),
                'visibility' => 'private',
                'throw'      => true,
            ],

        /*
         * Database backups. Written by infra/azure/backup-database.sh, read
         * here only so `backup:check` can tell whether last night's dump
         * actually arrived.
         */
        'azure_db_backups' => [
            'driver'    => 'azure',
            'account'   => env('AZURE_STORAGE_ACCOUNT'),
            'container' => 'hrms-db-backups',
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
        /*
         * Selected by DOCUMENT_DISK: 'azure' in production, 'local' to
         * fall back without a code change. The originals are retained
         * under `candidate_docs_local` until the Azure copies have proven
         * themselves in production, so a rollback is one variable.
         */
        'candidate_docs' => env('DOCUMENT_DISK', 'local') === 'azure'
            ? [
                'driver'    => 'azure',
                'account'   => env('AZURE_STORAGE_ACCOUNT'),
                'container' => 'hrms-candidate-docs',
                'throw'     => true,
            ]
            : [
                'driver'     => 'local',
                'root'       => storage_path('app/private/candidate-docs'),
                'visibility' => 'private',
                'throw'      => true,
            ],

        'candidate_docs_local' => [
            'driver'     => 'local',
            'root'       => storage_path('app/private/candidate-docs'),
            'visibility' => 'private',
            'throw'      => true,
        ],

        /*
         * Selected by DOCUMENT_DISK: 'azure' in production, 'local' to
         * fall back without a code change. The originals are retained
         * under `preboarding_docs_local` until the Azure copies have proven
         * themselves in production, so a rollback is one variable.
         */
        'preboarding_docs' => env('DOCUMENT_DISK', 'local') === 'azure'
            ? [
                'driver'    => 'azure',
                'account'   => env('AZURE_STORAGE_ACCOUNT'),
                'container' => 'hrms-preboarding-docs',
                'throw'     => true,
            ]
            : [
                'driver'     => 'local',
                'root'       => storage_path('app/private/preboarding-docs'),
                'visibility' => 'private',
                'throw'      => true,
            ],

        'preboarding_docs_local' => [
            'driver'     => 'local',
            'root'       => storage_path('app/private/preboarding-docs'),
            'visibility' => 'private',
            'throw'      => true,
        ],

        /*
         * Selected by DOCUMENT_DISK: 'azure' in production, 'local' to
         * fall back without a code change. The originals are retained
         * under `training_evidence_local` until the Azure copies have proven
         * themselves in production, so a rollback is one variable.
         */
        'training_evidence' => env('DOCUMENT_DISK', 'local') === 'azure'
            ? [
                'driver'    => 'azure',
                'account'   => env('AZURE_STORAGE_ACCOUNT'),
                'container' => 'hrms-training-evidence',
                'throw'     => true,
            ]
            : [
                'driver'     => 'local',
                'root'       => storage_path('app/private/training-evidence'),
                'visibility' => 'private',
                'throw'      => true,
            ],

        'training_evidence_local' => [
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
