<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ReferenceService;

/**
 * Reference-data admin: school + ethnicity maps, with unmapped values surfaced.
 * Read-only in M6 (editing + RBAC in M7).
 */
final class ReferenceController extends Controller
{
    private ReferenceService $ref;

    public function __construct(?ReferenceService $ref = null)
    {
        parent::__construct();
        $this->ref = $ref ?? new ReferenceService();
    }

    public function index(): string
    {
        $tab = ($_GET['tab'] ?? 'schools') === 'ethnicity' ? 'ethnicity' : 'schools';

        return $this->render('reference/index', [
            'tab'            => $tab,
            'schools'        => $this->ref->schools(),
            'ethnicity'      => $this->ref->ethnicityMap(),
            'unmappedEth'    => $this->ref->unmappedEthnicity(),
            'unmappedSchool' => $this->ref->unmappedSchoolCodes(),
        ], 'ref', 'Configuration  /  Reference data', 'Reference data — TCS Identity Master');
    }
}
