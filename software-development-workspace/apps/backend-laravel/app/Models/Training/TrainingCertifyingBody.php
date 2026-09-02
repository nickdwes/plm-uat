<?php

namespace App\Models\Training;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A professional body that certifies people rather than selling a product —
 * ISACA, (ISC)², PMI. Global, not organisation-scoped: see the migration
 * that creates this table for why.
 */
class TrainingCertifyingBody extends Model
{
    protected $table = 'training_certifying_bodies';

    protected $fillable = ['uuid', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
