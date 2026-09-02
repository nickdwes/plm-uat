<?php

namespace App\Services\Project;

use App\Models\Project\Project;
use App\Models\Project\ProjectEmail;
use App\Models\Support\Mailbox;
use App\Services\Integration\CalendarConnectionResolver;
use App\Services\Support\GraphMailClient;
use Illuminate\Support\Carbon;

/**
 * Pulling a project's shared mailbox into its correspondence.
 *
 * Reuses the support desk's GraphMailClient against the org's Graph connection.
 * The mailbox here is transient — the project's own address on that connection —
 * so this needs no support_mailboxes row. De-duplicated on the Graph message id,
 * so a repeated pull only adds what is new.
 */
class ProjectMailService
{
    public function __construct(private readonly GraphMailClient $graph)
    {
    }

    /**
     * @return array{status: string, fetched: int, new: int}
     */
    public function sync(Project $project, int $limit = 25): array
    {
        if (! $project->project_email) {
            return ['status' => 'no_mailbox', 'fetched' => 0, 'new' => 0];
        }

        $connection = CalendarConnectionResolver::resolve($project->organization_id);

        // A transient mailbox — the project's address on the org's connection.
        $mailbox = new Mailbox([
            'organization_id'           => $project->organization_id,
            'integration_connection_id' => $connection->id,
            'address'                   => $project->project_email,
        ]);
        $mailbox->setRelation('connection', $connection);

        $messages = $this->graph->recentMessages($mailbox, $limit);

        $new = 0;
        foreach ($messages as $m) {
            if (empty($m['id'])) {
                continue;
            }

            $email = ProjectEmail::updateOrCreate(
                ['project_id' => $project->id, 'graph_id' => $m['id']],
                [
                    'organization_id'     => $project->organization_id,
                    'internet_message_id' => $m['internet_message_id'] ?? null,
                    'from_name'           => $m['from_name'] ?? null,
                    'from_email'          => $m['from_email'] ?? null,
                    'subject'             => $m['subject'] ?? null,
                    'body_preview'        => $m['body_preview'] ?? null,
                    'body_html'           => $m['body_html'] ?? null,
                    'to_recipients'       => $m['to'] ?? [],
                    'cc_recipients'       => $m['cc'] ?? [],
                    'has_attachments'     => $m['has_attachments'] ?? false,
                    'received_at'         => ! empty($m['received_at']) ? Carbon::parse($m['received_at']) : null,
                ],
            );

            if ($email->wasRecentlyCreated) {
                $new++;
            }
        }

        return ['status' => 'ok', 'fetched' => count($messages), 'new' => $new];
    }
}
