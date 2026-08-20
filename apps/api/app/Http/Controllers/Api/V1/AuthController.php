<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $throttleKey = mb_strtolower($request->input('email')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        /** @var User|null $user */
        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            $this->audit->logSystem(AuditEventType::LOGIN_FAILED, null, [
                'email' => $request->input('email'),
            ], $user?->organization_id);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account is not active.']);
        }

        RateLimiter::clear($throttleKey);

        $token = $user->createToken(
            $request->input('device_name', 'api'),
            ['role:'.$user->role->value]
        );

        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->log(AuditEventType::LOGIN, $user, ['device' => $request->input('device_name')], $user);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($user->load('organization')),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('organization'));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->log(AuditEventType::LOGOUT, $request->user(), [], $request->user());
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }
}
