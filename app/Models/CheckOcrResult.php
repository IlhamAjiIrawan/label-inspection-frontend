<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CheckOcrResult extends Model
{
    use HasFactory;

    protected $table = 'check_ocr_results';

    protected $fillable = [
        'user_id',
        'check_session_code',
        'side',
        'label_slot',
        'client_filename',
        'saved_filename',
        'request_id',
        'cleaned_text',
        'lines',
        'status',
        'is_empty',
        'engine',
        'processed_at',
    ];

    protected $casts = [
        'label_slot'   => 'integer',
        'lines'        => 'array',
        'is_empty'     => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Slot global 1..4 (front1=1, front2=2, back1=3, back2=4).
     */
    public function globalSlot(): int
    {
        return $this->side === 'back' ? ((int) $this->label_slot) + 2 : (int) $this->label_slot;
    }
}
