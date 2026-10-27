<?php

namespace App\Services;

use App\Models\TrackEvent;
use App\Models\TrackedEmail;
use App\Models\TrackedLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stores an open or click and keeps the counters on the email in step.
 */
class EventRecorder
{
    /** Gmail's proxy often fetches the same image twice in a row; treat repeats as one open. */
    public const DUPLICATE_WINDOW_SECONDS = 30;

    public function __construct(private EventClassifier $classifier) {}

    public function record(TrackedEmail $email, string $type, Request $request, ?TrackedLink $link = null): TrackEvent
    {
        $info = $this->classifier->classify($request->userAgent());
        $ipHash = $this->classifier->hashIp($request->ip());
        $now = now();

        $automatic = $info['automatic']
            || ($email->sent_at !== null && $email->sent_at->diffInSeconds($now, true) < EventClassifier::AUTOMATIC_WINDOW_SECONDS);

        $duplicate = $type === TrackEvent::OPEN && $email->events()
            ->where('type', TrackEvent::OPEN)
            ->where('ip_hash', $ipHash)
            ->where('occurred_at', '>=', $now->copy()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))
            ->exists();

        return DB::transaction(function () use ($email, $type, $link, $info, $ipHash, $request, $now, $automatic, $duplicate) {
            $event = $email->events()->create([
                'tracked_link_id' => $link?->id,
                'type' => $type,
                'occurred_at' => $now,
                'client' => $info['client'],
                'device' => $info['device'],
                'ip_hash' => $ipHash,
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'is_self' => $email->isSuppressed(),
                // A duplicate fetch is kept for the history but not counted again.
                'is_automatic' => $automatic || $duplicate,
            ]);

            if ($event->countsForRecipient()) {
                $this->applyToCounters($email, $event, $link);
            }

            return $event;
        });
    }

    /**
     * The sender opened the thread in Gmail: their own pixel loads are not recipient opens.
     * Marks the last couple of minutes as self (the pixel usually loads before the extension
     * reports it) and ignores hits for the next minute.
     */
    public function suppress(TrackedEmail $email): void
    {
        DB::transaction(function () use ($email) {
            $recent = $email->events()
                ->where('is_self', false)
                ->where('is_automatic', false)
                ->where('occurred_at', '>=', now()->subMinutes(2))
                ->get();

            foreach ($recent as $event) {
                $event->update(['is_self' => true]);
            }

            $email->suppress_until = now()->addMinute();
            $email->save();

            if ($recent->isNotEmpty()) {
                $this->recount($email);
            }
        });
    }

    /**
     * Rebuilds the counters from the events that count for the recipient.
     */
    public function recount(TrackedEmail $email): void
    {
        $counting = $email->events()->where('is_self', false)->where('is_automatic', false);

        $opens = (clone $counting)->where('type', TrackEvent::OPEN);
        $clicks = (clone $counting)->where('type', TrackEvent::CLICK);

        $email->opens_count = (clone $opens)->count();
        $email->first_opened_at = (clone $opens)->min('occurred_at');
        $email->last_opened_at = (clone $opens)->max('occurred_at');
        $email->clicks_count = (clone $clicks)->count();
        $email->last_clicked_at = (clone $clicks)->max('occurred_at');
        $email->save();

        foreach ($email->links as $link) {
            $link->clicks_count = (clone $clicks)->where('tracked_link_id', $link->id)->count();
            $link->save();
        }
    }

    private function applyToCounters(TrackedEmail $email, TrackEvent $event, ?TrackedLink $link): void
    {
        if ($event->type === TrackEvent::OPEN) {
            $email->opens_count++;
            $email->first_opened_at ??= $event->occurred_at;
            $email->last_opened_at = $event->occurred_at;
        } else {
            $email->clicks_count++;
            $email->last_clicked_at = $event->occurred_at;
            // Clicking a link means the email was read, even when images were blocked.
            $email->first_opened_at ??= $event->occurred_at;
            $link?->increment('clicks_count');
        }

        $email->save();
    }
}
