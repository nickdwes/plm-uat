<?php

namespace App\Models\Benefits;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitDependent extends Model
{
    use SoftDeletes;

    protected $table = 'benefit_dependents';

    protected $fillable = [
        'employee_id', 'full_name', 'relationship', 'date_of_birth', 'id_number', 'supporting_doc_path', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
