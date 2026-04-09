<?php

namespace Bendary\AdminAudit\Middlewares;

use Bendary\AdminAudit\AuditLog;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuditPermissionMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $path = $request->getUri()->getPath();
        $isPermissionEndpoint = strpos($path, '/api/permission') !== false || strpos($path, '/permission') !== false;

        // Ensure it's a POST request to the permission endpoint and is successful
        if ($request->getMethod() === 'POST' && $isPermissionEndpoint) {
            if ($response->getStatusCode() === 204 || $response->getStatusCode() === 200) {
                try {
                    $actor = RequestUtil::getActor($request);
                    if ($actor->isAdmin()) {
                        $body = $request->getParsedBody();
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
                            Arr::get($request->getServerParams(), 'REMOTE_ADDR')
                        );
                        $audit->save();
                    }
                } catch (\Exception $e) {
                    // Fail silently to avoid breaking the application flow for a non-critical audit log
                }
            }
        }

        return $response;
    }
}
