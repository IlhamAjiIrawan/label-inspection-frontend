<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CheckingSession extends Model
{
    use HasFactory;

    protected $table = 'checking_sessions';

    protected $fillable = [
        'user_id',
        'reference_id',
        'batch_code',
        'template_id',
        'template_name',
        'template_width',
        'template_height',
        'total_slots',
        'status',
        'notes',
        // Visual Compare Mode (PLAN_VISUAL_COMPARE_MODE §7)
        'compare_status',
        'compare_summary',
        'compared_at',
        'external_status',
        'external_batch_id',
        'external_response',
        'external_dispatched_at',
        'external_error',
        'retry_count',
    ];

    protected $casts = [
        'template_width'         => 'integer',
        'template_height'        => 'integer',
        'total_slots'            => 'integer',
        'retry_count'            => 'integer',
        'compare_summary'        => 'array',
        'compared_at'            => 'datetime',
        'external_response'      => 'array',
        'external_dispatched_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reference()
    {
        return $this->belongsTo(Reference::class);
    }

    public function captures()
    {
        return $this->hasMany(CheckCapture::class, 'checking_session_id');
    }
}
