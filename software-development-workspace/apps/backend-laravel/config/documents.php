<?php

return [

    /*
     * The key documents are encrypted with, before they reach storage.
     *
     * Deliberately not APP_KEY. That one gets rotated to invalidate sessions
     * and signed URLs, and doing so must not make six years of contracts
     * unreadable. Losing this key loses the documents — it belongs in Key
     * Vault beside the database credentials.
     *
     * Generate with:  php artisan documents:key
     */
    'encryption_key' => env('DOCUMENT_ENCRYPTION_KEY', ''),

    /*
     * Which disks hold documents this application encrypts.
     *
     * Listed so `documents:encrypt-existing` knows what to convert, and so
     * adding a document class is a decision rather than an oversight.
     */
    'encrypted_disks' => [
        'opportunity_documents',
        'lead_documents',
        'account_documents',
        'candidate_docs',
        'travel_evidence',
        'project_documents',

        /*
         * The three that were missing, and the three that hold the most
         * sensitive documents in the system.
         *
         * Employment contracts, national ID and passport scans, KRA PIN
         * certificates, academic certificates, medical evidence for a training
         * claim. They were written straight to the disk and read straight back,
         * so the one class with a statutory duty of care behind it was the one
         * class stored as plain files — while tender responses, which are
         * merely commercially awkward, were encrypted.
         *
         * Nothing about the list said so. It was an omission, which is exactly
         * what the comment above hoped to prevent.
         */
        'employee_documents',
        'preboarding_docs',
        'training_evidence',
    ],
];
