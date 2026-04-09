<?php

namespace Bendary\AdminAudit\Controllers;

use Bendary\AdminAudit\AuditLog;
use Bendary\AdminAudit\Serializers\AuditLogSerializer;
use Flarum\Api\Controller\AbstractListController;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class ListAuditLogsController extends AbstractListController
{
    public $serializer = AuditLogSerializer::class;

    public $include = ['user'];

    public $sortFields = ['createdAt'];

    public $sort = ['createdAt' => 'desc'];

    protected $url;

    public function __construct(UrlGenerator $url)
    {
        $this->url = $url;
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $filters = $this->extractFilter($request);
        $sort = $this->extractSort($request);

        $limit = $this->extractLimit($request);
        $offset = $this->extractOffset($request);

        $query = AuditLog::query();

        // Apply filters
        if (isset($filters['user'])) {
            $query->where('user_id', $filters['user']);
        }
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (isset($filters['q'])) {
            $search = '%' . $filters['q'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', $search)
                  ->orWhere('target', 'like', $search);
            });
        }
        if (isset($filters['dateRange'])) {
            $dates = explode(',', $filters['dateRange']);
            if (count($dates) == 2) {
                $query->whereBetween('created_at', [$dates[0], $dates[1]]);
            }
        }

        foreach ((array) $sort as $field => $order) {
            $query->orderBy(\Illuminate\Support\Str::snake($field), $order);
        }

        $totalCount = $query->count();

        $query->skip($offset)->take($limit);

        $results = $query->get();

        $document->addPaginationLinks(
            $this->url->to('api')->route('admin-audit.index'),
            $request->getQueryParams(),
            $offset,
            $limit,
            $results->count() ? null : 0
        );

        $document->addMeta('total', $totalCount);

        return $results;
    }
}
