<?php

namespace App\Traits;

use App\Service\ApiCheck;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

trait GeneralTrait {

    /**
     * Check for auth and HTTP method.
     *
     * @param array $requestMethod
     * @param Request $request
     * @return array
     */
    public function authHttpCheck(array $requestMethod, Request $request, bool $doAuthChecking = true) : array {
        $apiCheck = new ApiCheck($this->getDoctrine()->getManager());

        if ($doAuthChecking) {
            // Check call auth.
            $authCheck = $apiCheck->checkAuth($request);
            if (!$authCheck['status']) {
                // Auth check failed, return error info.
                return $authCheck;
            }
            $response['user_id'] = $authCheck['user_id'];
        }

        // Check HTTP method is accepted.
        $method = $request->getMethod();
        $methodCheck = $apiCheck->checkMethod($requestMethod, $method);
        if (!$methodCheck['status']) {
            // Method check failed, return error info.
            return $methodCheck;
        }

        $response['status'] = true;
        return $response;
    }

    /**
     * Generates a unique token.
     *
     * @return string
     */
    private function generateToken(): string {
        return Uuid::uuid4()->toString();
    }

    /**
     * Generates a unique external id.
     *
     * @param string $externalId
     * @return string
     */
    private function generateExternalId(string $externalId = null): string {
        return hash('sha256', $externalId);
    }

    /**
     * Hashes the password.
     *
     * @param string $extra
     * @return array
     */
    private function hashPassword(string $extra = null, string $dashedPattern = null, bool $getPattern = false): array {
        $string = hash('sha256', $extra);
        $token = null;
        $previousPosition = 0;
        $createdTokenPattern = [];
        $dashedPattern = $dashedPattern ? explode(',', $dashedPattern) : [];
        for ($i = 0; $i < strlen($string); $i++) {
            $randomDashedPosition = count($dashedPattern) > 0 ? (int)$dashedPattern[$i] : rand(4, 10);
            if (count($dashedPattern) > 0) {
                $previousPosition = (int)($previousPosition + $randomDashedPosition);
                $token = substr_replace($token ?? $string, '-', $previousPosition, 1);
                if ((count($dashedPattern) - 2) < $i) {
                    break;
                }
            } else if($randomDashedPosition > 3 && ($i % $randomDashedPosition) === 0) {
                $previousPosition = (int)($previousPosition + $randomDashedPosition);
                $token = substr_replace($token ?? $string, '-', $previousPosition, 1);
                $createdTokenPattern[] = $randomDashedPosition;
            }
        }

        if ($getPattern) {
            return [
                'token' => $token,
                'pattern' => implode(',', $createdTokenPattern)
            ];
        }
        return ['token' => $token];
    }

    /**
     * Validate request properties.
     *
     * @param array $requested
     * @param array $checks
     * @return array
     */
    private function validateRequest(array $requested = [], array $checks = []): array {
        if (count($requested) > 0) {
            foreach ($checks as $check) {
                if (!in_array($check, array_keys($requested))) {
                    $response['status'] = false;
                    $response['message'] = new JsonResponse((object) [
                        'errcode' => 'M_UNKNOWN',
                        'error' => "'".$check."' not in content"
                    ], 403);
                    return $response;
                }

                if ($check === 'user_id') {
                    $userid = $requested['user_id'];
                    // Isolate the username from '@username:server'.
                    $start = strpos($userid, '@');
                    $end = strpos($userid, ':', $start);
                    $username = substr($userid, ($start + 1), ($end - $start) - 1);
                    // Return an error if it is numeric.
                    if (is_numeric($username)) {
                        $response['status'] = false;
                        $response['message'] = new JsonResponse((object) [
                            'errcode' => 'M_INVALID_USERNAME',
                            'error' => 'Numeric user IDs are reserved for guest users.'
                        ], 400);
                        return $response;
                    }
                }
            }
        } else {
            $response['status'] = false;
            $response['message'] = new JsonResponse((object) [
                'errcode' => 'M_UNKNOWN',
                'error' => "'".implode(', ', $checks)."' not in content"
            ], 403);
            return $response;
        }

        return ['status' => true];
    }

    /**
     * Validates login identifier type.
     *
     * @param object $identifier
     * @return array
     */
    private function loginIdentifierType(object $identifier = null): array {
        if ($identifier->type) {
            if ($identifier->type === 'm.id.user') {
                $check = ['user'];
                $loginidentifier = ['userid' => $identifier->user];
            } else if ($identifier->type === 'm.id.thirdparty') {
                $check = ['medium', 'address'];
                $loginidentifier = [
                    'medium' => $identifier->medium,
                    'address' => $identifier->address
                ];
            } else if ($identifier->type === 'm.id.phone') {
                $check = ['country', 'phone'];
                $loginidentifier = [
                    'country' => $identifier->country,
                    'phone' => $identifier->phone
                ];
            }

            $check = $this->validateRequest((array)$identifier, $check);
            if (!$check['status']) return $check;

            $response = [
                'status' => true,
                'loginidentifier' => $loginidentifier
            ];
        } else {
            $response['status'] = false;
            $response['message'] = new JsonResponse((object) [
                'errcode' => 'M_UNKNOWN',
                'error' => 'Bad login type.'
            ], 400);
        }
        return $response;
    }
}
