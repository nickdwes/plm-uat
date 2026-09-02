<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The project charter — the governing facts a project is set up against.
 *
 * Scope, charter and PIP each keep a short on-screen summary here; the signed
 * source document attaches through the existing project_attachments store,
 * tagged by document_type. The two completion dates are deliberately separate:
 * what the charter targets and what the contract commits to are different
 * promises, and the gap between them is worth seeing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->text('scope')->nullable()->after('description');
            $table->text('charter')->nullable()->after('scope');   // charter summary
            $table->text('pip')->nullable()->after('charter');     // implementation plan summary
            $table->string('product')->nullable()->after('pip');   // the solution/product delivered

            // The window the work runs over, and the two completion promises.
            $table->date('execution_start')->nullable()->after('started_on');
            $table->date('execution_end')->nullable()->after('execution_start');
            $table->date('charter_completion_date')->nullable()->after('execution_end');
            $table->date('contract_completion_date')->nullable()->after('charter_completion_date');

            // Client-side people, from the account's own contacts.
            $table->foreignId('client_contact_id')->nullable()->after('account_id')
                  ->constrained('account_contacts')->nullOnDelete();
            $table->foreignId('client_sponsor_contact_id')->nullable()->after('client_contact_id')
                  ->constrained('account_contacts')->nullOnDelete();

            // The project's shared mailbox — read on the correspondence tab.
            $table->string('project_email')->nullable()->after('product');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_contact_id');
            $table->dropConstrainedForeignId('client_sponsor_contact_id');
            $table->dropColumn([
                'scope', 'charter', 'pip', 'product', 'project_email',
                'execution_start', 'execution_end',
                'charter_completion_date', 'contract_completion_date',
            ]);
        });
    }
};
