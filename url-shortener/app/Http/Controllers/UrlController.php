<?php

namespace App\Http\Controllers;

use App\Models\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Http\Requests\StoreUrlRequest;

class UrlController extends Controller
{
    public function store(StoreUrlRequest $request)
    {
        do {
            $code = Str::random(6);
        } while (Url::where('code', $code)->exists());
        Url::create([
            'long_url' => $request->long_url,
            'code' => $code,
        ]);

        return response()->json([
            'message'=>'The link has valid syntax and a live domain',
            'long_url' => $request->long_url,
            'code' => $code,
        ], 201);
    }

    public function redirect(string $code)
    {
        $url = Url::where('code', $code)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if($url){
            $url->increment('click_count');
            return redirect($url->long_url);
        }


        return abort(404);
    }
}
