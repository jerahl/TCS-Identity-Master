<?php

declare(strict_types=1);

namespace App\Controller;

use App\Import\FieldMap;
use App\Service\ReferenceService;

/**
 * Reference-data admin: school + ethnicity maps, the NextGen↔PowerSchool field
 * crosswalk, with unmapped values surfaced. Read-only in M6 (editing + RBAC in M7).
 */
final class ReferenceController extends Controller
{
    private ReferenceService $ref;

    public function __construct(?ReferenceService $ref = null)
    {
        parent::__construct();
        $this->ref = $ref ?? new ReferenceService();
    }

    private const TABS = ['schools', 'ethnicity', 'mapping'];

    public function index(): string
    {
        $tab = in_array($_GET['tab'] ?? '', self::TABS, true) ? (string) $_GET['tab'] : 'schools';

        return $this->render('reference/index', [
            'tab'            => $tab,
            'schools'        => $this->ref->schools(),
            'ethnicity'      => $this->ref->ethnicityMap(),
            'unmappedEth'    => $this->ref->unmappedEthnicity(),
            'unmappedSchool' => $this->ref->unmappedSchoolCodes(),
            'fieldMap'       => FieldMap::fields(),
            'fieldGroups'    => FieldMap::GROUPS,
        ], 'ref', 'Configuration  /  Reference data', 'Reference data — TCS Identity Master');
    }
}
