<?php

namespace App\Services\Support;

use App\Models\Support\Ticket;
use App\Models\Support\TicketRca;

/**
 * The RCA document itself, laid out to the sixteen-section structure a
 * reviewer expects: what happened, why, the impact, and what stops it
 * happening again. Built as a string rather than a Blade view — see
 * PayslipTemplate for why that's the house pattern for a one-layout document.
 */
class RcaTemplate
{
    public function html(TicketRca $rca, Ticket $ticket): string
    {
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $nl = fn ($v) => nl2br($e($v));

        $incidentDescription = $rca->incident_description ?? [];
        $businessImpact      = $rca->business_impact ?? [];
        $riskAssessment      = $rca->risk_assessment ?? [];
        $reviewers           = $rca->reviewers ?? [];
        $timeline            = $rca->timeline ?? [];
        $fiveWhys            = $rca->five_whys ?? [];
        $corrective          = $rca->corrective_actions ?? [];
        $preventive          = $rca->preventive_actions ?? [];
        $evidenceFiles       = $rca->relationLoaded('evidenceFiles') ? $rca->evidenceFiles : collect();

        $section = function (string $title, string $body) {
            return "<div class=\"section\"><h2>{$title}</h2>{$body}</div>";
        };

        $field = fn (string $label, $value) => $value
            ? "<p class=\"field\"><span class=\"label\">{$label}</span>{$value}</p>" : '';

        $timelineRows = collect($timeline)->map(fn ($row) => '<tr><td>' . $e($row['time'] ?? '') . '</td><td>' . $e($row['event'] ?? '') . '</td></tr>')->implode('');
        $timelineTable = $timeline ? "<table class=\"tbl\"><thead><tr><th>Time</th><th>Event</th></tr></thead><tbody>{$timelineRows}</tbody></table>" : '<p class="muted">No timeline recorded.</p>';

        $whysRows = collect($fiveWhys)->map(function ($w, $i) use ($e) {
            $n = $i + 1;
            return "<p class=\"field\"><span class=\"label\">Why {$n}?</span>" . $e($w['question'] ?? '') . '<br><em>' . $e($w['answer'] ?? '') . '</em></p>';
        })->implode('');

        $actionTable = function (array $rows) use ($e) {
            if (! $rows) return '<p class="muted">None recorded.</p>';
            $body = collect($rows)->map(fn ($r) => '<tr>'
                . '<td>' . $e($r['action'] ?? '') . '</td>'
                . '<td>' . $e($r['owner'] ?? '') . '</td>'
                . '<td>' . $e($r['priority'] ?? '') . '</td>'
                . '<td>' . $e($r['due_date'] ?? '') . '</td>'
                . '<td>' . $e($r['status'] ?? '') . '</td>'
                . '</tr>')->implode('');
            return "<table class=\"tbl\"><thead><tr><th>Action</th><th>Owner</th><th>Priority</th><th>Due</th><th>Status</th></tr></thead><tbody>{$body}</tbody></table>";
        };

        // Section 16 is the two action lists consolidated, tagged by type — not stored again.
        $consolidated = collect($corrective)->map(fn ($r) => $r + ['type' => 'Corrective'])
            ->concat(collect($preventive)->map(fn ($r) => $r + ['type' => 'Preventive']));
        $finalPlanBody = $consolidated->map(fn ($r, $i) => '<tr>'
            . '<td>' . ($i + 1) . '</td>'
            . '<td>' . $e($r['action'] ?? '') . '</td>'
            . '<td>' . $e($r['type'] ?? '') . '</td>'
            . '<td>' . $e($r['owner'] ?? '') . '</td>'
            . '<td>' . $e($r['priority'] ?? '') . '</td>'
            . '<td>' . $e($r['due_date'] ?? '') . '</td>'
            . '<td>' . $e($r['status'] ?? '') . '</td>'
            . '</tr>')->implode('');
        $finalPlanTable = $consolidated->isEmpty() ? '<p class="muted">No actions recorded.</p>'
            : '<table class="tbl"><thead><tr><th>#</th><th>Action</th><th>Type</th><th>Owner</th><th>Priority</th><th>Due</th><th>Status</th></tr></thead><tbody>' . $finalPlanBody . '</tbody></table>';

        $css = <<<CSS
            body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1a1a1a; }
            h1 { font-size: 16pt; margin: 0 0 4pt; }
            h2 { font-size: 11.5pt; margin: 14pt 0 6pt; padding-bottom: 3pt; border-bottom: 1pt solid #d0d0d0; color: #1f3a5f; }
            .meta { font-size: 9pt; color: #555; margin-bottom: 10pt; }
            .badge { display: inline-block; padding: 2pt 8pt; border-radius: 10pt; font-size: 8.5pt; font-weight: bold; }
            .badge.draft { background: #fef3c7; color: #92400e; }
            .badge.final { background: #dcfce7; color: #166534; }
            .section { margin-bottom: 4pt; }
            .field { margin: 3pt 0; }
            .field .label { display: block; font-size: 8.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: .3pt; color: #555; }
            .grid { width: 100%; }
            .grid td { vertical-align: top; padding: 2pt 10pt 2pt 0; width: 50%; }
            .tbl { width: 100%; border-collapse: collapse; margin-top: 4pt; }
            .tbl th, .tbl td { border: 1pt solid #ccc; padding: 4pt 6pt; font-size: 9pt; text-align: left; }
            .tbl th { background: #f2f4f7; }
            .muted { color: #888; font-style: italic; }
            .filelist { margin: 0 0 6pt; padding-left: 16pt; font-size: 9pt; }
            .questions { background: #f8fafc; border: 1pt solid #e2e8f0; padding: 8pt 10pt; margin-top: 10pt; }
            CSS;

        $docInfo = '<table class="grid"><tr>'
            . '<td>' . $field('Incident ID', $e($ticket->reference)) . $field('Date of incident', $rca->incident_date?->toDateString()) . $field('System / service affected', $e($rca->system_affected)) . $field('Incident owner', $e($rca->incident_owner)) . '</td>'
            . '<td>' . $field('Date RCA prepared', $rca->prepared_date?->toDateString()) . $field('Business unit', $e($rca->business_unit)) . $field('Incident severity', $e($rca->severity)) . $field('Technical owner', $e($rca->technical_owner)) . '</td>'
            . '</tr></table>'
            . $field('Reviewers', $e(implode(', ', $reviewers)));

        $body = $docInfo
            . $section('2. Executive Summary', $nl($rca->executive_summary) ?: '<p class="muted">Not written yet.</p>')
            . $section('3. Incident Description',
                $field('Symptoms observed', $nl($incidentDescription['symptoms'] ?? null))
                . $field('Systems affected', $nl($incidentDescription['systems_affected'] ?? null))
                . $field('Users affected', $nl($incidentDescription['users_affected'] ?? null))
                . $field('Error messages', $nl($incidentDescription['error_messages'] ?? null))
                . $field('How it was detected', $nl($incidentDescription['detection_method'] ?? null))
                . $field('Initial observations', $nl($incidentDescription['initial_observations'] ?? null)))
            . $section('4. Business Impact',
                $field('Users affected', $e($businessImpact['users_affected_count'] ?? null))
                . $field('Transactions affected', $e($businessImpact['transactions_affected'] ?? null))
                . $field('Revenue impact', $e($businessImpact['revenue_impact'] ?? null))
                . $field('SLA impact', $nl($businessImpact['sla_impact'] ?? null))
                . $field('Operational impact', $nl($businessImpact['operational_impact'] ?? null))
                . $field('Customer impact', $nl($businessImpact['customer_impact'] ?? null))
                . $field('Compliance / security impact', $nl($businessImpact['compliance_security_impact'] ?? null))
                . $field('Data loss', $nl($businessImpact['data_loss'] ?? null)))
            . $section('5. Incident Timeline', $timelineTable)
            . $section('6. Technical Investigation', $nl($rca->technical_investigation) ?: '<p class="muted">Not written yet.</p>')
            . $section('7. Root Cause',
                $field('Immediate cause', $nl($rca->immediate_cause))
                . $field('Root cause', $nl($rca->root_cause))
                . $field('Contributing factors', $nl($rca->contributing_factors))
                . $field('Control failure — why existing controls did not prevent or detect this', $nl($rca->control_failure)))
            . $section('8. Five Whys Analysis', $whysRows . $field('Final root cause', '<strong>' . $nl($rca->final_root_cause) . '</strong>'))
            . $section('9. Root Cause Classification', $e($rca->root_cause_category) ?: '<p class="muted">Not classified.</p>')
            . $section('10. Resolution', $nl($rca->resolution) ?: '<p class="muted">Not written yet.</p>')
            . $section('11. Corrective Actions', $actionTable($corrective))
            . $section('12. Preventive Actions', $actionTable($preventive))
            . $section('13. Lessons Learned', $nl($rca->lessons_learned) ?: '<p class="muted">Not written yet.</p>')
            . $section('14. Evidence',
                ($evidenceFiles->isNotEmpty()
                    ? '<ul class="filelist">' . $evidenceFiles->map(fn ($f) => '<li>' . $e($f->original_name) . '</li>')->implode('') . '</ul>'
                    : '')
                . ($nl($rca->evidence) ?: ($evidenceFiles->isEmpty() ? '<p class="muted">None attached.</p>' : '')))
            . $section('15. Risk Assessment',
                $field('Similar systems that could be affected', $nl($riskAssessment['similar_systems'] ?? null))
                . $field('Probability of recurrence', $e($riskAssessment['probability'] ?? null))
                . $field('Potential impact', $nl($riskAssessment['impact'] ?? null))
                . $field('Existing controls', $nl($riskAssessment['existing_controls'] ?? null))
                . $field('Control gaps', $nl($riskAssessment['control_gaps'] ?? null))
                . $field('Recommended risk treatment', $nl($riskAssessment['recommended_treatment'] ?? null)))
            . $section('16. Final Action Plan', $finalPlanTable)
            . '<div class="questions"><strong>A strong RCA answers:</strong> What happened? What was the impact? Why did it happen? '
            . 'Why did existing controls fail to prevent or detect it? What will prevent recurrence?</div>';

        $statusClass = $rca->status === TicketRca::STATUS_FINAL ? 'final' : 'draft';
        $statusLabel = $rca->status === TicketRca::STATUS_FINAL ? 'FINAL' : 'DRAFT';

        return <<<HTML
            <html><head><meta charset="utf-8"><style>{$css}</style></head><body>
            <h1>{$e($rca->title ?: $ticket->subject)}</h1>
            <p class="meta">Ticket {$e($ticket->reference)} &middot; <span class="badge {$statusClass}">{$statusLabel}</span></p>
            {$body}
            </body></html>
            HTML;
    }
}
