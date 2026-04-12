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

            // 2. User modifications (POST = create, PATCH = update, DELETE = delete)
            if (in_array($method, ['POST', 'PATCH', 'DELETE']) && preg_match('#/api/users(/(\d+))?$#', $path, $matches)) {
                $action = 'update_user';
                if ($method === 'POST') $action = 'create_user';
                if ($method === 'DELETE') $action = 'delete_user';

                $targetId = $matches[2] ?? null;

                // For PATCH requests, detect exactly what changed
                $changes = [];
                $safeBody = $body ?? [];

                if ($method === 'PATCH' || $method === 'POST') {
                    $attributes = Arr::get($safeBody, 'data.attributes', []);
                    $relationships = Arr::get($safeBody, 'data.relationships', []);

                    if (!empty($attributes['username'])) $changes[] = 'username';
                    if (!empty($attributes['email'])) $changes[] = 'email';
                    if (!empty($attributes['displayName'])) $changes[] = 'displayName';
                    if (isset($attributes['password'])) {
                        $changes[] = 'password';
                        // Redact password from stored data
                        $safeBody['data']['attributes']['password'] = '***';
                    }
                    if (!empty($relationships['groups'])) $changes[] = 'groups';
                    if (isset($attributes['isEmailConfirmed'])) $changes[] = 'emailConfirmed';
                    if (isset($attributes['nickname'])) $changes[] = 'nickname';
                }

                // Build a human-readable target description
                // Try to read the response body to get the username
                $targetDesc = 'User ID ' . ($targetId ?? 'New');
                try {
                    $responseBody = (string) $response->getBody();
                    $responseData = json_decode($responseBody, true);
                    $userName = Arr::get($responseData, 'data.attributes.displayName') 
                             ?: Arr::get($responseData, 'data.attributes.username');
                    if ($userName) {
                        $targetDesc = "User: {$userName} (ID: " . Arr::get($responseData, 'data.id', $targetId ?? 'New') . ")";
                    }
                    // Reset the stream so Flarum can still read it
                    $response->getBody()->rewind();
                } catch (\Exception $e) {
                    // Keep the basic target description
                }

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
            }

        } catch (\Exception $e) {
            // Fail silently to avoid breaking the application sequence
        }

        return $response;
    }
}
