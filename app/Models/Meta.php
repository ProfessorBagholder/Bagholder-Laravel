<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

#[Table(name: 'meta', key: 'key', keyType: 'string', incrementing: false, timestamps: false)]
#[Guarded([])]
class Meta extends Model
{
    public static function getValue(string $key, mixed $default = ''): mixed
    {
        $row = DB::table('meta')->where('key', $key)->first();
        if (! $row || $row->value === null) {
            return $default;
        }

        return $row->value;
    }

    public static function putValue(string $key, mixed $value): void
    {
        DB::table('meta')->updateOrInsert(
            ['key' => $key],
            ['value' => $value === null ? '' : (is_string($value) ? $value : json_encode($value))],
        );
    }

    public static function json(string $key, mixed $default = []): mixed
    {
        $raw = self::getValue($key, '');
        if ($raw === '' || $raw === null) {
            return $default;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : $default;
    }
}
