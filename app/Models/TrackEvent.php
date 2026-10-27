<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tracked_link_id', 'type', 'occurred_at', 'client', 'device', 'ip_hash', 'user_agent', 'is_self', 'is_automatic'])]
class TrackEvent extends Model
{
    public const OPEN = 'open';

    public const CLICK = 'click';

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'is_self' => 'boolean',
            'is_automatic' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TrackedEmail, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(TrackedEmail::class, 'tracked_email_id');
    }

    /**
     * @return BelongsTo<TrackedLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(TrackedLink::class, 'tracked_link_id');
    }

    /**
     * Counted in the totals shown to the sender.
     */
    public function countsForRecipient(): bool
    {
        return ! $this->is_self && ! $this->is_automatic;
    }
}
