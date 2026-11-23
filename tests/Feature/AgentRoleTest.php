<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Auth\Models\ClientAccount;
use Modules\Boarding\Models\BoardingClient;
use Modules\Inbound\Models\Client;
use Tests\TestCase;

class AgentRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Bus::fake();
    }

    private function user(UserRole $role, bool $superAdmin = false): User
    {
        return User::query()->forceCreate([
            'name'           => ucfirst($role->value) . ' ' . Str::random(4),
            'email'          => Str::random(8) . '@example.com',
            'password'       => 'password',
            'role'           => $role,
            'is_super_admin' => $superAdmin,
        ]);
    }

    private function client(string $name, ?User $agent = null): Client
    {
        return Client::query()->forceCreate([
            'id'            => (string) Str::uuid(),
            'pms_client_id' => (string) Str::uuid(),
            'client_name'   => $name,
            'client_pms'    => 'CLIO',
            'agent_id'      => $agent?->id,
        ]);
    }

    private function boardingClient(string $name, ?User $agent = null): BoardingClient
    {
        return BoardingClient::query()->forceCreate([
            'name'           => $name,
            'client_id'      => 'iso_' . Str::uuid(),
            'client_api_key' => 'sbk_test',
            'status'         => 'active',
            'agent_id'       => $agent?->id,
        ]);
    }

    private function account(Client|BoardingClient $owner): ClientAccount
    {
        $email = Str::random(8) . '@client.test';

        return ClientAccount::query()->forceCreate([
            'owner_type'  => $owner::class,
            'owner_id'    => $owner->id,
            'email'       => $email,
            'email_lower' => $email,
            'is_active'   => true,
        ]);
    }

    public function test_existing_users_default_to_admin(): void
    {
        $user = User::query()->forceCreate([
            'name' => 'Legacy', 'email' => 'legacy@example.com', 'password' => 'password',
        ])->fresh();

        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertFalse($user->isAgent());
    }

    public function test_admin_sees_every_client(): void
    {
        $agent = $this->user(UserRole::Agent);
        $this->client('Agent Firm', $agent);
        $this->client('Unassigned Firm');
        $this->boardingClient('Agent ISO', $agent);

        $this->actingAs($this->user(UserRole::Admin))
            ->get(route('inbound.clients.index'))
            ->assertOk()
            ->assertSee('Agent Firm')
            ->assertSee('Unassigned Firm')
            ->assertSee('Agent ISO')
            ->assertSee('Agent: ' . $agent->name);
    }

    public function test_agent_sees_only_their_own_clients(): void
    {
        $agent = $this->user(UserRole::Agent);
        $other = $this->user(UserRole::Agent);
        $this->client('My Firm', $agent);
        $this->boardingClient('My ISO', $agent);
        $this->client('Other Agent Firm', $other);
        $this->client('Unassigned Firm');
        $this->boardingClient('Other ISO', $other);

        $this->actingAs($agent)
            ->get(route('inbound.clients.index'))
            ->assertOk()
            ->assertSee('My Firm')
            ->assertSee('My ISO')
            ->assertDontSee('Other Agent Firm')
            ->assertDontSee('Unassigned Firm')
            ->assertDontSee('Other ISO')
            ->assertSee('2 clients');
    }

    public function test_agent_gets_the_same_list_and_create_pages_as_an_admin(): void
    {
        $agent = $this->user(UserRole::Agent);
        $this->client('My Firm', $agent);

        $this->actingAs($agent)
            ->get(route('inbound.clients.index'))
            ->assertOk()
            ->assertSee('Manage all connected PMS clients and their configurations')
            ->assertSee('New Client')
            ->assertSee('Agent: ' . $agent->name);

        $this->actingAs($agent)
            ->get(route('inbound.clients.create'))
            ->assertOk()
            ->assertSee(route('inbound.clients.store'), false);
    }

    public function test_create_client_cancel_goes_to_the_client_list_for_admins_and_agents(): void
    {
        foreach ([UserRole::Admin, UserRole::Agent] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('inbound.clients.create'))
                ->assertOk()
                ->assertSee('href="' . route('inbound.clients.index') . '" class="btn">Cancel', false);
        }
    }

    public function test_mindbody_and_advancedmd_show_as_coming_soon_for_admins_and_agents(): void
    {
        foreach ([UserRole::Admin, UserRole::Agent] as $role) {
            $html = $this->actingAs($this->user($role))
                ->get(route('inbound.clients.create'))
                ->assertOk()
                ->content();

            foreach (['MINDBODY', 'ADVANCEDMD'] as $pms) {
                $this->assertMatchesRegularExpression(
                    '/class="tile pms coming-soon" data-value="' . $pms . '" data-tooltip="Coming soon\.\." aria-disabled="true"/',
                    $html,
                    "{$pms} tile should be disabled for {$role->value}"
                );
            }

            // Working PMS tiles stay clickable.
            $this->assertStringContainsString('data-value="WAVE" onclick="selectPMS(this)"', $html);
        }
    }

    public function test_coming_soon_pms_cannot_be_submitted(): void
    {
        foreach (['MINDBODY', 'advancedmd'] as $pms) {
            $this->actingAs($this->user(UserRole::Admin))
                ->from(route('inbound.clients.create'))
                ->post(route('inbound.clients.store'), [
                    'integration_type' => 'pms',
                    'client_name'      => "Soon {$pms}",
                    'client_email'     => 'soon@client.test',
                    'client_pms'       => $pms,
                ])
                ->assertSessionHasErrors('client_pms');

            $this->assertFalse(Client::query()->where('client_name', "Soon {$pms}")->exists());
        }
    }

    public function test_client_created_by_an_agent_belongs_to_them(): void
    {
        $agent = $this->user(UserRole::Agent);

        $this->actingAs($agent)->post(route('inbound.clients.store'), [
            'integration_type'   => 'boarding',
            'client_name'        => 'New ISO',
            'client_email'       => 'new-iso@client.test',
            'allowed_processors' => ['square'],
        ])->assertRedirect();

        $this->assertSame($agent->id, BoardingClient::query()->where('name', 'New ISO')->value('agent_id'));
    }

    public function test_client_created_by_an_admin_is_unassigned(): void
    {
        $this->actingAs($this->user(UserRole::Admin))->post(route('inbound.clients.store'), [
            'integration_type'   => 'boarding',
            'client_name'        => 'Admin ISO',
            'client_email'       => 'admin-iso@client.test',
            'allowed_processors' => ['square'],
        ])->assertRedirect();

        $this->assertNull(BoardingClient::query()->where('name', 'Admin ISO')->value('agent_id'));
    }

    public function test_agent_cannot_change_or_delete_another_agents_client(): void
    {
        $agent   = $this->user(UserRole::Agent);
        $foreign = $this->client('Foreign Firm', $this->user(UserRole::Agent));
        $account = $this->account($foreign);

        $this->actingAs($agent)
            ->patch(route('inbound.clients.update-status', ['client_id' => $foreign->id]))
            ->assertNotFound();
        $this->assertTrue($account->fresh()->is_active);

        $this->actingAs($agent)
            ->delete(route('inbound.clients.destroy', ['client_id' => $foreign->id]))
            ->assertNotFound();
        $this->assertNotSoftDeleted($foreign);
    }

    public function test_agent_can_change_and_delete_their_own_client(): void
    {
        $agent   = $this->user(UserRole::Agent);
        $mine    = $this->client('My Firm', $agent);
        $account = $this->account($mine);

        $this->actingAs($agent)
            ->patch(route('inbound.clients.update-status', ['client_id' => $mine->id]))
            ->assertRedirect(route('inbound.clients.index'));
        $this->assertFalse($account->fresh()->is_active);

        $this->actingAs($agent)
            ->delete(route('inbound.clients.destroy', ['client_id' => $mine->id]))
            ->assertRedirect(route('inbound.clients.index'));
        $this->assertSoftDeleted($mine);
    }

    public function test_agent_cannot_open_another_agents_boarding_client(): void
    {
        $agent   = $this->user(UserRole::Agent);
        $foreign = $this->boardingClient('Foreign ISO', $this->user(UserRole::Agent));

        $this->actingAs($agent)
            ->get(route('inbound.clients.boarding.show', $foreign->client_id))
            ->assertNotFound();

        $this->actingAs($agent)
            ->post(route('inbound.clients.boarding.toggle-status', $foreign->client_id))
            ->assertNotFound();
        $this->assertSame('active', $foreign->fresh()->status);
    }

    public function test_boarding_admin_pages_now_require_an_admin_login(): void
    {
        $iso = $this->boardingClient('Some ISO');

        $this->get(route('inbound.clients.boarding.show', $iso->client_id))
            ->assertRedirect(route('admin.login'));

        $this->post(route('inbound.clients.boarding.toggle-status', $iso->client_id))
            ->assertRedirect(route('admin.login'));
    }

    public function test_booksync_admin_requires_login_and_is_closed_to_agents(): void
    {
        $this->get(route('booksync.admin.clients.index'))
            ->assertRedirect(route('admin.login'));

        $this->actingAs($this->user(UserRole::Agent))
            ->get(route('booksync.admin.clients.index'))
            ->assertForbidden();
    }

    // ── "View as client" (impersonation) ───────────────────────────────────

    private function assertViewAsStarted(User $user, ClientAccount $account): void
    {
        $this->actingAs($user)
            ->post(route('admin.impersonate.start', $account->id))
            ->assertRedirect()
            ->assertCookie('merchant_session');

        $this->assertDatabaseHas('merchant_sessions', [
            'client_account_id'       => $account->id,
            'is_impersonation'        => true,
            'impersonated_by_user_id' => $user->id,
        ]);
    }

    private function assertViewAsRefused(User $user, ClientAccount $account): void
    {
        $this->actingAs($user)
            ->post(route('admin.impersonate.start', $account->id))
            ->assertForbidden();

        $this->assertDatabaseMissing('merchant_sessions', ['client_account_id' => $account->id]);
    }

    public function test_agent_can_view_as_their_own_client_without_super_admin(): void
    {
        $agent = $this->user(UserRole::Agent);

        $this->assertViewAsStarted($agent, $this->account($this->client('My Firm', $agent)));
    }

    public function test_agent_can_view_as_their_own_boarding_client(): void
    {
        $agent = $this->user(UserRole::Agent);

        $this->assertViewAsStarted($agent, $this->account($this->boardingClient('My ISO', $agent)));
    }

    public function test_agent_cannot_view_as_another_agents_or_an_unassigned_client(): void
    {
        $agent = $this->user(UserRole::Agent);

        $this->assertViewAsRefused($agent, $this->account($this->client('Foreign Firm', $this->user(UserRole::Agent))));
        $this->assertViewAsRefused($agent, $this->account($this->client('Unassigned Firm')));
    }

    public function test_super_admin_flag_does_not_widen_an_agents_access(): void
    {
        $agent = $this->user(UserRole::Agent, superAdmin: true);

        $this->assertViewAsRefused($agent, $this->account($this->client('Foreign Firm', $this->user(UserRole::Agent))));
    }

    public function test_regular_admin_still_cannot_view_as_client(): void
    {
        $this->assertViewAsRefused($this->user(UserRole::Admin), $this->account($this->client('Any Firm')));
    }

    public function test_super_admin_can_still_view_as_any_client(): void
    {
        $superAdmin = $this->user(UserRole::Admin, superAdmin: true);

        $this->assertViewAsStarted($superAdmin, $this->account($this->client('Any Firm', $this->user(UserRole::Agent))));
    }

    public function test_view_as_client_button_follows_the_same_rule(): void
    {
        $agent = $this->user(UserRole::Agent);
        $this->account($this->client('My Firm', $agent));

        $this->actingAs($agent)->get(route('inbound.clients.index'))->assertSee('View as client');
        $this->actingAs($this->user(UserRole::Admin))->get(route('inbound.clients.index'))->assertDontSee('View as client');
        $this->actingAs($this->user(UserRole::Admin, superAdmin: true))->get(route('inbound.clients.index'))->assertSee('View as client');
    }

    public function test_admin_create_command_makes_an_agent(): void
    {
        $this->artisan('admin:create', ['--name' => 'Ann Agent', '--email' => 'ann@example.com', '--role' => 'agent'])
            ->expectsQuestion('Password', 'secret-pass')
            ->expectsQuestion('Confirm password', 'secret-pass')
            ->assertSuccessful();

        $this->assertSame(UserRole::Agent, User::query()->where('email', 'ann@example.com')->first()->role);
    }

    public function test_admin_create_command_still_defaults_to_admin(): void
    {
        $this->artisan('admin:create', ['--name' => 'Ada Admin', '--email' => 'ada@example.com'])
            ->expectsQuestion('Password', 'secret-pass')
            ->expectsQuestion('Confirm password', 'secret-pass')
            ->assertSuccessful();

        $this->assertSame(UserRole::Admin, User::query()->where('email', 'ada@example.com')->first()->role);
    }

    public function test_assign_agent_command_assigns_and_unassigns(): void
    {
        $agent  = $this->user(UserRole::Agent);
        $client = $this->client('Existing Firm');

        $this->artisan('client:assign-agent', ['client' => $client->pms_client_id, 'agent' => $agent->email])
            ->assertSuccessful();
        $this->assertSame($agent->id, $client->fresh()->agent_id);

        $this->artisan('client:assign-agent', ['client' => $client->id, '--unassign' => true])
            ->assertSuccessful();
        $this->assertNull($client->fresh()->agent_id);
    }

    public function test_assign_agent_command_refuses_a_non_agent_user(): void
    {
        $admin  = $this->user(UserRole::Admin);
        $client = $this->client('Existing Firm');

        $this->artisan('client:assign-agent', ['client' => $client->id, 'agent' => $admin->email])
            ->assertFailed();
        $this->assertNull($client->fresh()->agent_id);
    }
}
