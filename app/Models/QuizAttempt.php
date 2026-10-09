<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizAttempt extends Model
{
    protected $fillable = [
        'student_id',
        'quiz_id',
        'status',
        'active_key',
        'attempt_token',
        'started_at',
        'expires_at',
        'last_seen_at',
        'submitted_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];
}
