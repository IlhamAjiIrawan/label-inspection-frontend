<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reference extends Model
{
    use HasFactory;

    protected $table = 'references';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'template_id',
        'template_name',
        'name',
        'template_width',
        'template_height',
        'image_path',
        'filename',
        'session_key',
        'side',
        'fastapi_ref_id',
        'fastapi_ocr_status',
        'fastapi_thumb_url',
        'active',
        'resolution_w',
        'resolution_h',
        'filesize',
    ];

    /**
     * Cast atribut ke tipe native.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Get the user that owns the reference.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ocrResult()
    {
        return $this->hasOne(ReferenceOcrResult::class);
    }

    public function checkingSessions()
    {
        return $this->hasMany(CheckingSession::class);
    }

    public function checkCaptures()
    {
        return $this->hasMany(CheckCapture::class, 'reference_id');
    }
}
