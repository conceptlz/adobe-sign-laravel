<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $access_token
 * @property string|null $refresh_token
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string|null $api_access_point
 */
class AdobeSignToken extends Model
{
    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token'];

    public function getTable(): string
    {
        return config('adobe-sign.oauth.table', 'adobe_sign_tokens');
    }

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }
}
