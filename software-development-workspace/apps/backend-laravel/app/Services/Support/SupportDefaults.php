<?php

namespace App\Services\Support;

use App\Models\Support\PriorityMatrix;
use App\Models\Support\TicketImpact;
use App\Models\Support\TicketPriority;
use App\Models\Support\TicketStatus;
use App\Models\Support\TicketType;
use App\Models\Support\TicketUrgency;
use Illuminate\Support\Facades\DB;

/**
 * A working service desk for an organisation that has never opened the setup.
 *
 * Created on first sight rather than by a seeder somebody has to remember to
 * run. The same reasoning as the payroll document settings: an organisation
 * added after this code shipped must not be the one whose first ticket fails
 * because no status exists to put it in.
 *
 * The vocabulary is the old PLM's, deliberately. Seventeen statuses and seven
 * types are more than a textbook would recommend, but they are the words the
 * team has used for two years and 1,792 tickets — and a migration that renames
 * everybody's working language on day one gets rejected on day two.
 */
class SupportDefaults
{
    /**
     * Statuses, with the state each one means.
     *
     * The state is the load-bearing column: `pending` is what stops the SLA
     * clock, and the difference between "With Supplier" and "In Progress" is
     * the difference between a target nobody could meet and one they missed.
     */
    private const STATUSES = [
        ['New',                            'open',     '#3b0270', true],
        ['Open',                           'open',     '#0c6fc4', false],
        ['In Progress',                    'open',     '#0f766e', false],
        ['Updated',                        'open',     '#0369a1', false],
        ['Re-Opened',                      'open',     '#b45309', false],
        ['Draft',                          'open',     '#64748b', false],
        ['Approved',                       'open',     '#15803d', false],

        // Waiting on somebody who is not us. The clock stops on all of these.
        ['With User',                      'pending',  '#846ac9', false],
        ['With Supplier',                  'pending',  '#be185d', false],
        ['Awaiting Approval',              'pending',  '#a16207', false],
        ['Purchase Order Approval',        'pending',  '#a16207', false],
        ['Supplier Delivery',              'pending',  '#9333ea', false],
        ['Local Site Action Required',     'pending',  '#c2410c', false],
        ['Infrastructure Change Required', 'pending',  '#7c2d12', false],

        ['Resolved',                       'resolved', '#15803d', false],
        // A declined request is finished, not fixed.
        ['Rejected',                       'closed',   '#b91c1c', false],
        ['Closed',                         'closed',   '#334155', false],
    ];

    private const TYPES = [
        ['Incident',        'Something is broken', true],
        ['Service Request', 'Somebody needs something', false],
        ['Problem',         'The cause behind repeated incidents', false],
        ['Change Request',  'A deliberate change to a service', false],
        ['Network Request', 'Connectivity, links and circuits', false],
        ['Request',         'General request', false],
        ['Negative Feedback', 'A complaint about service', false],
    ];

    private const URGENCIES = [['High', 1], ['Medium', 2, true], ['Low', 3]];
    private const IMPACTS   = [['High', 1], ['Medium', 2, true], ['Low', 3]];

    private const PRIORITIES = [
        ['Critical', 1, '#b91c1c'],
        ['High',     2, '#c2410c'],
        ['Medium',   3, '#a16207', true],
        ['Low',      4, '#0f766e'],
    ];

    /**
     * The matrix, read as [impact level][urgency level] => priority level.
     *
     * High impact and high urgency is critical; low and low is low. The
     * interesting cases are the corners — a printer nobody can use is urgent to
     * one person and low impact to everyone else, and lands on Medium rather
     * than jumping the queue.
     */
    private const MATRIX = [
        1 => [1 => 1, 2 => 2, 3 => 3],
        2 => [1 => 2, 2 => 3, 3 => 4],
        3 => [1 => 3, 2 => 4, 3 => 4],
    ];

    public function ensureFor(int $organizationId): void
    {
        if (TicketStatus::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)->exists()) {
            return;
        }

        DB::transaction(function () use ($organizationId) {
            foreach (self::STATUSES as $i => [$name, $state, $colour, $default]) {
                TicketStatus::create([
                    'organization_id' => $organizationId,
                    'name'   => $name,
                    'slug'   => \Str::slug($name),
                    'state'  => $state,
                    'colour' => $colour,
                    'is_default' => $default,
                    'position'   => $i,
                ]);
            }

            foreach (self::TYPES as $i => [$name, $description, $default]) {
                TicketType::create([
                    'organization_id' => $organizationId,
                    'name' => $name, 'slug' => \Str::slug($name),
                    'description' => $description,
                    'is_default'  => $default, 'position' => $i,
                ]);
            }

            foreach (self::URGENCIES as $row) {
                TicketUrgency::create([
                    'organization_id' => $organizationId,
                    'name' => $row[0], 'slug' => \Str::slug($row[0]),
                    'level' => $row[1], 'is_default' => $row[2] ?? false,
                ]);
            }

            foreach (self::IMPACTS as $row) {
                TicketImpact::create([
                    'organization_id' => $organizationId,
                    'name' => $row[0], 'slug' => \Str::slug($row[0]),
                    'level' => $row[1], 'is_default' => $row[2] ?? false,
                ]);
            }

            foreach (self::PRIORITIES as $row) {
                TicketPriority::create([
                    'organization_id' => $organizationId,
                    'name' => $row[0], 'slug' => \Str::slug($row[0]),
                    'level' => $row[1], 'colour' => $row[2],
                    'is_default' => $row[3] ?? false,
                ]);
            }

            $this->buildMatrix($organizationId);
        });
    }

    /** Every impact/urgency pair gets an answer, so triage can never fall through. */
    private function buildMatrix(int $organizationId): void
    {
        $impacts    = TicketImpact::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)->get()->keyBy('level');
        $urgencies  = TicketUrgency::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)->get()->keyBy('level');
        $priorities = TicketPriority::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)->get()->keyBy('level');

        foreach (self::MATRIX as $impactLevel => $byUrgency) {
            foreach ($byUrgency as $urgencyLevel => $priorityLevel) {
                if (! isset($impacts[$impactLevel], $urgencies[$urgencyLevel], $priorities[$priorityLevel])) {
                    continue;
                }

                PriorityMatrix::create([
                    'organization_id' => $organizationId,
                    'impact_id'       => $impacts[$impactLevel]->id,
                    'urgency_id'      => $urgencies[$urgencyLevel]->id,
                    'priority_id'     => $priorities[$priorityLevel]->id,
                ]);
            }
        }
    }
}
