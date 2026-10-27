<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrackEvent;
use App\Models\TrackedEmail;
use App\Services\EventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the extension reads and writes: registering sent emails, listing their status,
 * the activity feed and the sender's own views.
 */
class EmailController extends Controller
{
    public function __construct(private EventRecorder $recorder) {}

    /**
     * Called by the extension right after Send. Safe to retry: the token makes it idempotent.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:16', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'subject' => ['nullable', 'string', 'max:500'],
            'recipients' => ['present', 'array', 'max:100'],
            'recipients.*' => ['string', 'max:255'],
            'sent_at' => ['nullable', 'date'],
            'links' => ['nullable', 'array', 'max:50'],
            'links.*.position' => ['required', 'integer', 'min:0', 'max:255'],
            'links.*.url' => ['required', 'url:http,https', 'max:2000'],
        ]);

        $existing = TrackedEmail::where('token', $data['token'])->first();
        abort_if($existing !== null && $existing->user_id !== $request->user()->id, 409, 'Token already in use.');

        $email = $existing ?? $request->user()->trackedEmails()->make(['token' => $data['token']]);
        $email->fill([
            'subject' => $data['subject'] ?? '',
            'recipients' => array_values(array_unique(array_map('strtolower', $data['recipients']))),
            'sent_at' => $data['sent_at'] ?? now(),
        ])->save();

        foreach ($data['links'] ?? [] as $link) {
            $email->links()->updateOrCreate(['position' => $link['position']], ['url' => $link['url']]);
        }

        return response()->json(['email' => $this->summary($email->fresh())], $existing ? 200 : 201);
    }

    /**
     * Recent emails with their counters, for the checkmarks in Gmail.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $emails = $request->user()->trackedEmails()
            ->when($data['since'] ?? null, fn ($q, $since) => $q->where('updated_at', '>=', $since))
            ->latest('sent_at')
            ->limit($data['limit'] ?? 300)
            ->get();

        return response()->json([
            'emails' => $emails->map(fn (TrackedEmail $e) => $this->summary($e))->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function show(Request $request, string $token): JsonResponse
    {
        $email = $request->user()->trackedEmails()->where('token', $token)->firstOrFail();
        $email->load(['links', 'events' => fn ($q) => $q->latest('occurred_at')->limit(200)]);

        return response()->json([
            'email' => $this->summary($email),
            'links' => $email->links->map(fn ($l) => [
                'position' => $l->position, 'url' => $l->url, 'clicks' => $l->clicks_count,
            ])->values(),
            'events' => $email->events->map(fn (TrackEvent $ev) => $this->event($ev))->values(),
        ]);
    }

    /**
     * Opens and clicks that count, newest first: the feed and the desktop notifications.
     */
    public function activity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $events = TrackEvent::query()
            ->whereHas('email', fn ($q) => $q->where('user_id', $request->user()->id))
            ->where('is_self', false)
            ->where('is_automatic', false)
            ->when($data['since'] ?? null, fn ($q, $since) => $q->where('occurred_at', '>', $since))
            ->with(['email', 'link'])
            ->latest('occurred_at')
            ->limit($data['limit'] ?? 30)
            ->get();

        return response()->json([
            'events' => $events->map(fn (TrackEvent $ev) => $this->event($ev) + [
                'email' => $this->summary($ev->email),
            ])->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $emails = $request->user()->trackedEmails()->where('sent_at', '>=', now()->subDays(30));

        $sent = (clone $emails)->count();
        $opened = (clone $emails)->where('opens_count', '>', 0)->count();
        $clicked = (clone $emails)->where('clicks_count', '>', 0)->count();

        return response()->json([
            'days' => 30,
            'sent' => $sent,
            'opened' => $opened,
            'clicked' => $clicked,
            'open_rate' => $sent ? round($opened / $sent * 100) : 0,
            'click_rate' => $sent ? round($clicked / $sent * 100) : 0,
        ]);
    }

    /**
     * The sender has these emails open in Gmail right now; their own views must not count.
     */
    public function suppress(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tokens' => ['required', 'array', 'max:50'],
            'tokens.*' => ['string', 'max:40'],
        ]);

        $emails = $request->user()->trackedEmails()->whereIn('token', $data['tokens'])->with('links')->get();
        $emails->each(fn (TrackedEmail $e) => $this->recorder->suppress($e));

        return response()->json(['emails' => $emails->map(fn ($e) => $this->summary($e->fresh()))->values()]);
    }

    private function summary(TrackedEmail $e): array
    {
        return [
            'token' => $e->token,
            'subject' => $e->subject,
            'recipients' => $e->recipients,
            'sent_at' => $e->sent_at?->toIso8601String(),
            'opens' => $e->opens_count,
            'clicks' => $e->clicks_count,
            'first_opened_at' => $e->first_opened_at?->toIso8601String(),
            'last_opened_at' => $e->last_opened_at?->toIso8601String(),
            'last_clicked_at' => $e->last_clicked_at?->toIso8601String(),
        ];
    }

    private function event(TrackEvent $ev): array
    {
        return [
            'type' => $ev->type,
            'at' => $ev->occurred_at->toIso8601String(),
            'client' => $ev->client,
            'device' => $ev->device,
            'link' => $ev->link?->url,
            'is_self' => $ev->is_self,
            'is_automatic' => $ev->is_automatic,
        ];
    }
}
