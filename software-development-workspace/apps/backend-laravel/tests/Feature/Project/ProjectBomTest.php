<?php

namespace Tests\Feature\Project;

use App\Models\Organization;
use App\Models\Project\Project;
use App\Models\Project\ProjectBom;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The project Bill of Materials — priced lines, CSV import, and approval. */
class ProjectBomTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        app()->instance('current.organization_id', $this->org->id);
        $this->project = Project::create(['organization_id' => $this->org->id, 'name' => 'SD-WAN']);

        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'r' . fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'T', 'scope' => 'organization',
            'permissions' => [Permission::PROJECTS_VIEW, Permission::PROJECTS_UPDATE],
        ]);
        $user->roles()->attach($role->id, ['organization_id' => $this->org->id]);
        $user->forgetCachedRoles();
        Sanctum::actingAs($user);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}?org={$this->org->slug}";
    }

    public function test_lines_can_be_added_and_totals_are_derived(): void
    {
        $this->postJson($this->url('/bom/items'), [
            'category' => 'hardware', 'description' => 'Core switch', 'quantity' => 4,
            'unit_cost' => 1200, 'selling_price' => 1800,
        ])->assertCreated();

        $res = $this->getJson($this->url('/bom'))->assertOk();
        $this->assertSame('draft', $res->json('bom.status'));
        $this->assertCount(1, $res->json('items'));
        $row = $res->json('items.0');
        $this->assertSame(7200.0, (float) $row['order_value']);   // 1800 × 4
        $this->assertSame(2400.0, (float) $row['margin']);         // (1800−1200) × 4
        $this->assertSame(7200.0, (float) $res->json('summary.total_value'));
        $this->assertSame(2400.0, (float) $res->json('summary.total_margin'));
    }

    public function test_csv_import_maps_headers_loosely(): void
    {
        $csv = "Description,Category,Qty,Unit Cost,Selling Price,Part No\n"
             . "Firewall,hardware,2,1000,1500,FW-100\n"
             . "M365 Licence,software,10,50,80,LIC-M365\n"
             . "Mystery,unknowncat,1,5,9,\n";
        $file = UploadedFile::fake()->createWithContent('bom.csv', $csv);

        $res = $this->postJson($this->url('/bom/import'), ['file' => $file])->assertOk();

        $this->assertSame(3, $res->json('imported'));
        $items = collect($res->json('items'));
        $this->assertSame('FW-100', $items->firstWhere('description', 'Firewall')['part_number']);
        $this->assertSame('software', $items->firstWhere('description', 'M365 Licence')['category']);
        // Unknown category falls back to hardware, not dropped.
        $this->assertSame('hardware', $items->firstWhere('description', 'Mystery')['category']);
    }

    public function test_approving_freezes_edits_and_reopening_unfreezes(): void
    {
        $this->postJson($this->url('/bom/items'), ['category' => 'hardware', 'description' => 'Switch', 'quantity' => 1])->assertCreated();

        $this->postJson($this->url('/bom/approve'))->assertOk()
            ->assertJsonPath('bom.status', 'approved');

        // Editing a frozen BOM is refused.
        $this->postJson($this->url('/bom/items'), ['category' => 'software', 'description' => 'Licence'])->assertStatus(422);

        $this->postJson($this->url('/bom/reopen'))->assertOk()->assertJsonPath('bom.status', 'draft');
        $this->postJson($this->url('/bom/items'), ['category' => 'software', 'description' => 'Licence'])->assertCreated();
    }

    public function test_an_empty_bom_cannot_be_approved(): void
    {
        $this->postJson($this->url('/bom/approve'))->assertStatus(422);
        $this->assertSame(0, ProjectBom::where('project_id', $this->project->id)->where('status', 'approved')->count());
    }
}
