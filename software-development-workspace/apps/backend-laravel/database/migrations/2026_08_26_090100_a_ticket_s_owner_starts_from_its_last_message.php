<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every ticket that already existed got `current_owner = 'support'` from the
 * column default, whatever it was actually waiting on. That is wrong for any
 * ticket where the last thing that happened was an agent's own reply — this
 * corrects it once, from the message that's already on record, rather than
 * leaving every pre-existing ticket looking like it's sitting with support.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('support_tickets')->orderBy('id')->chunkById(200, function ($tickets) {
            foreach ($tickets as $ticket) {
                $last = DB::table('support_ticket_messages')
                    ->where('support_ticket_id', $ticket->id)
                    ->where('visibility', 'public')
                    ->orderByDesc('created_at')
                    ->first();

                if (! $last) {
                    continue; // No message yet — the 'support' default already holds.
                }

                DB::table('support_tickets')->where('id', $ticket->id)->update([
                    'current_owner' => $last->is_from_agent ? 'client' : 'support',
                    'owner_set_at'  => $last->created_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        // A backfill from history has no meaningful reverse — leaving the
        // column defaults in place is not "undoing" it either way.
    }
};
