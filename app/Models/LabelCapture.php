<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LabelCapture extends Model
{
    use HasFactory;

    protected $table = 'label_captures';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'template_id',
        'template_name',
        'template_width',
        'template_height',
        'original_filename',
        'local_path',
        'resolution_w',
        'resolution_h',
        'filesize',
        'source_timestamp',
        'status',
        'error_message',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'template_width'  => 'integer',
        'template_height' => 'integer',
        'resolution_w'    => 'integer',
        'resolution_h'    => 'integer',
        'filesize'        => 'integer',
    ];

    /**
     * Get the user that owns the capture.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
