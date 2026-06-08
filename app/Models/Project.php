<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'path',
        'git_remote',
        'default_branch',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    public function commandWhitelist(): HasMany
    {
        return $this->hasMany(CommandWhitelist::class);
    }
}
