<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['position', 'url'])]
class TrackedLink extends Model
{
    /**
     * @return BelongsTo<TrackedEmail, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(TrackedEmail::class, 'tracked_email_id');
    }
}
