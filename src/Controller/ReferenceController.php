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

    /**
     * Interactive data-flow chart (sources → IDM → OneSync → destinations) —
     * a self-contained standalone page, served behind the auth gate like every
     * other route. Its diagram runtime evaluates the chart definition with
     * `new Function`, which the site-wide CSP (script-src 'self') forbids, so
     * this response replaces the CSP with one that adds 'unsafe-eval' — scoped
     * to this page only; everything else (no inline/external script, no remote
     * fonts) stays as strict as the global policy. Keep in lockstep with the
     * nginx CSP in scripts/harden-debian12.sh (the browser enforces both).
     */
    public function dataflow(): string
    {
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "font-src 'self'; "
            . "img-src 'self' data:; "
            . "script-src 'self' 'unsafe-eval'; "
            . "object-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'"
        );

        $file = dirname(__DIR__, 2) . '/templates/reference/dataflow.html';
        $html = @file_get_contents($file);
        if ($html === false) {
            http_response_code(404);
            return 'Data-flow page not installed (templates/reference/dataflow.html missing).';
        }
        return $html;
    }
}
