<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ClubSetting extends Model
{
    protected $fillable = [
        'name', 'tagline', 'logo_path', 'primary_color', 'secondary_color',
        'address', 'phone', 'email', 'website', 'facebook_url', 'instagram_url',
    ];

    public static function current(): ?self
    {
        return static::query()->first();
    }

    public function logoUrl(): string
    {
        return $this->logo_path !== null
            ? Storage::disk('public')->url($this->logo_path)
            : asset('assets/ife-logo.png');
    }

    public function hasContactInfo(): bool
    {
        return $this->address !== null || $this->phone !== null || $this->email !== null;
    }

    public function hasSocialLinks(): bool
    {
        return $this->website !== null || $this->facebook_url !== null || $this->instagram_url !== null;
    }
}
