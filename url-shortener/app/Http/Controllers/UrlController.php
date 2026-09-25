<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUrlRequest;
use App\Http\Requests\UpdateUrlRequest;
use App\Models\Click;
use App\Models\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UrlController extends Controller
{
    public function index()
    {
        $perPage = min((int) request()->integer('per_page', 15), 50);

        $urls = request()->user()
            ->urls()
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->latest()
            ->paginate($perPage, [
                'long_url',
                'code',
                'click_count',
                'expires_at',
            ]);

        return response()->json($urls);
    }

    public function store(StoreUrlRequest $request)
    {
        do {
            $code = Str::random(6);
        } while (Url::where('code', $code)->exists());

        $url = Url::create([
            'user_id' => $request->user()->id,
            'long_url' => $request->long_url,
            'code' => $code,
            'expires_at' => $request->expires_at,
        ]);

        return response()->json([
            'message' => 'URL created successfully',
            'data' => [
                'long_url' => $url->long_url,
                'code' => $url->code,
                'expires_at' => $url->expires_at,
            ],
        ], 201);
    }

    public function update(UpdateUrlRequest $request, string $code)
    {
        $url = $request->user()->
            urls()->where('code', $code)->first();

        if (! $url) {
            return response()->json(['message' => 'URL not found'], 404);
        }

        $url->update($request->validated());

        return response()->json([
            'message' => 'URL updated successfully',
            'data' => [
                'long_url' => $url->long_url,
                'code' => $url->code,
                'expires_at' => $url->expires_at,
            ],
        ]);
    }

    public function redirect(string $code)
    {
        $url = Url::where('code', $code)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($url) {
            Click::create([
                'url_id' => $url->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
            $url->increment('click_count');

            return redirect($url->long_url);
        }

        return abort(404);
    }

    public function show(Request $request, string $code)
    {
        $url = $request->user()
            ->urls()
            ->where('code', $code)
            ->first();

        if (! $url) {
            return response()->json(['message' => 'URL not found'], 404);
        }

        return response()->json([
            'long_url' => $url->long_url,
            'code' => $url->code,
            'expires_at' => $url->expires_at,
            'is_expired' => $url->expires_at?->isPast() ?? false,
            'click_count' => $url->click_count,
        ]);
    }

    public function destroy(Request $request, string $code)
    {
        $url = $request->user()
            ->urls()->where('code', $code)
            ->first();

        if (! $url) {
            return response()->json(['message' => 'URL not found'], 404);
        }

        $url->delete();

        return response()->noContent();
    }

    public function stats(Request $request, string $code)
    {
        $url = $request->user()
            ->urls()
            ->where('code', $code)
            ->first();

        if (! $url) {

            return response()->json(['message' => 'URL not found'], 404);
        }

        return response()->json([
            'code' => $url->code,
            'long_url' => $url->long_url,
            'click_count' => $url->click_count,
            'clicks' => $url->clicks()->get(['ip_address', 'user_agent', 'created_at']),
            'expires_at' => $url->expires_at,
        ]);
    }
}
