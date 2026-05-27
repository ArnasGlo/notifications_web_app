<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = ['number_id', 'contact_number_id', 'status'];
    public function number() { return $this->belongsTo(Number::class); }
    public function contactNumber() { return $this->belongsTo(Number::class, 'contact_number_id'); }
}
