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

            // 2. Users Log
            if (preg_match('/\/api\/users(\/[0-9]+)?$/', $path)) {
                if (in_array($method, ['POST', 'PATCH', 'DELETE'])) {
                    $action = 'update_user';
                    if ($method === 'POST') $action = 'create_user';
                    if ($method === 'DELETE') $action = 'delete_user';

                    $targetId = null;
                    if (preg_match('/\/api\/users\/([0-9]+)$/', $path, $matches)) {
                        $targetId = $matches[1];
                    }
                    
                    $targetDesc = 'User ID ' . ($targetId ?? 'New');

                    // Filter out sensitive fields
                    $safeBody = $body;
                    if (isset($safeBody['data']['attributes']['password'])) {
                        $safeBody['data']['attributes']['password'] = '***';
                    }

                    $audit = AuditLog::build(
                        $actor->id,
                        'users',
                        $action,
                        $targetDesc,
                        null,
                        $safeBody, // Store the dispatched edits cleanly
                        null,
                        $ip
                    );
                    $audit->save();
                }
            }

        } catch (\Exception $e) {
            // Fail silently to avoid breaking the application sequence
        }

        return $response;
    }
}
