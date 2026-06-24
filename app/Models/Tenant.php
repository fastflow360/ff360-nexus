<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

#[Fillable(['id', 'name', 'ip', 'rate_limit'])]
class Tenant extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = [
        'rate_limit' => 0,
    ];

    protected function serializeDate(DateTimeInterface $date): string
    {
        return Carbon::instance($date)
            ->setTimezone((string) config('app.timezone'))
            ->format(DateTimeInterface::ATOM);
    }
}
