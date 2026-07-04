<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationBroadcast extends Model
{
    protected $fillable = [
        'admin_user_id',
        'target_type',
        'target_id',
        'target_name',
        'title',
        'body',
        'image_url',
        'tokens_targeted',
        'devices_delivered',
        'devices_failed',
        'status',
        'failure_reason',
        'resent_from_id',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'tokens_targeted' => 'integer',
        'devices_delivered' => 'integer',
        'devices_failed' => 'integer',
    ];

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    public function resentFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resent_from_id');
    }
}
