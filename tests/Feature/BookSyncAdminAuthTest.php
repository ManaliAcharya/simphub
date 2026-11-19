<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\BookSync\Models\BookSyncClient;
use Tests\TestCase;

/**
 * The BookSync admin pages used to be reachable by anyone with the URL.
 * They now need an admin (web guard) login, and keep working for admins.
 */
class BookSyncAdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->forceCreate([
            'name'     => 'BookSync Admin',
            'email'    => 'booksync-admin@example.com',
            'password' => 'password',
            'role'     => UserRole::Admin,
        ]);
    }

    private function bookSyncClient(): BookSyncClient
    {
        return BookSyncClient::query()->forceCreate([
            'id'             => (string) Str::uuid(),
            'name'           => 'Acme Books',
            'client_id'      => 'bs_' . Str::random(12),
            'client_api_key' => 'key_test',
            'status'         => 'active',
        ]);
    }

    public function test_guests_are_sent_to_admin_login_for_every_booksync_admin_page(): void
    {
        $client = $this->bookSyncClient();

        $urls = [
            ['get', route('booksync.admin.logs.index')],
            ['get', route('booksync.admin.clients.index')],
            ['get', route('booksync.admin.clients.create')],
            ['post', route('booksync.admin.clients.store')],
            ['get', route('booksync.admin.clients.show', $client->client_id)],
            ['get', route('booksync.admin.clients.api-docs', $client->client_id)],
            ['post', route('booksync.admin.clients.toggle-status', $client->client_id)],
            ['post', route('booksync.admin.clients.set-portal-password', $client->client_id)],
            ['get', route('booksync.admin.merchants.create', $client->client_id)],
            ['post', route('booksync.admin.merchants.store', $client->client_id)],
            ['get', route('booksync.admin.merchants.show', 'm_unknown')],
            ['post', route('booksync.admin.merchants.toggle-status', 'm_unknown')],
        ];

        foreach ($urls as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('admin.login'));
        }

        $this->assertSame('active', $client->fresh()->status, 'A guest must not be able to toggle a client.');
    }

    public function test_admin_can_use_booksync_admin_pages(): void
    {
        $client = $this->bookSyncClient();

        $this->actingAs($this->admin());

        $this->get(route('booksync.admin.clients.index'))
            ->assertOk()
            ->assertSee('Acme Books')
            ->assertSee('Signed in as')
            ->assertSee(route('admin.logout'));

        $this->get(route('booksync.admin.logs.index'))->assertOk();
        $this->get(route('booksync.admin.clients.show', $client->client_id))->assertOk();
        $this->get(route('booksync.admin.clients.api-docs', $client->client_id))
            ->assertOk()
            ->assertSee('Signed in as');

        $this->post(route('booksync.admin.clients.toggle-status', $client->client_id))->assertRedirect();
        $this->assertSame('inactive', $client->fresh()->status);
    }

    public function test_admin_returns_to_the_booksync_page_after_logging_in(): void
    {
        $this->admin();

        $this->get(route('booksync.admin.clients.index'))->assertRedirect(route('admin.login'));

        $this->post(route('admin.login.submit'), [
            'email'    => 'booksync-admin@example.com',
            'password' => 'password',
        ])->assertRedirect(route('booksync.admin.clients.index'));
    }

    public function test_public_booksync_pages_still_need_no_admin_login(): void
    {
        $this->get(route('booksync.portal.login'))->assertOk();
        $this->get(route('booksync.docs'))->assertOk();
    }
}
