<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paperwork belonging to a customer, or to one of their contracts.
 *
 * The contract drawer hangs documents off a contract (the signed contract, the
 * award letter). The legacy system hung them off the account and gave them an
 * expiry date — for certificates and registrations that lapse. Both are the
 * same act of filing a document against a customer, so they are one table with
 * a nullable contract, rather than two that will drift.
 *
 * expiry_date is carried over from the legacy even though its table was empty
 * there: it is the only field that makes a filed document actionable rather
 * than merely stored, and it costs one column.
 *
 * Private, like every other document class here. A signed contract carries
 * commercial terms and signatures, which do not belong on a URL that needs no
 * authentication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            // Null when the document belongs to the customer as a whole.
            $table->foreignId('account_contract_id')->nullable()
                ->constrained('account_contracts')->cascadeOnDelete();

            $table->string('document_type', 64)->default('other');
            $table->string('label')->nullable();

            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->date('expiry_date')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'account_contract_id']);
            // Drives "what lapses in the next 60 days" without a scan.
            $table->index(['organization_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_attachments');
    }
};
