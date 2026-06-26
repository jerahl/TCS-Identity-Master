<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuditService;

/**
 * Audit-log viewer (admin only — holds before/after PII and login records).
 * Filter by entity/action/actor; paginated.
 */
final class AuditController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): string
    {
        $filters = [
            'entity' => (string) ($_GET['entity'] ?? 'all'),
            'action' => (string) ($_GET['action'] ?? 'all'),
            'actor'  => trim((string) ($_GET['actor'] ?? '')),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $audit = new AuditService();
        $total = $audit->count($filters);
        $rows = $audit->list($filters, self::PER_PAGE, $offset);

        return $this->render('audit/index', [
            'rows'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'perPage' => self::PER_PAGE,
            'pages'   => max(1, (int) ceil($total / self::PER_PAGE)),
            'filters' => $filters,
        ], 'audit', 'Administration  /  Audit log', 'Audit log — TCS Identity Master');
    }
}
