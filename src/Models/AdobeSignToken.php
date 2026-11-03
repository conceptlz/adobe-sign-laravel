<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One stored OAuth token pair, keyed by user key. Both tokens are encrypted with APP_KEY and
 * hidden from serialisation. Used through EloquentTokenStore.
 *
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

    /** The adobe-sign.oauth.table config value. */
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
