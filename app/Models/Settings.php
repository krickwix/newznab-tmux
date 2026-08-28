<?php

declare(strict_types=1);

/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program (see LICENSE.txt in the base directory.  If
 * not, see:.
 *
 * @link      <http://www.gnu.org/licenses/>.
 *
 * @author    niel
 * @author    DariusIII
 * @copyright 2016 nZEDb, 2017 NNTmux
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Settings - model for settings table.
 *
 * @property string $name
 * @property string $value
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Settings whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Settings whereValue($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Settings newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Settings newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Settings query()
 */
class Settings extends Model
{
    public const REGISTER_STATUS_OPEN = 0;

    public const REGISTER_STATUS_INVITE = 1;

    public const REGISTER_STATUS_CLOSED = 2;

    public const ERR_BADUNRARPATH = -1;

    public const ERR_BADFFMPEGPATH = -2;

    public const ERR_BADMEDIAINFOPATH = -3;

    public const ERR_BADNZBPATH = -4;

    public const ERR_DEEPNOUNRAR = -5;

    public const ERR_BADTMPUNRARPATH = -6;

    public const ERR_BADLAMEPATH = -11;

    public const ERR_SABCOMPLETEPATH = -12;

    /**
     * @var string
     */
    protected $primaryKey = 'name';

    /**
     * @var string
     */
    protected $keyType = 'string';

    protected $dateFormat = false;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @var Collection<int, mixed>
     */
    protected static ?\Illuminate\Support\Collection $settingsCollection = null; // @phpstan-ignore generics.notGeneric, property.phpDocType, missingType.generics

    /**
     * When the memoised copy of the settings table was loaded, as a microtime float.
     */
    protected static ?float $settingsLoadedAt = null;

    /**
     * How long a memoised copy of the settings table may be reused, in seconds.
     *
     * A web request lives for milliseconds, so it always gets a single load. The TTL only
     * bounds staleness in long-running CLI processes (Horizon workers, nntmux:worker), which
     * would otherwise hold the first copy they ever read for the life of the process --
     * forgetCachedSettings() only clears the memo inside the process that calls it.
     */
    protected const SETTINGS_MEMO_TTL = 30;

    /**
     * Guards against re-entering the bulk load while it is already running.
     */
    protected static bool $loadingSettings = false;

    /**
     * The whole settings table as a name => raw value map, loaded at most once per request.
     *
     * Every read of a setting used to issue its own `where name = ? limit 1` query. There are
     * over 200 settingValue() call sites, so a single page ran 204 of them: cheap individually,
     * but each one pays a database round trip, which is what made the site slow.
     *
     * toBase() is load bearing. Eloquent's pluck() hydrates a model per row and reads its
     * attributes, which lands back in the __get() override below -- so an Eloquent-level bulk
     * read of this table costs one query per row, the very thing this method exists to avoid.
     * The base query builder returns raw column values and never calls __get().
     *
     * Raw values are also what callers already expected: `->value('value')` and the `value`
     * accessor both hand back the column, so they get exactly the same thing once
     * convertValue() is applied.
     *
     * Returns null when the map is not usable, which means the caller must fall back to the
     * single key query it used before.
     *
     * @return \Illuminate\Support\Collection<string, string|null>|null
     */
    protected static function settingsMap(): ?\Illuminate\Support\Collection
    {
        $now = microtime(true);

        if (self::$settingsCollection !== null
            && self::$settingsLoadedAt !== null
            && ($now - self::$settingsLoadedAt) < self::SETTINGS_MEMO_TTL) {
            return self::$settingsCollection;
        }

        // Re-entered while the map is still loading, because a global scope, model event or
        // observer read a setting during the pluck() below. Report "no map" so the caller falls
        // back to a single key query -- exactly what it did before -- rather than recursing
        // until the process dies.
        if (self::$loadingSettings) {
            return null;
        }

        self::$loadingSettings = true;

        try {
            self::$settingsCollection = self::query()->toBase()->pluck('value', 'name');
            self::$settingsLoadedAt = $now;
        } finally {
            self::$loadingSettings = false;
        }

        return self::$settingsCollection;
    }

    /**
     * Get the value attribute and convert empty strings to null and numeric strings to numbers.
     *
     * @param  string  $value
     */
    public function getValueAttribute($value): mixed
    {
        return self::convertValue($value);
    }

    /**
     * Adapted from https://laravel.io/forum/01-15-2016-overriding-eloquent-attributes.
     *
     * @return mixed
     */
    public function __get($key)
    {
        $settings = self::settingsMap();

        if ($settings === null) {
            $override = self::query()->where('name', $key)->first();

            if ($override && ! $this->hasGetMutator($key)) {
                return $override->value;
            }

            return parent::__get($key);
        }

        // has(), not a null check on the value: the old code tested whether a row existed, and
        // a row whose value is NULL is still an override. Reading the value would drop those
        // through to parent::__get() instead.
        //
        // If there's an override and no mutator has been explicitly defined on
        // the model then use the override value
        if ($settings->has($key) && ! $this->hasGetMutator($key)) {
            // The old code returned $override->value, which ran the value accessor.
            return self::convertValue($settings->get($key));
        }

        // If the attribute is not overridden the use the usual __get() magic method
        return parent::__get($key);
    }

    /**
     * Return a simple key-value array of all settings.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public static function toTree(bool $excludeUnsectioned = true): array
    {
        // toBase() for the same reason as settingsMap(): the model has a `value` accessor, so an
        // Eloquent pluck() maps every row through a model instance, which lands in the __get()
        // override and turns one bulk read into one query per row. The explicit convertValue()
        // keeps the converted values the accessor used to return.
        $results = self::query()
            ->toBase()
            ->pluck('value', 'name')
            ->map(fn ($value) => self::convertValue($value))
            ->toArray();

        if (empty($results)) {
            throw new \RuntimeException(
                'No results from Settings table! Check your table has been created and populated.'
            );
        }

        return $results;
    }

    public static function settingValue(mixed $setting): mixed
    {
        try {
            $map = self::settingsMap();

            $value = $map !== null
                ? $map->get((string) $setting)
                : self::query()->where('name', $setting)->value('value');
        } catch (QueryException $e) {
            if (self::isDatabaseOptionalCli()) {
                return null;
            }

            throw $e;
        }

        // Apply the same conversion logic as the accessor
        return self::convertValue($value);
    }

    private static function isDatabaseOptionalCli(): bool
    {
        if (PHP_SAPI !== 'cli') {
            return false;
        }

        $argv = $_SERVER['argv'] ?? [];
        if (! is_array($argv)) {
            return false;
        }

        return in_array('package:discover', $argv, true)
            || (in_array('nntmux:worker', $argv, true) && in_array('--list', $argv, true));
    }

    /**
     * Convert setting value: numeric strings to numbers, preserve empty strings.
     *
     * @param  string|null  $value
     */
    public static function convertValue($value): mixed
    {
        // Handle null
        if ($value === null) {
            return null;
        }

        // Keep empty strings as empty strings (don't convert to null)
        // Many settings expect empty strings, not null
        if ($value === '') {
            return '';
        }

        // Convert numeric strings to actual numbers
        if (is_numeric($value)) {
            // Check if it's an integer or float
            if (strpos((string) $value, '.') !== false) {
                return (float) $value;
            }

            return (int) $value;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function settingsUpdate(array $data = []): void
    {
        foreach ($data as $key => $value) {
            self::query()->where('name', $key)->update(['value' => \is_array($value) ? implode(', ', $value) : $value]);
        }

        self::forgetCachedSettings();
    }

    public static function forgetCachedSettings(): void
    {
        Cache::forget('site_settings');
        Cache::forget('site_settings_array');
        Cache::forget('site_settings_converted');
        Cache::forget('api_v1_server_menu');
        Cache::forget('api_v2_capabilities');

        self::$settingsCollection = null;
        self::$settingsLoadedAt = null;
    }
}
