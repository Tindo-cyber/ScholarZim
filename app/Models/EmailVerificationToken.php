<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailVerificationToken extends Model
{
    protected $table = 'email_verification_tokens';

    protected $primaryKey = 'token_id';

    public $timestamps = false;

    protected $fillable = ['user_id', 'token', 'expires_at', 'used'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function isUsable(): bool
    {
        return ! $this->used && $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
