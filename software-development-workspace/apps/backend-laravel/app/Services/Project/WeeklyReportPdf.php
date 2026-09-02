<?php

namespace App\Services\Project;

use App\Models\Project\Project;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * The weekly status report, rendered to PDF bytes. Same mpdf setup as the RCA.
 *
 * @return array{content: string, filename: string}
 */
class WeeklyReportPdf
{
    public function __construct(private readonly WeeklyReportTemplate $template = new WeeklyReportTemplate())
    {
    }

    /** @return array{content: string, filename: string} */
    public function render(Project $project, array $report, array $history): array
    {
        $mpdf = new Mpdf([
            'tempDir'       => storage_path('app/mpdf'),
            'format'        => 'A4',
            'margin_top'    => 14,
            'margin_bottom' => 14,
            'margin_left'   => 14,
            'margin_right'  => 14,
        ]);

        $week = $report['week_start'] ?? '';
        $mpdf->SetTitle('Weekly Status — ' . $project->name . ' — ' . $week);
        $mpdf->WriteHTML($this->template->html($project, $report, $history));

        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $project->reference ?: $project->name);

        return [
            'content'  => $mpdf->Output('', Destination::STRING_RETURN),
            'filename' => 'Weekly-Report-' . trim((string) $slug, '-') . '-' . $week . '.pdf',
        ];
    }
}
