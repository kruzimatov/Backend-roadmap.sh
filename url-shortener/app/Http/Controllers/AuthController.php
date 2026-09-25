<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated()); // new user creation
        $token = $user->createToken('auth_token')->plainTextToken; // token create first

        return response()->json([
            'user' => $user->only('id', 'name', 'email'), // return only needed fields
            'token' => $token, // and token
        ], 201); // 201 for created successfully
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated(); // validate take credentials
        $user = User::where('email', $credentials['email'])->first(); // check the db for email

        if (! $user || ! Hash::check($credentials['password'], $user->password)) { // check password
            return response()->json([
                'message' => 'The provided credentials do not match our records.',
            ], 401); // if not, return not found
        }

        return response()->json([ // if found return data
            'user' => $user->only('id', 'name', 'email'),
            'token' => $user->createToken('auth_token')->plainTextToken,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete(); // remove current token

        return response()->json([
            'message' => 'Logged out successfully.', // give response
        ]);
    }
}
