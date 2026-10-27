<?php

namespace Tests\Feature;

use App\Models\TrackedEmail;
use App\Models\User;
use App\Services\EventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrackingTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'abcDEF1234567890xyz_';

    private const GMAIL_UA = 'Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)';

    private function registeredEmail(?User $user = null, array $overrides = []): TrackedEmail
    {
        $user ??= User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/emails', array_merge([
            'token' => self::TOKEN,
            'subject' => 'About your Backend Developer opening',
            'recipients' => ['Info@Cloud6.ae'],
            'sent_at' => now()->subMinutes(10)->toIso8601String(),
            'links' => [['position' => 0, 'url' => 'https://ekfa.vercel.app/']],
        ], $overrides))->assertSuccessful();

        return TrackedEmail::where('token', self::TOKEN)->firstOrFail();
    }

    public function test_register_and_login_return_a_token(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Ekfa', 'email' => 'ekfa@example.com', 'password' => 'secret-pass-1',
        ])->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $this->postJson('/api/auth/login', ['email' => 'EKFA@example.com', 'password' => 'secret-pass-1'])
            ->assertOk()->assertJsonStructure(['token']);

        $this->postJson('/api/auth/login', ['email' => 'ekfa@example.com', 'password' => 'wrong'])
            ->assertUnprocessable();
    }

    public function test_registering_an_email_is_idempotent_and_lowercases_recipients(): void
    {
        $email = $this->registeredEmail();
        $this->registeredEmail($email->user);

        $this->assertSame(1, TrackedEmail::count());
        $this->assertSame(['info@cloud6.ae'], $email->recipients);
        $this->assertSame(1, $email->links()->count());
    }

    public function test_another_user_cannot_take_over_a_token(): void
    {
        $this->registeredEmail();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/emails', ['token' => self::TOKEN, 'recipients' => []])->assertStatus(409);
    }

    public function test_pixel_returns_an_uncached_gif_and_counts_an_open(): void
    {
        $email = $this->registeredEmail();

        $this->get('/o/'.self::TOKEN.'.gif', ['User-Agent' => self::GMAIL_UA])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/gif')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');

        $email->refresh();
        $this->assertSame(1, $email->opens_count);
        $this->assertNotNull($email->first_opened_at);
        $this->assertSame('Gmail', $email->events()->first()->client);
    }

    public function test_unknown_pixel_still_returns_an_image(): void
    {
        $this->get('/o/unknownToken12345678.gif')->assertOk()->assertHeader('Content-Type', 'image/gif');
    }

    public function test_repeat_fetch_from_the_same_ip_is_not_counted_twice(): void
    {
        $email = $this->registeredEmail();

        $this->get('/o/'.self::TOKEN.'.gif', ['User-Agent' => self::GMAIL_UA]);
        $this->get('/o/'.self::TOKEN.'.gif', ['User-Agent' => self::GMAIL_UA]);

        $email->refresh();
        $this->assertSame(1, $email->opens_count);
        $this->assertSame(2, $email->events()->count());

        $this->travel(EventRecorder::DUPLICATE_WINDOW_SECONDS + 1)->seconds();
        $this->get('/o/'.self::TOKEN.'.gif', ['User-Agent' => self::GMAIL_UA]);
        $this->assertSame(2, $email->fresh()->opens_count);
    }

    public function test_security_scanner_and_instant_hits_are_marked_automatic(): void
    {
        $email = $this->registeredEmail();
        $this->get('/c/'.self::TOKEN.'/0', ['User-Agent' => 'Mozilla/5.0 Barracuda LinkProtect'])
            ->assertRedirect('https://ekfa.vercel.app/');

        $this->assertSame(0, $email->fresh()->clicks_count);
        $this->assertTrue($email->events()->first()->is_automatic);

        // An open one second after sending is a machine, not a person.
        $email->update(['sent_at' => now()]);
        $this->get('/o/'.self::TOKEN.'.gif', ['User-Agent' => self::GMAIL_UA]);
        $this->assertSame(0, $email->fresh()->opens_count);
    }

    public function test_click_redirects_to_the_registered_link_not_the_query_string(): void
    {
        $email = $this->registeredEmail();

        $this->get('/c/'.self::TOKEN.'/0?u=https://evil.example/', ['User-Agent' => self::GMAIL_UA])
            ->assertRedirect('https://ekfa.vercel.app/');

        $email->refresh();
        $this->assertSame(1, $email->clicks_count);
        $this->assertNotNull($email->first_opened_at, 'A click implies the email was opened.');
        $this->assertSame(1, $email->links()->first()->clicks_count);
    }

    public function test_unknown_email_link_shows_a_continue_page_instead_of_redirecting(): void
    {
        $this->get('/c/unknownToken12345678/0?u=https://ekfa.vercel.app/')
            ->assertOk()->assertSee('Continue to ekfa.vercel.app');

        $this->get('/c/unknownToken12345678/0?u=javascript:alert(1)')->assertNotFound();
    }

    public function test_senders_own_view_is_not_counted(): void
    {
        $email = $this->registeredEmail();

        // Pixel loads first, then the extension reports that the sender opened the thread.
        $this->get('/o/'.self::TOKEN.'.gif', ['User-Agent' => self::GMAIL_UA]);
        $this->assertSame(1, $email->fresh()->opens_count);

        $this->postJson('/api/emails/suppress', ['tokens' => [self::TOKEN]])->assertOk();
        $this->assertSame(0, $email->fresh()->opens_count);

        // Loads during the suppression window are also the sender's.
        $this->travel(EventRecorder::DUPLICATE_WINDOW_SECONDS + 1)->seconds();
        $this->get('/o/'.self::TOKEN.'.gif', ['User-Agent' => self::GMAIL_UA]);
        $this->assertSame(0, $email->fresh()->opens_count);

        // After it expires, opens count again.
        $this->travel(2)->minutes();
        $this->get('/o/'.self::TOKEN.'.gif', ['User-Agent' => self::GMAIL_UA]);
        $this->assertSame(1, $email->fresh()->opens_count);
    }

    public function test_activity_lists_only_counting_events_for_the_current_user(): void
    {
        $email = $this->registeredEmail();
        $this->get('/o/'.self::TOKEN.'.gif', ['User-Agent' => self::GMAIL_UA]);
        $this->get('/c/'.self::TOKEN.'/0', ['User-Agent' => 'curl/8.0']);

        $this->getJson('/api/activity')->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.type', 'open')
            ->assertJsonPath('events.0.email.subject', 'About your Backend Developer opening');

        $this->getJson('/api/emails/'.self::TOKEN)->assertOk()->assertJsonCount(2, 'events');
        $this->getJson('/api/stats')->assertOk()->assertJsonPath('open_rate', 100);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/activity')->assertOk()->assertJsonCount(0, 'events');
        $this->getJson('/api/emails/'.self::TOKEN)->assertNotFound();
    }

    public function test_api_requires_a_token(): void
    {
        $this->getJson('/api/emails')->assertUnauthorized();
    }

    public function test_public_pages_render(): void
    {
        $this->get('/')->assertOk()->assertSee('Mail Track');
        $this->get('/privacy')->assertOk()->assertSee('Privacy policy');
    }
}

