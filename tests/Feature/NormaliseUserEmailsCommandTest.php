<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NormaliseUserEmailsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function emails(): array
    {
        return DB::table('users')->orderBy('user_id')->pluck('email')->all();
    }

    public function test_it_reports_without_changing_anything_by_default(): void
    {
        $this->seed(DatabaseSeeder::class);
        $id = DB::table('users')->min('user_id');
        DB::table('users')->where('user_id', $id)->update(['email' => 'Mixed.Case@Example.com']);
        $before = $this->emails();

        $this->artisan('scholarzim:normalise-emails')->expectsOutputToContain('1 address(es) with capital letters')->assertExitCode(0);

        $this->assertSame($before, $this->emails());
    }

    public function test_apply_lowercases_but_leaves_case_clashes_for_a_manual_merge(): void
    {
        $this->seed(DatabaseSeeder::class);
        $ids = DB::table('users')->orderBy('user_id')->limit(3)->pluck('user_id')->all();
        DB::table('users')->where('user_id', $ids[0])->update(['email' => 'Mixed.Case@Example.com']);
        DB::table('users')->where('user_id', $ids[1])->update(['email' => 'Twin@Example.com']);
        DB::table('users')->where('user_id', $ids[2])->update(['email' => 'twin@example.com']);

        $this->artisan('scholarzim:normalise-emails', ['--apply' => true])->expectsOutputToContain('needs a manual merge: twin@example.com')->assertExitCode(0);

        $this->assertSame('mixed.case@example.com', DB::table('users')->where('user_id', $ids[0])->value('email'));
        $this->assertSame('Twin@Example.com', DB::table('users')->where('user_id', $ids[1])->value('email'));
        $this->assertSame('twin@example.com', DB::table('users')->where('user_id', $ids[2])->value('email'));
    }
}
