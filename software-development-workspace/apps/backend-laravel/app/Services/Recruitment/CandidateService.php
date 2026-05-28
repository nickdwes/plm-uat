<?php

namespace App\Services\Recruitment;

use App\Models\Recruitment\Candidate;
use App\Repositories\Recruitment\CandidateRepository;
use App\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CandidateService extends BaseService
{
    public function __construct(CandidateRepository $repository)
    {
        parent::__construct($repository);
    }

    public function list(int $organizationId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Candidate::orderByDesc('created_at');

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                  ->orWhere('last_name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        if (!empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }

        return $query->with(['applications.vacancy', 'applications.currentStage'])->paginate($perPage);
    }

    public function create(int $organizationId, array $data): Candidate
    {
        $data['organization_id'] = $organizationId;
        return Candidate::create($data);
    }

    public function update(Candidate $candidate, array $data): Candidate
    {
        $candidate->update($data);
        return $candidate->fresh();
    }

    public function uploadResume(Candidate $candidate, UploadedFile $file): Candidate
    {
        if ($candidate->resume_path) {
            Storage::disk('public')->delete($candidate->resume_path);
        }

        $path = $file->store("resumes/{$candidate->id}", 'public');
        $candidate->update(['resume_path' => $path]);

        return $candidate->fresh();
    }
}
