<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AuthService;


class AuthController extends Controller{

    public function register(Request $request) {
        $meta = [
            'correlation_id' => 'req_' . uniqid(),
            'timestamp'      => now()->toISOString(),
        ];

        try {

            $validated = $request->validate([
                'organization_name' => 'required|string|max:150',
                'name'              => 'required|string|max:150',
                'email'             => 'required|email',
                'password'          => 'required|string|min:8',
            ]);

            $authService = new AuthService();

            $response = $authService->register($validated);

            $response['meta'] = $meta;

            return response()->json($response, 201);

        } catch (\Exception $e) {

            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
                'errors'  => [],
                'meta'    => $meta,
            ], 500);

        }

    }
    
}
