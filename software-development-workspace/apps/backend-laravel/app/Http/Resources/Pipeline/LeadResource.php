<?php

namespace App\Http\Resources\Pipeline;

use App\Support\Audit\PiiMasker;
use App\Support\Authorization\Permission;
use App\Support\Pipeline\LeadQualification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Stakeholders are the same asset as account contacts — people at a
        // customer — so the same permission classifies them (see
        // AccountContactResource for the masking rationale).
        $showsContacts = $request->user()?->hasPermission(Permission::ACCOUNTS_VIEW_CONTACTS) ?? false;

        return [
            'contacts_visible' => $showsContacts,
            'id'        => $this->id,
            'reference' => $this->reference,
            'name'      => $this->name,
            'summary'   => $this->summary,
            'source'    => $this->source,
            'status'    => $this->status,
            'priority'  => $this->priority,
            'deadline'  => $this->deadline?->toDateString(),
            'website'   => $this->website,

            'problem_statement' => $this->problem_statement,
            'proposed_solution' => $this->proposed_solution,

            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id'   => $this->account->id,
                'name' => $this->account->name,
                'initials' => $this->account->initials,
            ] : null),

            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id, 'name' => $this->owner->full_name,
            ] : null),

            'team' => $this->whenLoaded('team', fn () => $this->team->map(fn ($m) => [
                'id'       => $m->id,
                'employee' => ['id' => $m->employee?->id, 'name' => $m->employee?->full_name],
                'role'     => $m->role,
            ])),

            'stakeholders' => $this->whenLoaded('stakeholders', fn () => $this->stakeholders->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'role' => $s->role,
                'email' => $showsContacts || $s->email === null ? $s->email : PiiMasker::maskEmail($s->email),
                'phone' => $showsContacts || $s->phone === null ? $s->phone : PiiMasker::maskTail($s->phone),
                'influence' => $s->influence,
            ])),

            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id, 'label' => $a->label, 'document_type' => $a->document_type,
                'original_name' => $a->original_name, 'size_bytes' => $a->size_bytes,
                'uploaded_at' => $a->created_at?->toIso8601String(),
            ])),

            /*
             * Five questions rather than one figure. A lead at 89 tells you
             * nothing to do; "budget under discussion, nobody identified"
             * tells you exactly which call to make next.
             *
             * The options ship with the lead rather than being hardcoded on
             * the client, because the wording of an answer is the thing being
             * agreed on — a screen that says "Indicated" where the server
             * means something else is worse than one that says nothing.
             */
            'qualification' => [
                'dimensions' => collect(LeadQualification::DIMENSIONS)
                    ->map(fn ($label, $key) => [
                        'key'      => $key,
                        'label'    => $label,
                        'question' => LeadQualification::QUESTIONS[$key],
                        'options'  => LeadQualification::options($key),
                        // The points, kept for the bar widths.
                        'value'    => $this->{$key},
                        // The answer that produced them, for the sentence.
                        'answer'   => LeadQualification::answerFor($key, $this->{$key}),
                    ])->values(),

                'score'        => $this->qualification_score,
                'band'         => $this->qualification_band,
                // 90 from one dimension and 90 from five are not the same
                // claim, and only one is worth acting on.
                'completeness' => LeadQualification::completeness($this->resource),
                'percentile'   => LeadQualification::percentile($this->resource),
                'unanswered'   => LeadQualification::unanswered($this->resource),
                'notes'        => $this->qualification_notes,
            ],

            /*
             * What it became. Present rather than encoded in the status, which
             * is the whole reason the legacy's status could not be counted on.
             */
            /*
             * Where it got to, not just that it got somewhere. The banner used
             * to repeat the deal's name, which on a converted lead is the lead's
             * own title — a sentence that told the reader nothing they were not
             * already looking at.
             *
             * No money here on purpose: the deal's value is behind a commercials
             * permission on its own screen, and a banner is not the place to
             * step around that.
             */
            'opportunity' => $this->whenLoaded('opportunity', fn () => $this->opportunity ? [
                'id'           => $this->opportunity->id,
                'reference'    => $this->opportunity->reference,
                'name'         => $this->opportunity->name,
                'status'       => $this->opportunity->status,
                'strength'     => $this->opportunity->score,
                'qualified_at' => $this->opportunity->qualified_at?->toIso8601String(),
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
