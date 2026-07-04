<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardPutUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function createAdmin(array $overrides = []): AdminUser
    {
        static $phoneSeq = 0;

        return AdminUser::create(array_merge([
            'name' => 'Test Admin',
            'phone' => '0800000' . str_pad((string) (++$phoneSeq), 4, '0', STR_PAD_LEFT),
            'password' => Hash::make('password'),
            'role' => 'manager',
            'permissions' => ['doctors.view'],
        ], $overrides));
    }

    public function test_admin_update_via_put_json_body_persists_changes(): void
    {
        $admin = $this->createAdmin([
            'name' => 'Original Name',
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->putJson('/api/dashboard/admins/' . $admin->id, [
            'name' => 'Updated Name',
            'permissions' => ['doctors.view', 'doctors.manage'],
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertOk()
            ->assertJsonPath('admin.name', 'Updated Name');

        $this->assertDatabaseHas('admin_users', [
            'id' => $admin->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_admin_update_via_post_method_override_persists_changes(): void
    {
        $admin = $this->createAdmin([
            'name' => 'Original Name',
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->postJson('/api/dashboard/admins/' . $admin->id, [
            'name' => 'Override Updated',
            'permissions' => ['complexes.manage'],
        ], [
            'Authorization' => 'Bearer ' . $token,
            'X-HTTP-Method-Override' => 'PUT',
        ]);

        $response->assertOk()
            ->assertJsonPath('admin.name', 'Override Updated');

        $this->assertDatabaseHas('admin_users', [
            'id' => $admin->id,
            'name' => 'Override Updated',
        ]);
    }
}
