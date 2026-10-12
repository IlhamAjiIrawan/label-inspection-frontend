<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /**
     * NIK-only login — tanpa password (simplified access).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'nik' => ['required', 'string', 'size:9', 'regex:/^[0-9]{9}$/'],
        ], [
            'nik.required' => 'Employee NIK is required.',
            'nik.size'     => 'Employee NIK must be 9 digits.',
            'nik.regex'    => 'Employee NIK must contain only numbers (0-9).',
        ]);

        $user = User::where('nik', $credentials['nik'])->first();

        // Generic anti-enumeration message
        if (! $user) {
            return response()->json(['message' => 'Employee NIK not registered'], 401);
        }

        $user->tokens()->delete();

        $token = $user->createToken('login', ['*'], now()->addMinutes(60))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'       => $user->id,
                'name'     => $user->name,
                'nik'      => $user->nik,
                'username' => $user->username,
            ],
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }
}