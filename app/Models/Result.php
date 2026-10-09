<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Result extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'quiz_id',
        'submission_id',
        'submission_payload_hash',
        'device_id',
        'score',
    ];

    public function details()
    {
        return $this->hasMany(ResultDetail::class);
    }
}
