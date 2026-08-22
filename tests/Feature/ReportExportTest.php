<?php

namespace Tests\Feature;

use App\Models\User;
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
        ];
    }

    public static function excelRoutes(): array
    {
        return [
            'users' => ['/admin/reports/users.xlsx', 'users-report.xlsx'],
            'opportunities' => ['/admin/reports/opportunities.xlsx', 'opportunities-report.xlsx'],
            'applications' => ['/admin/reports/applications.xlsx', 'applications-report.xlsx'],
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
