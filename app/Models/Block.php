<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Block extends Model
{
    protected $fillable = ['number_id', 'type', 'value'];
    public function number() { return $this->belongsTo(Number::class); }
}
