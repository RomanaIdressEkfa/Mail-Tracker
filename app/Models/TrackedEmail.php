<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['token', 'subject', 'recipients', 'sent_at', 'suppress_until'])]
class TrackedEmail extends Model
{
    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'sent_at' => 'datetime',
            'first_opened_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'last_clicked_at' => 'datetime',
            'suppress_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<TrackedLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(TrackedLink::class);
    }

    /**
     * @return HasMany<TrackEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(TrackEvent::class);
    }

    public function isSuppressed(): bool
    {
        return $this->suppress_until !== null && $this->suppress_until->isFuture();
    }
}
