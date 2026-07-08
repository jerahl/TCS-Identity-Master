<?php

declare(strict_types=1);

namespace App\Controller;

use App\Import\FieldMap;
use App\Service\AuditService;
use App\Service\ReferenceService;
use App\Support\Csrf;

/**
 * Reference-data admin: school, ethnicity and position (job code → person type)
 * maps, the NextGen↔PowerSchool field crosswalk, with unmapped values surfaced.
 * The school OU mapping (AD OU + Google OU — where destination writers place
 * accounts) is editable inline on the Schools tab, admin-only + CSRF-checked;
 * every change is written to audit_log with a before/after image.
 */
final class ReferenceController extends Controller
{
    private ReferenceService $ref;

    public function __construct(?ReferenceService $ref = null)
    {
        parent::__construct();
        $this->ref = $ref ?? new ReferenceService();
    }

    private const TABS = ['schools', 'ethnicity', 'positions', 'mapping'];

    public function index(): string
    {
        $tab = in_array($_GET['tab'] ?? '', self::TABS, true) ? (string) $_GET['tab'] : 'schools';

        return $this->render('reference/index', [
            'tab'            => $tab,
            'schools'        => $this->ref->schools(),
            'ethnicity'      => $this->ref->ethnicityMap(),
            'positions'      => $this->ref->positionMap(),
            'unmappedEth'    => $this->ref->unmappedEthnicity(),
            'unmappedSchool' => $this->ref->unmappedSchoolCodes(),
            'unmappedJobs'   => $this->ref->unmappedJobCodes(),
            'fieldMap'       => FieldMap::fields(),
            'fieldGroups'    => FieldMap::GROUPS,
            'csrf'           => Csrf::token(),
        ], 'ref', 'Configuration  /  Reference data', 'Reference data — TCS Identity Master');
    }

    /**
     * Save a school's OU mapping from the Schools tab (admin-only, gated in
     * public/index.php). The Google OU is normalized to leading-slash form —
     * the district convention is /tcs/faculty/{school OU}. Blank clears a
     * mapping (the row goes back to the amber "(unmapped)" state; new Google
     * creates for that school then land in the root OU).
     */
    public function saveSchool(array $params): string
    {
        $back = url('/reference', ['tab' => 'schools']);
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect($back);
        }

        $id = (int) ($params['id'] ?? 0);
        $before = $id > 0 ? $this->ref->findSchool($id) : null;
        if ($before === null) {
            $this->flash('That school no longer exists.');
            return $this->redirect($back);
        }

        $adOu = ReferenceService::cleanOu((string) ($_POST['ad_ou'] ?? ''));
        $googleOu = ReferenceService::normalizeGoogleOu((string) ($_POST['google_ou'] ?? ''));
        if ($adOu === ($before['ad_ou'] ?? null) && $googleOu === ($before['google_ou'] ?? null)) {
            $this->flash('No changes to save for ' . $before['name'] . '.');
            return $this->redirect($back);
        }

        $this->ref->updateSchoolMapping($id, $adOu, $googleOu);
        (new AuditService())->log('school', $id, 'update',
            ['ad_ou' => $before['ad_ou'], 'google_ou' => $before['google_ou']],
            ['ad_ou' => $adOu, 'google_ou' => $googleOu],
            $this->currentUser()['name']);

        $this->flash('Saved OU mapping for ' . $before['name'] . '.');
        return $this->redirect($back);
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
