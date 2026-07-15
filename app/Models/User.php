<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use App\Traits\Auditable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasRoles, HasFactory, Notifiable, HasApiTokens, Auditable;

    public function getActivitylogOptions(): \Spatie\Activitylog\Support\LogOptions
    {
        return \Spatie\Activitylog\Support\LogOptions::defaults()
            ->logOnly(['name', 'status', 'email'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('User');
    }

    protected $fillable = [
        'name',
        'status',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    // protected static function booted()
    // {
    //     static::creating(function ($user) {
    //         if (empty($user->password)) {
    //             $user->password = Hash::make('password');
    //         }
    //     });
    // }
}
