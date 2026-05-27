<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delegate extends Model
{
    protected $table = 'number_delegates';

    protected $fillable = ['number_id', 'assistant_user_id'];

    public function number()
    {
        return $this->belongsTo(Number::class);
    }

    public function assistant()
    {
        return $this->belongsTo(User::class, 'assistant_user_id');
    }
}
