<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditAction;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The admin PDF and Excel exports carried over from the Spring report services. */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    public static function pdfRoutes(): array
    {
        return [
            'users' => ['/admin/reports/users.pdf', 'users-report.pdf'],
            'opportunities' => ['/admin/reports/opportunities.pdf', 'opportunities-report.pdf'],
            'applications' => ['/admin/reports/applications.pdf', 'applications-report.pdf'],
            'recommendations' => ['/admin/reports/recommendations.pdf', 'recommendations-report.pdf'],
            'system activities' => ['/admin/reports/activities.pdf', 'system-activities-report.pdf'],
        ];
    }

    public static function excelRoutes(): array
    {
        return [
            'users' => ['/admin/reports/users.xlsx', 'users-report.xlsx'],
            'opportunities' => ['/admin/reports/opportunities.xlsx', 'opportunities-report.xlsx'],
            'applications' => ['/admin/reports/applications.xlsx', 'applications-report.xlsx'],
            'system activities' => ['/admin/reports/activities.xlsx', 'system-activities-report.xlsx'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pdfRoutes')]
    public function test_pdf_reports_download(string $url, string $filename): void
    {
        $response = $this->actingAs($this->admin)->get($url);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('content-disposition', 'attachment; filename="' . $filename . '"');

        // A real PDF, not an error page rendered with the wrong content type.
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('excelRoutes')]
    public function test_excel_reports_download(string $url, string $filename): void
    {
        $response = $this->actingAs($this->admin)->get($url);

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename="' . $filename . '"');

        // XLSX is a zip container; "PK" is its magic number.
        $this->assertStringStartsWith('PK', $response->getContent());
    }

    public function test_reports_hub_renders_for_admins(): void
    {
        $this->actingAs($this->admin)->get('/admin/reports')
            ->assertOk()
            ->assertSee('Export reports')
            ->assertSee('/admin/reports/users.xlsx');
    }

    public function test_the_hub_lists_the_system_activities_exports_and_keeps_the_audit_screen(): void
    {
        $this->actingAs($this->admin)->get('/admin/reports')
            ->assertOk()
            ->assertSee('System activities')
            ->assertSee('/admin/reports/activities.pdf')
            ->assertSee('/admin/reports/activities.xlsx')
            ->assertSee(route('admin.audit'), false);

        $this->actingAs($this->admin)->get(route('admin.audit'))->assertOk();
    }

    /** @return array<int, array<int, mixed>> the sheet's rows, header first */
    private function sheetRows(string $xlsx): array
    {
        $path = tempnam(sys_get_temp_dir(), 'act') . '.xlsx';
        file_put_contents($path, $xlsx);

        try {
            return \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        } finally {
            @unlink($path);
        }
    }

    public function test_the_activities_excel_lists_real_audit_entries_newest_first_with_their_detail(): void
    {
        AuditLog::query()->delete();
        AuditLog::create([
            'actor_email' => 'older@example.test', 'actor_user_id' => 7, 'action' => AuditAction::APPLY, 'entity_type' => 'APPLICATION',
            'entity_id' => 11, 'details' => 'Applied to "Older Award"', 'created_at' => now()->subDays(3), 'request_id' => 'req-old',
        ]);
        AuditLog::create([
            'actor_email' => 'newer@example.test', 'actor_user_id' => 8, 'action' => AuditAction::STATUS_UPDATE, 'entity_type' => 'APPLICATION',
            'entity_id' => 12, 'details' => 'Accepted', 'reason' => 'Strong application', 'old_values' => ['status' => 'PENDING'],
            'new_values' => ['status' => 'ACCEPTED'], 'created_at' => now()->subDay(), 'request_id' => 'req-new', 'ip_address' => '10.0.0.5',
        ]);

        $rows = $this->sheetRows($this->actingAs($this->admin)->get('/admin/reports/activities.xlsx')->assertOk()->getContent());

        $this->assertSame(['Date and time', 'Actor', 'Actor ID', 'Action', 'Record type', 'Record ID', 'Details', 'Reason', 'Old values', 'New values', 'IP address', 'Request ID'], $rows[0]);
        $this->assertCount(3, $rows, 'header plus the two entries, and nothing made up');
        $this->assertSame('newer@example.test', $rows[1][1], 'newest first');
        $this->assertSame('older@example.test', $rows[2][1]);
        $this->assertSame('Strong application', $rows[1][7]);
        $this->assertSame('APPLICATION', $rows[1][4]);
        $this->assertSame('12', (string) $rows[1][5]);
        $this->assertStringContainsString('PENDING', $rows[1][8]);
        $this->assertStringContainsString('ACCEPTED', $rows[1][9]);
        $this->assertSame('req-new', $rows[1][11]);
    }

    public function test_the_activities_pdf_view_shows_the_recorded_activity(): void
    {
        AuditLog::query()->delete();
        AuditLog::create([
            'actor_email' => 'provider@example.test', 'action' => AuditAction::STATUS_UPDATE, 'entity_type' => 'APPLICATION',
            'entity_id' => 12, 'details' => 'Accepted the application', 'reason' => 'Strong application', 'created_at' => now(),
        ]);

        $html = view('reports.activities', [
            'title' => 'System Activities Report', 'truncated' => false, 'limit' => 1000,
            'entries' => AuditLog::query()->orderByDesc('created_at')->get(),
        ])->render();

        $this->assertStringContainsString('provider@example.test', $html);
        $this->assertStringContainsString('APPLICATION #12', $html);
        $this->assertStringContainsString('Accepted the application', $html);
        $this->assertStringContainsString('Strong application', $html);
    }

    public function test_the_activity_exports_do_not_change_the_audit_history(): void
    {
        $before = AuditLog::count();

        $this->actingAs($this->admin)->get('/admin/reports/activities.pdf')->assertOk();
        $this->actingAs($this->admin)->get('/admin/reports/activities.xlsx')->assertOk();

        $this->assertSame($before, AuditLog::count());
    }

    public function test_activity_exports_are_closed_to_other_roles_and_guests(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        foreach (['/admin/reports/activities.pdf', '/admin/reports/activities.xlsx'] as $url) {
            // Turned away - forbidden, or sent elsewhere by the role check - and never handed the file.
            foreach ([$student, $provider] as $user) {
                $this->app['auth']->forgetGuards();
                $response = $this->actingAs($user)->get($url);

                $this->assertContains($response->getStatusCode(), [302, 403], $user->email . ' ' . $url);
                $this->assertNull($response->headers->get('content-disposition'), 'no download was offered');
            }
        }

        $this->app['auth']->forgetGuards();
        $this->get('/admin/reports/activities.pdf')->assertRedirect('/login');
        $this->get('/admin/reports/activities.xlsx')->assertRedirect('/login');
    }

    public function test_reports_are_closed_to_other_roles(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($student)->get('/admin/reports')->assertForbidden();
        $this->actingAs($student)->get('/admin/reports/users.pdf')->assertForbidden();
    }

    public function test_reports_are_closed_to_guests(): void
    {
        $this->get('/admin/reports/users.xlsx')->assertRedirect('/login');
    }
}
