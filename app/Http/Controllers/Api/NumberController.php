<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NumberLookupRequest;
use App\Http\Resources\NumberLookupResource;
use App\Models\Number;

class NumberController extends Controller
{
    public function lookup(NumberLookupRequest $request)
    {
        $number = Number::where('number', $request->validated('number'))
            ->where('status', 'active')
            ->first(['id', 'number']);

        abort_unless($number, 404);

        return new NumberLookupResource($number);
    }
}
