<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEarningCode extends Model
{
    use BelongsToOrganization;

    protected $table = 'payroll_earning_codes';

    public const DEFAULTS = [
        ['code' => 'BASIC',    'name' => 'Basic Salary',         'is_taxable' => true,  'is_pensionable' => true,  'is_shif_applicable' => true,  'is_nssf_applicable' => true],
        ['code' => 'HOUSE',    'name' => 'House Allowance',      'is_taxable' => true,  'is_pensionable' => false, 'is_shif_applicable' => true,  'is_nssf_applicable' => false],
        ['code' => 'TRANS',    'name' => 'Transport Allowance',  'is_taxable' => true,  'is_pensionable' => false, 'is_shif_applicable' => true,  'is_nssf_applicable' => false],
        ['code' => 'MEDICAL',  'name' => 'Medical Allowance',    'is_taxable' => true,  'is_pensionable' => false, 'is_shif_applicable' => true,  'is_nssf_applicable' => false],
        ['code' => 'OVERTIME', 'name' => 'Overtime',             'is_taxable' => true,  'is_pensionable' => false, 'is_shif_applicable' => true,  'is_nssf_applicable' => false],
        ['code' => 'BONUS',    'name' => 'Bonus',                'is_taxable' => true,  'is_pensionable' => false, 'is_shif_applicable' => true,  'is_nssf_applicable' => false],
        ['code' => 'PERDIEM',  'name' => 'Per Diem',             'is_taxable' => false, 'is_pensionable' => false, 'is_shif_applicable' => false, 'is_nssf_applicable' => false],
        ['code' => 'SITTING',  'name' => 'Sitting Allowance',    'is_taxable' => true,  'is_pensionable' => false, 'is_shif_applicable' => false, 'is_nssf_applicable' => false],
        ['code' => 'COMM',     'name' => 'Commission',           'is_taxable' => true,  'is_pensionable' => false, 'is_shif_applicable' => true,  'is_nssf_applicable' => false],
    ];

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'is_taxable',
        'is_pensionable',
        'is_shif_applicable',
        'is_nssf_applicable',
        'is_active',
        'effective_date',
    ];

    protected $casts = [
        'is_taxable'          => 'boolean',
        'is_pensionable'      => 'boolean',
        'is_shif_applicable'  => 'boolean',
        'is_nssf_applicable'  => 'boolean',
        'is_active'           => 'boolean',
        'effective_date'      => 'date',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
