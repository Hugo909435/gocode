<?php

namespace App\Services;

use App\Models\Setting;

class SettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        $setting = Setting::find($key);

        return $setting ? $setting->value : $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public function getEncrypted(string $key, mixed $default = null): mixed
    {
        $encrypted = $this->get($key);

        if ($encrypted === null) {
            return $default;
        }

        try {
            return decrypt($encrypted);
        } catch (\Exception) {
            return $default;
        }
    }

    public function setEncrypted(string $key, mixed $value): void
    {
        $this->set($key, encrypt($value));
    }
}
