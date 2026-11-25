<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:120',
            'email'    => 'required|email|max:190|unique:users,email',
            'phone'    => 'nullable|string|max:20',
            'city'     => 'nullable|string|max:100',
            'password' => 'required|string|min:6',
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'phone'    => $data['phone'] ?? null,
            'city'     => $data['city'] ?? null,
            'password' => Hash::make($data['password']),
            'role'     => 'user',
        ]);

        $token = $user->createToken('siaganusa-mobile')->plainTextToken;

        return response()->json([
            'status' => true,
            'user'   => $user,
            'token'  => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'status'  => false,
                'message' => 'Email atau password salah',
            ], 401);
        }

        $token = $user->createToken('siaganusa-mobile')->plainTextToken;

        return response()->json([
            'status' => true,
            'user'   => $user,
            'token'  => $token,
        ]);
    }

    public function profile(Request $request)
    {
        return response()->json([
            'status' => true,
            'data'   => $request->user(),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Berhasil logout',
        ]);
    }
}