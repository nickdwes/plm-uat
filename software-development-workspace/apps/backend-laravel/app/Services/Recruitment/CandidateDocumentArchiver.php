<?php

namespace App\Services\Recruitment;

use App\Models\EmployeeDocument;
use App\Models\Recruitment\Candidate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Preserves a hired candidate's CV as an employee document.
 *
 * Candidate documents expire after six months by lifecycle rule, which is
 * correct for applicants nobody hired and wrong for the one person whose CV
 * became part of a personnel file. Azure cannot tell the two apart — a
 * lifecycle rule sees blobs and dates, not outcomes — so the distinction has
 * to be made here, at the moment of hiring.
 *
 * Without this, a hired employee's CV disappears roughly six months after they
 * applied. Nothing fails, nobody is told, and it is noticed only when someone
 * goes looking for a document that used to be there.
 */
class CandidateDocumentArchiver
{
    private const CATEGORY = 'cv';

    /**
     * Copy the candidate's CV into the employee's documents.
     *
     * Returns the created record, or null when there was nothing to copy or a
     * copy already exists. Never throws: hiring someone must not fail because
     * their CV could not be copied, and the caller cannot sensibly recover.
     * Failures are logged loudly instead, because the whole point is that this
     * is the last moment the file is guaranteed to exist.
     */
    public function archiveForEmployee(
        int $organizationId,
        int $employeeId,
        ?int $candidateId = null,
        ?string $email = null,
        ?int $actorId = null,
    ): ?EmployeeDocument {
        try {
            $candidate = $this->resolveCandidate($organizationId, $candidateId, $email);

            if (! $candidate?->resume_path) {
                return null;
            }

            // Re-running a conversion must not stack up copies of the same CV.
            $existing = EmployeeDocument::where('employee_id', $employeeId)
                ->where('category', self::CATEGORY)
                ->exists();

            if ($existing) {
                return null;
            }

            $source = Storage::disk('candidate_docs');

            if (! $source->exists($candidate->resume_path)) {
                Log::warning('CV archive skipped: source file missing', [
                    'candidate_id' => $candidate->id,
                    'employee_id'  => $employeeId,
                    'path'         => $candidate->resume_path,
                ]);
                return null;
            }

            $extension = pathinfo($candidate->resume_path, PATHINFO_EXTENSION) ?: 'pdf';
            $path      = "{$organizationId}/{$employeeId}/" . self::CATEGORY . '/' . Str::uuid() . ".{$extension}";

            // Streamed rather than read whole: both disks may be remote, and
            // holding an arbitrary upload in memory to move it is needless.
            $stream = $source->readStream($candidate->resume_path);
            Storage::disk('employee_documents')->writeStream($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return EmployeeDocument::create([
                'employee_id'   => $employeeId,
                'uploaded_by'   => $actorId,
                'category'      => self::CATEGORY,
                'original_name' => Str::slug($candidate->full_name ?: 'candidate') . "-cv.{$extension}",
                'storage_path'  => $path,
                'mime_type'     => $source->mimeType($candidate->resume_path) ?: 'application/octet-stream',
                'size_bytes'    => $source->size($candidate->resume_path),
            ]);
        } catch (\Throwable $e) {
            Log::error('CV archive failed', [
                'organization_id' => $organizationId,
                'employee_id'     => $employeeId,
                'candidate_id'    => $candidateId,
                'error'           => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Find the candidate behind a hire.
     *
     * `candidate_id` is the intended link but is nullable and, in practice,
     * unset on every existing preboarding record — relying on it alone would
     * make this silently do nothing. Email is what actually connects the two,
     * and candidates are unique per organisation and email address.
     */
    private function resolveCandidate(int $organizationId, ?int $candidateId, ?string $email): ?Candidate
    {
        if ($candidateId) {
            $byId = Candidate::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->find($candidateId);

            if ($byId) {
                return $byId;
            }
        }

        if (! $email) {
            return null;
        }

        return Candidate::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
            ->whereNotNull('resume_path')
            ->latest('id')
            ->first();
    }
}
