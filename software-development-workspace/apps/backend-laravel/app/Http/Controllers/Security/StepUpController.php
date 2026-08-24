<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Services\Security\StepUpVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Requesting and redeeming a step-up code. Rate-limited at the route level —
 * this is exactly the kind of endpoint a script would hammer to spam
 * somebody's inbox or grind through six-digit codes.
 */
class StepUpController extends Controller
{
    private const PURPOSES = ['payroll'];

    public function __construct(private readonly StepUpVerification $service) {}

    public function status(Request $request): JsonResponse
    {
        $purpose = $this->purpose($request);

        return response()->json([
            'elevated'       => $this->service->isElevated($request->user(), $purpose),
            'elevated_until' => $this->service->elevatedUntil($request->user(), $purpose)?->toISOString(),
        ]);
    }

    public function requestCode(Request $request): JsonResponse
    {
        $purpose = $this->purpose($request);

        $this->service->requestCode($request->user(), $purpose, $request->ip(), $request->userAgent());

        // Deliberately vague about where it went beyond "your email" — the
        // response is visible to anyone who can reach this endpoint while
        // signed in as this user, and the address itself is not theirs to
        // confirm from here.
        return response()->json(['message' => 'A verification code has been sent to your email.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $purpose = $this->purpose($request);

        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);

        $result = $this->service->verify($request->user(), $purpose, $data['code']);

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json(['elevated_until' => $result['elevated_until']->toISOString()]);
    }

    private function purpose(Request $request): string
    {
        $purpose = $request->string('purpose')->toString();

        abort_unless(in_array($purpose, self::PURPOSES, true), 422, 'Unrecognised purpose.');

        return $purpose;
    }
}
