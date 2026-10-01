<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdministrativeWard extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $fillable = ['code', 'province_code', 'name'];
}
