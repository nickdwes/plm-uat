<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Role;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $organizations = [
            [
                'name'          => 'Konvergenz Network Solutions',
                'slug'          => 'konvergenz',
                'legal_name'    => 'Konvergenz Network Solutions Ltd',
                'industry'      => 'Technology & ICT',
                'country'       => 'KE',
                'currency'      => 'KES',
                'primary_email' => 'hr@konvergenz.co.ke',
                'is_active'     => true,
            ],
            [
                'name'          => 'Visibility Technology Solutions',
                'slug'          => 'visibility',
                'legal_name'    => 'Visibility Technology Solutions Ltd',
                'industry'      => 'Technology',
                'country'       => 'KE',
                'currency'      => 'KES',
                'primary_email' => 'hr@visibility.co.ke',
                'is_active'     => true,
            ],
            [
                'name'          => 'Tiberbu Healthnet',
                'slug'          => 'tiberbu',
                'legal_name'    => 'Tiberbu Healthnet Ltd',
                'industry'      => 'Healthcare',
                'country'       => 'KE',
                'currency'      => 'KES',
                'primary_email' => 'hr@tiberbu.co.ke',
                'is_active'     => true,
            ],
            [
                'name'          => 'Teedu Academy',
                'slug'          => 'teedu',
                'legal_name'    => 'Teedu Academy Ltd',
                'industry'      => 'Education',
                'country'       => 'KE',
                'currency'      => 'KES',
                'primary_email' => 'hr@teedu.co.ke',
                'is_active'     => true,
            ],
        ];

        foreach ($organizations as $data) {
            Organization::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
