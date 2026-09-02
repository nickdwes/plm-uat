<?php

namespace App\Services\Support;

use App\Models\Support\Ticket;
use App\Models\Support\TicketRca;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * The RCA, rendered to bytes.
 */
class RcaPdf
{
    public function __construct(private readonly RcaTemplate $template = new RcaTemplate()) {}

    /** @return array{content: string, filename: string} */
    public function render(TicketRca $rca, Ticket $ticket): array
    {
        $mpdf = new Mpdf([
            'tempDir'       => storage_path('app/mpdf'),
            'format'        => 'A4',
            'margin_top'    => 14,
            'margin_bottom' => 14,
            'margin_left'   => 14,
            'margin_right'  => 14,
        ]);

        $mpdf->SetTitle('RCA — ' . $ticket->reference);
        $mpdf->WriteHTML($this->template->html($rca, $ticket));

        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $ticket->reference);

        return [
            'content'  => $mpdf->Output('', Destination::STRING_RETURN),
            'filename' => 'RCA-' . trim((string) $slug, '-') . '.pdf',
        ];
    }
}
