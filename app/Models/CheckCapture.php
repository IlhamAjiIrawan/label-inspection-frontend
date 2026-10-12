<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CheckCapture extends Model
{
    use HasFactory;

    protected $table = 'check_captures';

    protected $fillable = [
        'checking_session_id',
        'user_id',
        'reference_id',
        'slot_number',
        'image_path',
        'filename_original',
        'filename_stored',
        'resolution_w',
        'resolution_h',
        'filesize',
        'mime_type',
        'status',
        'ocr_status',
        'cleaned_text',
        'raw_text',
        'ocr_engine',
        // Tuning RapidOCR (2026-09-12): nama model det/rec dari callback FastAPI.
        'ocr_det_model',
        'ocr_rec_model',
        'error_message',
        // Visual Compare Mode (PLAN_VISUAL_COMPARE_MODE §7)
        'compare_match',
        'compare_confidence',
        'compare_notes',
        'compare_model',
        'compared_at',
        // Gemini Text Compare (PLAN_GEMINI_API_TEXT_COMPARE 2026-10-07):
        // audit aditif hasil similarity FastAPI (v1 difflib / v2-gemini).
        // Semua nullable — baris lama + payload lama aman.
        'compare_engine',
        'compare_version',
        'compare_verdict',
        'compare_score',
        'critical_diff_count',
        'minor_diff_count',
        'total_diffs',
        'gemini_diffs_json',
        'external_status',
        'external_path',
        'external_response',
        'external_dispatched_at',
        'external_error',
    ];

    protected $casts = [
        'slot_number'            => 'integer',
        'resolution_w'           => 'integer',
        'resolution_h'           => 'integer',
        'filesize'               => 'integer',
        'compare_match'          => 'boolean',
        'compare_confidence'     => 'float',
        'compared_at'            => 'datetime',
        // Gemini Text Compare (2026-10-07): audit aditif.
        'compare_score'          => 'float',
        'critical_diff_count'    => 'integer',
        'minor_diff_count'       => 'integer',
        'total_diffs'            => 'integer',
        'gemini_diffs_json'      => 'array',
        'external_response'      => 'array',
        'external_dispatched_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(CheckingSession::class, 'checking_session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reference()
    {
        return $this->belongsTo(Reference::class);
    }
}
