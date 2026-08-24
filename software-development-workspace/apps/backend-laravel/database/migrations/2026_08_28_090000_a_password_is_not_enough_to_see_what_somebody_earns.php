<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step-up verification: proving it is really you, right now, not just that
 * your session cookie is still valid.
 *
 * A stolen laptop with an open tab is the threat a permission check does not
 * cover — the signed-in session already has whatever access the role grants.
 * This is a second, short-lived proof asked for immediately before something
 * genuinely sensitive is shown, the same pattern the pipeline commercials
 * step-up was designed around (see docs/entra-stepup-and-sharepoint.md) —
 * except the challenge is a code emailed to the viewer's own address rather
 * than an Entra authenticator prompt, since there is no Conditional Access
 * policy to lean on here.
 *
 * One row is one attempt: a code gets requested, then either verified within
 * its window or it expires unused. `elevated_until`, once set, is what every
 * later request actually checks — the code itself is only ever compared once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_step_up_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * What this proof is for. A step-up granted for payroll should
             * not silently also unlock something else sensitive added later
             * without a decision that it should — so every gate names its own
             * purpose, and a grant only ever satisfies the purpose it was
             * requested for.
             */
            $table->string('purpose', 40);

            // Hashed like a password, never stored in the clear — six digits
            // is little enough entropy that the row itself must not be the
            // thing standing between a database leak and every payslip in it.
            $table->string('code_hash');

            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('verified_at')->nullable();

            /*
             * The elevation this code bought, once verified. Checked on every
             * later request behind the gate — the code is spent the moment it
             * is right, this is what actually stands afterwards.
             */
            $table->timestamp('elevated_until')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'purpose', 'elevated_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_step_up_codes');
    }
};
