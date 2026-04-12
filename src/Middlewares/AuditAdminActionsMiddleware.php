<?php

namespace Bendary\AdminAudit\Middlewares;

use Bendary\AdminAudit\AuditLog;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuditAdminActionsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $statusCode = $response->getStatusCode();

        // Ensure it's a successful modifying operation
        if (!in_array($statusCode, [200, 201, 204])) {
            return $response;
        }

        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        
        try {
            $actor = RequestUtil::getActor($request);
            if (!$actor || !$actor->isAdmin()) {
                return $response;
            }

            $body = $request->getParsedBody();
            $ip = Arr::get($request->getServerParams(), 'REMOTE_ADDR');

            // If parsedBody is null, try reading raw JSON body
            if ($body === null) {
                $rawBody = (string) $request->getBody();
                $body = json_decode($rawBody, true);
                try { $request->getBody()->rewind(); } catch (\Exception $e) {}
            }

            // ===== DEBUG LOG (temporary) =====
            // This will help us understand exactly what the middleware sees
            $debugFile = sys_get_temp_dir() . '/flarum_audit_debug.log';
            $debugEntry = date('Y-m-d H:i:s') . " | Method: {$method} | Path: {$path} | Status: {$statusCode} | BodyType: " . gettype($body) . " | BodyKeys: " . (is_array($body) ? implode(',', array_keys($body)) : 'N/A') . "\n";
            file_put_contents($debugFile, $debugEntry, FILE_APPEND);
            // ===== END DEBUG LOG =====

            // 1. Permissions Log
            if ($method === 'POST' && (str_contains($path, '/api/permission') || str_contains($path, '/permission'))) {
                $permission = Arr::get($body, 'permission');
                $groupIds = Arr::get($body, 'groupIds', []);

                $audit = AuditLog::build(
                    $actor->id,
                    'permissions',
                    'update_permission',
                    $permission,
                    null,
                    ['group_ids' => $groupIds],
                    null,
                    $ip
                );
                $audit->save();
            }

            // 2. User modifications - use str_contains like permissions (proven working)
            if (in_array($method, ['POST', 'PATCH', 'DELETE']) && str_contains($path, '/api/users')) {
                // Avoid intercepting our own audit log API or user listing GETs
                // Only log if we're not hitting a sub-resource like /api/users/X/avatar
                $isUserRoute = preg_match('#/api/users(/\d+)?$#', $path) || preg_match('#/api/users$#', $path);
                
                if ($isUserRoute) {
                    $action = 'update_user';
                    if ($method === 'POST') $action = 'create_user';
                    if ($method === 'DELETE') $action = 'delete_user';

                    // Extract target user ID from path
                    $targetId = null;
                    if (preg_match('#/api/users/(\d+)#', $path, $userMatches)) {
                        $targetId = $userMatches[1];
                    }

                    // Parse changes from body
                    $changes = [];
                    $safeBody = $body ?? [];
                    $attributes = Arr::get($safeBody, 'data.attributes', []);
                    $relationships = Arr::get($safeBody, 'data.relationships', []);

                    if (!empty($attributes['username'])) $changes[] = 'username';
                    if (!empty($attributes['email'])) $changes[] = 'email';
                    if (!empty($attributes['displayName'])) $changes[] = 'displayName';
                    if (isset($attributes['password'])) {
                        $changes[] = 'password';
                        if (isset($safeBody['data']['attributes']['password'])) {
                            $safeBody['data']['attributes']['password'] = '***';
                        }
                    }
                    if (!empty($relationships['groups'])) $changes[] = 'groups';
                    if (isset($attributes['isEmailConfirmed'])) $changes[] = 'emailConfirmed';
                    if (isset($attributes['nickname'])) $changes[] = 'nickname';

                    // Build target description from response
                    $targetDesc = 'User ID ' . ($targetId ?? 'New');
                    try {
                        $responseBody = (string) $response->getBody();
                        $responseData = json_decode($responseBody, true);
                        $userName = Arr::get($responseData, 'data.attributes.displayName') 
                                 ?: Arr::get($responseData, 'data.attributes.username');
                        if ($userName) {
                            $targetDesc = "User: {$userName} (ID: " . Arr::get($responseData, 'data.id', $targetId ?? 'New') . ")";
                        }
                        $response->getBody()->rewind();
                    } catch (\Exception $e) {}

                    $meta = !empty($changes) ? ['modified_fields' => $changes] : null;

                    $audit = AuditLog::build(
                        $actor->id,
                        'users',
                        $action,
                        $targetDesc,
                        null,
                        $safeBody,
                        $meta,
                        $ip
                    );
                    $audit->save();

                    // Debug confirmation
                    file_put_contents($debugFile, "  >> USER ACTION LOGGED: {$action} | Target: {$targetDesc} | Changes: " . implode(',', $changes) . "\n", FILE_APPEND);
                }
            }

        } catch (\Exception $e) {
            // Log the exception for debugging
            $debugFile = sys_get_temp_dir() . '/flarum_audit_debug.log';
            file_put_contents($debugFile, date('Y-m-d H:i:s') . " | EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
        }

        return $response;
    }
}
