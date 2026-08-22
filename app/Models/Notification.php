<?php

namespace App\Models;

use App\Support\NotificationPresentation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $table = 'notifications';

    protected $primaryKey = 'notification_id';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'type',
        'message',
        'link',
        'related_id',
        'is_read',
        'created_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function icon(): string
    {
        return NotificationPresentation::icon($this->type);
    }

    public function tone(): string
    {
        return NotificationPresentation::tone($this->type);
    }

    public function category(): string
    {
        return NotificationPresentation::category($this->type);
    }
}
