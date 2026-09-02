<?php

namespace App\Services\Support;

use App\Models\Support\RcaEvidenceFile;
use App\Models\Support\Ticket;
use App\Models\Support\TicketRca;
use App\Models\User;
use App\Support\Storage\DocumentVault;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * One RCA per ticket — created on first save, not on ticket open.
 *
 * Most tickets never need one; seeding a blank row for every ticket would
 * make "does this have an RCA" a question you'd have to check field-by-field
 * instead of row-existence.
 */
class RcaService
{
    public function find(Ticket $ticket): ?TicketRca
    {
        return $ticket->rca;
    }

    public function save(Ticket $ticket, array $data, User $actor): TicketRca
    {
        $rca = $ticket->rca;

        if (! $rca) {
            $data['organization_id']   = $ticket->organization_id;
            $data['support_ticket_id'] = $ticket->id;
            $data['created_by']        = $actor->id;
            $rca = TicketRca::create($data);
        } else {
            $data['updated_by'] = $actor->id;
            $rca->update($data);
        }

        return $rca->fresh();
    }

    /**
     * The RCA a file attaches to, created bare if evidence arrives before the
     * first save — a screenshot dropped in while writing the summary
     * shouldn't be blocked on clicking Save first.
     */
    public function getOrCreate(Ticket $ticket, User $actor): TicketRca
    {
        return $ticket->rca ?? TicketRca::create([
            'organization_id'   => $ticket->organization_id,
            'support_ticket_id' => $ticket->id,
            'created_by'        => $actor->id,
        ]);
    }

    public function uploadEvidence(TicketRca $rca, UploadedFile $file, int $uploadedBy): RcaEvidenceFile
    {
        $ext  = $file->getClientOriginalExtension();
        $path = sprintf('rca/%d/%d/%s.%s', $rca->organization_id, $rca->id, Str::uuid(), $ext);

        // Encrypted like every other document class — an incident screenshot
        // routinely carries a customer's own data on screen.
        DocumentVault::putContents('rca_evidence', $path, $file->getContent());

        return RcaEvidenceFile::create([
            'ticket_rca_id'  => $rca->id,
            'original_name'  => $file->getClientOriginalName(),
            'storage_path'   => $path,
            'mime_type'      => $file->getMimeType(),
            'size_bytes'     => $file->getSize(),
            'uploaded_by'    => $uploadedBy,
        ]);
    }
}
