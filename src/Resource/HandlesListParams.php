<?php

declare(strict_types=1);

namespace SilverShop\Api\Resource;

use SilverShop\Api\ApiException;
use SilverShop\Api\Control\ApiController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataList;

/**
 * Shared `start`/`limit` pagination, `filter[...]` filtering and `sort` ordering for list resources. Pagination
 * metadata is returned as response headers (`X-Total-Count`, `X-Page-Start`, `X-Page-Limit`, `X-Total-Pages`) so the
 * JSON/XML body stays a bare collection — backwards-compatible with core's `api/v1/products`.
 */
trait HandlesListParams
{
    /**
     * Apply `start`/`limit` and return the page plus the headers describing it.
     *
     * @return array{list: DataList, start: int, limit: int, total: int, headers: array<string, string>}
     */
    protected function paginate(DataList $list, HTTPRequest $request): array
    {
        $config = Config::forClass(ApiController::class);
        $default = (int) $config->get('default_page_size');
        $max = (int) $config->get('max_page_size');

        $total = $list->count();

        $limit = (int) ($request->getVar('limit') ?? $default);
        if ($limit <= 0) {
            $limit = $default;
        }
        $limit = min($limit, $max);

        $start = max(0, (int) ($request->getVar('start') ?? 0));

        $page = $list->limit($limit, $start);

        $headers = [
            'X-Total-Count' => (string) $total,
            'X-Page-Start' => (string) $start,
            'X-Page-Limit' => (string) $limit,
            'X-Total-Pages' => (string) ($limit > 0 ? (int) ceil($total / $limit) : 0),
        ];

        return ['list' => $page, 'start' => $start, 'limit' => $limit, 'total' => $total, 'headers' => $headers];
    }

    /**
     * Apply `filter[Field]=value` for the allowed fields. Allowlist maps a field name to a match modifier
     * (`PartialMatch`, `ExactMatch`, `GreaterThanOrEqual`, …).
     *
     * @param array<string, string> $allowed
     */
    protected function applyFilter(DataList $list, HTTPRequest $request, array $allowed): DataList
    {
        $filters = $request->getVar('filter');
        if (!is_array($filters)) {
            return $list;
        }

        foreach ($filters as $field => $value) {
            if (!is_string($field) || !isset($allowed[$field]) || is_array($value)) {
                continue;
            }

            $modifier = $allowed[$field];
            $key = $modifier === 'ExactMatch' ? $field : $field . ':' . $modifier;
            $list = $list->filter([$key => $value]);
        }

        return $list;
    }

    /**
     * Apply `sort=Field` / `sort=-Field` (leading `-` = descending), comma-separated for multiple keys, restricted
     * to the allowed fields.
     *
     * @param array<int, string> $allowed
     */
    protected function applySort(DataList $list, HTTPRequest $request, array $allowed): DataList
    {
        $sort = trim((string) $request->getVar('sort'));
        if ($sort === '') {
            return $list;
        }

        $order = [];
        foreach (explode(',', $sort) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $direction = 'ASC';
            if (str_starts_with($segment, '-')) {
                $direction = 'DESC';
                $segment = substr($segment, 1);
            }
            if (!in_array($segment, $allowed, true)) {
                throw ApiException::badRequest(sprintf('Cannot sort by "%s"', $segment));
            }
            $order[$segment] = $direction;
        }

        return $order === [] ? $list : $list->sort($order);
    }
}
