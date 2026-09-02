<?php

namespace App\Services\Project;

use App\Models\Project\Project;

/**
 * The weekly status report, laid out as a one-page document a sponsor reads:
 * where the project stands this week, how that moved from last week, what is
 * being worked toward, what is at risk, and the trend behind it.
 *
 * Built as an HTML string rather than a Blade view — the house pattern for a
 * single-layout printable, same as RcaTemplate and the payslip.
 */
class WeeklyReportTemplate
{
    public function html(Project $project, array $report, array $history): string
    {
        $e  = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $nl = fn ($v) => nl2br($e($v));

        $live   = $report['live'] ?? [];
        $deltas = $report['deltas'] ?? null;

        $rag   = strtolower($live['rag'] ?? 'green');
        $ragUp = strtoupper($rag);

        // Narrative: this week's published summary if present, else the most
        // recent published week's, so the document is never blank.
        $summary = null; $summaryWeek = null;
        foreach ($history as $h) {
            if (! empty($h['summary'])) { $summary = $h['summary']; $summaryWeek = $h['week_start']; break; }
        }

        $delta = '';
        if ($deltas) {
            $d = (int) ($deltas['progress_pct'] ?? 0);
            $arrow = $d > 0 ? '▲' : ($d < 0 ? '▼' : '—');
            $delta = $d === 0
                ? 'No change vs last week'
                : "{$arrow} " . abs($d) . ' pts vs last week';
            if (! empty($deltas['rag_changed'])) {
                $delta .= ' (RAG was ' . strtoupper($deltas['rag_was'] ?? '') . ')';
            }
        } else {
            $delta = 'First reported week';
        }

        // ── Signals ──────────────────────────────────────────────────────────
        $signal = fn (string $label, $n) => '<td class="sig"><span class="sig-n">' . (int) $n . '</span><span class="sig-l">' . $label . '</span></td>';
        $signals = '<table class="signals"><tr>'
            . $signal('Overdue tasks', $live['overdue_tasks'] ?? 0)
            . $signal('Open issues', $live['open_issues'] ?? 0)
            . $signal('Open dependencies', $live['open_dependencies'] ?? 0)
            . $signal('Objectives', count($live['okrs'] ?? []))
            . '</tr></table>';

        // ── Objectives ───────────────────────────────────────────────────────
        $okrRows = collect($live['okrs'] ?? [])->map(fn ($o) =>
            '<tr><td>' . ($o['done'] ?? false ? '✓' : '○') . '</td><td>' . $e($o['title'] ?? '') . '</td><td>' . $e(ucwords(str_replace('_', ' ', $o['status'] ?? ''))) . '</td></tr>'
        )->implode('');
        $okrTable = $okrRows
            ? '<table class="tbl"><thead><tr><th></th><th>Objective</th><th>Status</th></tr></thead><tbody>' . $okrRows . '</tbody></table>'
            : '<p class="muted">No objectives flagged for this week.</p>';

        // ── Risks ────────────────────────────────────────────────────────────
        $riskRows = collect($live['risks'] ?? [])->map(fn ($r) =>
            '<tr><td class="c">' . (int) ($r['score'] ?? 0) . '</td><td>' . $e($r['title'] ?? '') . '</td><td>' . $e(ucwords($r['status'] ?? '')) . '</td></tr>'
        )->implode('');
        $riskTable = $riskRows
            ? '<table class="tbl"><thead><tr><th>Heat</th><th>Risk</th><th>Status</th></tr></thead><tbody>' . $riskRows . '</tbody></table>'
            : '<p class="muted">No open risks on the register.</p>';

        // ── Trend (published history) ────────────────────────────────────────
        $trendRows = collect($history)->take(8)->map(function ($h) use ($e) {
            $r = strtolower($h['rag'] ?? 'green');
            return '<tr><td>' . $e($h['week_start'] ?? '') . '</td>'
                . '<td><span class="badge rag-' . $r . '">' . strtoupper($r) . '</span></td>'
                . '<td class="c">' . (int) ($h['progress_pct'] ?? 0) . '%</td>'
                . '<td class="c">' . (int) ($h['completed_tasks'] ?? 0) . '/' . (int) ($h['total_tasks'] ?? 0) . '</td>'
                . '<td>' . $e($h['published_by'] ?? '') . '</td></tr>';
        })->implode('');
        $trendTable = $trendRows
            ? '<table class="tbl"><thead><tr><th>Week</th><th>RAG</th><th>Progress</th><th>Done</th><th>Published by</th></tr></thead><tbody>' . $trendRows . '</tbody></table>'
            : '<p class="muted">Nothing published yet.</p>';

        $progress   = (int) ($live['progress_pct'] ?? 0);
        $completed  = (int) ($live['completed_tasks'] ?? 0);
        $total      = (int) ($live['total_tasks'] ?? 0);
        $weekStart  = $report['week_start'] ?? '';
        $client     = $project->relationLoaded('account') && $project->account ? $project->account->name : null;
        $stateLabel = ($report['published'] ?? false) ? 'PUBLISHED' : 'DRAFT';
        $stateClass = ($report['published'] ?? false) ? 'published' : 'draft';

        $css = <<<CSS
            body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1a1a1a; }
            h1 { font-size: 17pt; margin: 0 0 2pt; }
            h2 { font-size: 11.5pt; margin: 14pt 0 6pt; padding-bottom: 3pt; border-bottom: 1pt solid #d0d0d0; color: #1f3a5f; }
            .meta { font-size: 9pt; color: #555; margin-bottom: 12pt; }
            .badge { display: inline-block; padding: 2pt 8pt; border-radius: 10pt; font-size: 8.5pt; font-weight: bold; }
            .rag-green { background: #dcfce7; color: #166534; }
            .rag-amber { background: #fef9c3; color: #854d0e; }
            .rag-red   { background: #fee2e2; color: #991b1b; }
            .state.published { background: #dcfce7; color: #166534; }
            .state.draft { background: #f3f4f6; color: #555; }
            .headline { border: 1pt solid #d0d0d0; border-radius: 4pt; padding: 10pt 12pt; margin-bottom: 8pt; }
            .headline .pct { font-size: 26pt; font-weight: bold; }
            .headline .sub { font-size: 9.5pt; color: #555; }
            .headline .delta { font-size: 9.5pt; font-weight: bold; margin-top: 3pt; }
            .signals { width: 100%; border-collapse: collapse; margin-top: 6pt; }
            .signals .sig { border: 1pt solid #e2e8f0; text-align: center; padding: 8pt 4pt; width: 25%; }
            .signals .sig-n { display: block; font-size: 15pt; font-weight: bold; }
            .signals .sig-l { display: block; font-size: 8pt; color: #555; }
            .tbl { width: 100%; border-collapse: collapse; margin-top: 4pt; }
            .tbl th, .tbl td { border: 1pt solid #ccc; padding: 4pt 6pt; font-size: 9pt; text-align: left; }
            .tbl th { background: #f2f4f7; }
            .tbl td.c, .tbl th.c { text-align: center; }
            .muted { color: #888; font-style: italic; }
            .summary { background: #f8fafc; border: 1pt solid #e2e8f0; padding: 8pt 10pt; }
            CSS;

        $clientLine = $client ? ' &middot; ' . $e($client) : '';
        $summaryBlock = $summary
            ? '<div class="summary">' . $nl($summary) . ($summaryWeek ? '<div class="sub" style="margin-top:4pt;color:#888;font-size:8pt">Narrative from week of ' . $e($summaryWeek) . '</div>' : '') . '</div>'
            : '<p class="muted">No narrative published yet.</p>';

        return <<<HTML
            <html><head><meta charset="utf-8"><style>{$css}</style></head><body>
            <h1>Weekly Status Report</h1>
            <p class="meta">{$e($project->name)} &middot; {$e($project->reference)}{$clientLine} &middot; Week of {$e($weekStart)}
               &nbsp; <span class="badge rag-{$rag}">{$ragUp}</span>
               <span class="badge state {$stateClass}">{$stateLabel}</span></p>

            <div class="headline">
                <span class="pct">{$progress}%</span>
                <span class="sub">complete &mdash; {$completed} of {$total} tasks done</span>
                <div class="delta">{$delta}</div>
            </div>
            {$signals}

            <h2>Summary</h2>
            {$summaryBlock}

            <h2>This week's objectives</h2>
            {$okrTable}

            <h2>Key risks</h2>
            {$riskTable}

            <h2>Trend</h2>
            {$trendTable}
            </body></html>
            HTML;
    }
}
