<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class AdminLogoutTest extends TestCase
{
    private function admin(): User
    {
        return (new User)->forceFill(['id' => 1, 'name' => 'Test Admin', 'email' => 'admin@example.com']);
    }

    public function test_admin_bar_shows_logout_for_a_signed_in_admin(): void
    {
        $this->actingAs($this->admin());

        $html = view('auth::partials.admin-bar')->render();

        $this->assertStringContainsString('Test Admin', $html);
        $this->assertStringContainsString(route('admin.logout'), $html);
    }

    public function test_admin_bar_renders_nothing_without_an_admin_session(): void
    {
        $this->assertSame('', trim(view('auth::partials.admin-bar')->render()));
    }

    public function test_logout_ends_the_admin_session_and_returns_to_admin_login(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('web');
    }

    public function test_logout_requires_an_admin_session(): void
    {
        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
    }
}
