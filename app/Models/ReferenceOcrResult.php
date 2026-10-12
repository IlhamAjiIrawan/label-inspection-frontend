<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferenceOcrResult extends Model
{
    use HasFactory;

    protected $table = 'reference_ocr_results';

    protected $fillable = [
        'reference_id',
        'label_capture_id',
        'user_id',
        'original_filename',
        'ocr_filename',
        'engine',
        // Tuning RapidOCR (2026-09-12): nama model det/rec dari callback FastAPI.
        'det_model',
        'rec_model',
        'text_present',
        'language',
        'preprocessed',
        'preprocessing_steps',
        'raw_text',
        'cleaned_text',
        'lines',
        // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05) TAHAP L2: per sisi.
        'cleaned_text_front',
        'lines_front',
        'cleaned_text_back',
        'lines_back',
        'has_back',
        'status',
        'error',
        'file_size_bytes',
        'processing_time_seconds',
        'processed_at',
        'external_response',
    ];

    protected $casts = [
        'preprocessed'            => 'boolean',
        'text_present'            => 'boolean',
        'preprocessing_steps'     => 'array',
        'lines'                   => 'array',
        // REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05) TAHAP L2.
        'lines_front'             => 'array',
        'lines_back'              => 'array',
        'has_back'                => 'boolean',
        'external_response'       => 'array',
        'processed_at'            => 'datetime',
        'processing_time_seconds' => 'float',
        'file_size_bytes'         => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reference()
    {
        return $this->belongsTo(Reference::class);
    }

    public function labelCapture()
    {
        return $this->belongsTo(LabelCapture::class);
    }

    public function isProcessing()
    {
        return $this->status === 'processing';
    }

    public function isSuccess()
    {
        return $this->status === 'success';
    }

    public function isFailed()
    {
        return $this->status === 'failed';
    }

    /**
     * REVIEW_FLOW_COMPARE_FRONT_BACK_PLAN (2026-10-05) TAHAP L2.
     * Backward compat: reference lama hanya punya cleaned_text tunggal
     * (asumsi = sisi front, sama seperti migrasi FastAPI ref_library).
     */
    public function getCleanedTextFrontAttribute($value)
    {
        return $value ?? $this->attributes['cleaned_text'] ?? null;
    }

    public function getCleanedTextBackAttribute($value)
    {
        return $value ?? null;
    }
}
