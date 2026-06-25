<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * People list + person detail (read-only in Milestone 2).
 */
final class PersonController extends Controller
{
    public function index(): string
    {
        $filters = [
            'status'  => (string) ($_GET['status'] ?? 'all'),
            'type'    => (string) ($_GET['type'] ?? 'all'),
            'school'  => (string) ($_GET['school'] ?? 'all'),
            'missing' => isset($_GET['missing']) && $_GET['missing'] !== '0',
            'pending' => isset($_GET['pending']) && $_GET['pending'] !== '0',
            'q'       => (string) ($_GET['q'] ?? ''),
        ];

        $result = $this->people->list($filters);

        return $this->render('people/index', [
            'people'        => $result['rows'],
            'shown'         => count($result['rows']),
            'total'         => $result['total'],
            'filters'       => $filters,
            'schoolOptions' => $this->people->schoolFilterOptions(),
        ], 'people', 'People', 'People — TCS Identity Master');
    }

    public function show(array $params): string
    {
        $id = (int) ($params['id'] ?? 0);
        $person = $id > 0 ? $this->people->find($id) : null;

        if ($person === null) {
            http_response_code(404);
            return $this->render('pages/not_found', [
                'message' => 'No person with that id.',
            ], 'people', 'People  /  Not found', 'Not found — TCS Identity Master');
        }

        return $this->render('people/show', [
            'p'          => $person,
            'sourceIds'  => $this->people->sourceIds($id),
            'assignments' => $this->people->assignments($id),
            'syncStatus' => $this->people->syncStatus($id),
            'timeline'   => $this->people->timeline($id),
        ], 'people', 'People  /  Record', 'Person record — TCS Identity Master');
    }
}
