<?php

namespace App\Services\Project;

use App\Models\Project\Project;

/**
 * Whether a project may begin ordering. Ordering is gated on a per-project
 * policy so a framework/LPO award can proceed at once while a bespoke contract
 * waits to be signed — the same mechanism, a different gate.
 */
class OrderReadiness
{
    public const GATES = ['manual', 'on_award', 'on_contract', 'on_lpo'];

    /** Default gate suggested from a contract type — a starting point the PM can override. */
    private const GATE_FOR_TYPE = [
        'framework'    => 'on_award',
        'subscription' => 'on_award',
        'licence'      => 'on_award',
        'support'      => 'on_award',
        'service'      => 'on_contract',
        'sla'          => 'on_contract',
    ];

    /** The full readiness picture for the UI and the generator. */
    public function for(Project $project): array
    {
        $awarded       = $this->awarded($project);
        $contractReady = $this->contractReady($project);
        $lpoReceived   = $this->lpoReceived($project);
        $gate          = $project->order_release_gate ?: 'manual';

        $signalMet = match ($gate) {
            'on_award'    => $awarded,
            'on_contract' => $awarded && $contractReady,
            'on_lpo'      => $awarded && $lpoReceived,
            default       => true,   // manual — no gate
        };

        return [
            'gate'            => $gate,
            'suggested_gate'  => $this->suggestedGate($project),
            'awarded'         => $awarded,
            'contract_ready'  => $contractReady,
            'contract_auto'   => $this->contractActive($project),
            'lpo_received'    => $lpoReceived,
            'ready'           => $signalMet,
            'blocker'         => $signalMet ? null : $this->blocker($gate, $awarded, $contractReady, $lpoReceived),
        ];
    }

    /** Whether ordering may proceed right now. */
    public function ready(Project $project): bool
    {
        return $this->for($project)['ready'];
    }

    /** The deal has been won/awarded. */
    private function awarded(Project $project): bool
    {
        if ($project->relationLoaded('opportunity') || $project->opportunity_id) {
            if ($project->opportunity?->outcome === 'won') {
                return true;
            }
        }

        // A project with no won opportunity is treated as awarded once it has
        // moved past kickoff — the initiation gate is its award.
        return in_array($project->status, ['in_flight', 'on_hold', 'delivered'], true);
    }

    /** Contract readiness — the PM's override wins, else the linked contract. */
    private function contractReady(Project $project): bool
    {
        if ($project->contract_ready_override !== null) {
            return (bool) $project->contract_ready_override;
        }

        return $this->contractActive($project);
    }

    /** An active AccountContract on the project's opportunity. */
    private function contractActive(Project $project): bool
    {
        return (bool) $project->opportunity?->contracts()->where('status', 'active')->exists();
    }

    private function lpoReceived(Project $project): bool
    {
        return filled($project->customer_lpo_number);
    }

    private function suggestedGate(Project $project): ?string
    {
        $type = $project->opportunity?->contracts()->latest('id')->value('type');

        return $type ? (self::GATE_FOR_TYPE[$type] ?? null) : null;
    }

    private function blocker(string $gate, bool $awarded, bool $contractReady, bool $lpoReceived): string
    {
        if (! $awarded) {
            return 'Awaiting award — the deal is not yet won.';
        }

        return match ($gate) {
            'on_contract' => 'Awaiting a ready contract before ordering.',
            'on_lpo'      => 'Awaiting the customer LPO before ordering.',
            default       => 'Not ready to order.',
        };
    }
}
