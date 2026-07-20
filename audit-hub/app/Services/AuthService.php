<?php

namespace App\Services;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Hash;

class AuthService {

    public function register(array $data){

        return DB::transaction(function () use ($data) {

            if (User::where('email', strtolower($data['email']))->exists()) {
                throw new \Exception('Email already exists.');
            }

            $slug = Str::slug($data['organization_name']);

            if (Tenant::where('slug', $slug)->exists()) {
                $slug .= '-' . Str::random(5);
            }

            $tenant = Tenant::create([
                'uuid'            => Str::uuid()->toString(),
                'name'            => $data['organization_name'],
                'slug'            => $slug,
                'status'          => 'trial',
                'timezone'        => 'UTC',
                'data_region'     => 'us',
                'retention_days'  => 365,
                'billing_email'   => strtolower($data['email']),
            ]);

             $user = User::create([
                'tenant_id' => $tenant->id,
                'name'      => $data['name'],
                'email'     => strtolower($data['email']),
                'password'  => $data['password'], // hashed by model cast
                'role'      => 'owner',
                'status'    => 'active',
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return [
                'status' => true,
                'message' => 'Registration successful.',
                'data' => [
                    'tenant' => $tenant,
                    'user' => $user,
                    'token' => $token,
                ],
            ];
        });
    }
}
