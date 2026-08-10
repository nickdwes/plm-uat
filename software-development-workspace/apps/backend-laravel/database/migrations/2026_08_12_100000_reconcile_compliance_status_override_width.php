<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bring `status_override` to the width the repository says it has.
 *
 * Production ran a migration called
 * `2026_04_16_000001_enhance_org_compliance_documents` whose file is not in
 * this repository and never has been — it is in the ledger, so it happened, but
 * there is nothing left to read. Its only surviving trace in the schema is this
 * column: production has varchar(50), a database built from these migrations
 * gets varchar(60), because the create migration was later edited in place to
 * say 60.
 *
 * Widening is the safe direction whichever number was meant. Sixty is what the
 * committed migration says, and it is the only reviewable statement of intent
 * left; and going up cannot truncate a value that already fits, so no row can
 * be harmed by being wrong about which was intended.
 *
 * Written defensively because it has to be a no-op on a database that already
 * has 60 — which is every database built from these migrations from now on.
 *
 * Note for whoever runs this: production's ledger still carries a row for the
 * missing migration. `migrate` ignores it, but `migrate:rollback` past that
 * point will fail on a file that cannot be found. Left alone deliberately —
 * deleting the row would erase the only evidence that it ever ran.
 */
return new class extends Migration
{
    private const TABLE  = 'org_compliance_documents';
    private const COLUMN = 'status_override';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        // Raw rather than a doctrine/dbal change(): the column is a plain
        // varchar and this avoids adding a dependency for one width.
        DB::statement(
            'ALTER TABLE ' . self::TABLE . ' ALTER COLUMN ' . self::COLUMN . ' TYPE varchar(60)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        /*
         * Narrowing can fail on a value that has since been written at more
         * than fifty characters. That is correct: better a rollback that
         * refuses than one that silently truncates somebody's override.
         */
        DB::statement(
            'ALTER TABLE ' . self::TABLE . ' ALTER COLUMN ' . self::COLUMN . ' TYPE varchar(50)'
        );
    }
};
