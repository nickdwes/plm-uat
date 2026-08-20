<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Account\Account;
use App\Models\Support\Team;
use App\Models\Support\Ticket;
use App\Services\Support\ReportWindow;
use App\Services\Support\ServiceReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The figures, and the list they were counted from.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ServiceReview $review) {}

    public function pack(Request $request): JsonResponse
    {
        $this->authorize('report', Ticket::class);

        $window = $this->window($request);

        return response()->json([
            'data'     => $this->review->pack($window),
            'accounts' => Account::orderBy('name')->get(['id', 'name']),
            'teams'    => Team::orderBy('name')->get(['id', 'name']),
            'account'  => $window->accountId
                ? Account::whereKey($window->accountId)->value('name')
                : null,
        ]);
    }

    /**
     * The tickets behind the figures.
     *
     * Built from the same population as the report rather than from a fresh
     * query, because the first thing anybody does with a service review is
     * check the total against the list — and a spreadsheet with 114 rows under
     * a slide that says 112 discredits both, whichever one is right.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('report', Ticket::class);

        $window = $this->window($request);

        $rows = Ticket::query()
            ->whereNull('merged_at')
            ->when($window->accountId, fn (Builder $q) => $q->where('account_id', $window->accountId))
            ->when($window->teamId, fn (Builder $q) => $q->where('support_team_id', $window->teamId))
            ->whereBetween('opened_at', [$window->from, $window->until])
            ->with(['account:id,name', 'status:id,name', 'priority:id,name', 'type:id,name',
                'serviceCategory:id,name', 'assignee:id,name', 'sla'])
            ->orderBy('opened_at')
            ->get();

        $name = 'service-review-' . $window->from->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($rows, $window) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Service review', $window->label, 'Times in working minutes']);
            fputcsv($out, []);
            fputcsv($out, [
                'Reference', 'Opened', 'Account', 'Subject', 'Type', 'Category',
                'Priority', 'Status', 'Assigned to',
                'Response target', 'Response taken', 'Response met',
                'Resolution target', 'Resolution taken', 'Resolution met',
                'Resolved',
            ]);

            foreach ($rows as $t) {
                $sla = $t->sla;

                fputcsv($out, [
                    $t->reference,
                    $t->opened_at?->setTimezone($window->timezone)->format('Y-m-d H:i'),
                    $t->account?->name,
                    $t->subject,
                    $t->type?->name,
                    $t->serviceCategory?->name ?? 'Not set',
                    $t->priority?->name,
                    $t->status?->name,
                    $t->assignee?->name ?? 'Unassigned',
                    $sla?->response_minutes,
                    $sla?->response_minutes_used,
                    /*
                     * Three states, not two. A blank cell for "no target yet"
                     * and a "No" for a missed one look identical once a
                     * spreadsheet is sorted, and one of them is an accusation.
                     */
                    $this->outcome($sla?->response_met, $sla !== null),
                    $sla?->resolution_minutes,
                    $sla?->resolution_minutes_used,
                    $this->outcome($sla?->resolution_met, $sla !== null),
                    $t->resolved_at?->setTimezone($window->timezone)->format('Y-m-d H:i'),
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    private function outcome(?bool $met, bool $hasSla): string
    {
        return match (true) {
            ! $hasSla    => 'No SLA',
            $met === true  => 'Met',
            $met === false => 'Missed',
            default        => 'Still running',
        };
    }

    private function window(Request $request): ReportWindow
    {
        $data = $request->validate([
            'from'       => ['nullable', 'date'],
            'to'         => ['nullable', 'date'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'team_id'    => ['nullable', 'integer', 'exists:support_teams,id'],
        ]);

        return ReportWindow::make(
            app('current.organization_id'),
            $data['from'] ?? null,
            $data['to'] ?? null,
            isset($data['account_id']) ? (int) $data['account_id'] : null,
            isset($data['team_id']) ? (int) $data['team_id'] : null,
        );
    }
}
