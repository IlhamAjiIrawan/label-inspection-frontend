<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'username',
        'nik',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Get the references for the user.
     */
    public function references()
    {
        return $this->hasMany(Reference::class);
    }

    /**
     * Get the label captures for the user.
     */
    public function labelCaptures()
    {
        return $this->hasMany(LabelCapture::class);
    }

    public function ocrResults()
    {
        return $this->hasMany(ReferenceOcrResult::class);
    }

    public function checkingSessions()
    {
        return $this->hasMany(CheckingSession::class);
    }

    public function checkCaptures()
    {
        return $this->hasMany(CheckCapture::class);
    }
}
