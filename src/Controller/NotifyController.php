<?php

declare(strict_types=1);

namespace App\Controller;

use App\Db;
use App\Service\AuditService;
use App\Service\LoginsReportService;
use App\Service\NotifyTemplateService;
use App\Support\Csrf;
use App\View\View;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Orientation-notification generation (Workflow B). Renders the New Teacher /
 * Non-Instructional Technology Orientation Checklist for one person — as an HTML
 * preview, a server-side PDF (Dompdf), or a ZIP of PDFs for a whole filtered
 * batch — populated from the golden record including the OneSync-minted account.
 * Content is editable per variant (NotifyTemplateService); generation is audited.
 *
 * All routes gated at 'edit' (credentials + an onboarding action). A checklist
 * only generates once a username is minted and locked — there must be an account
 * to hand over.
 */
final class NotifyController extends Controller
{
    /** The checklist variants (person_type-driven). */
    public const DOCS = ['new_teacher', 'non_instructional'];

    /** Safety cap on a single bulk run. */
    private const BULK_MAX = 500;

    private NotifyTemplateService $templates;

    public function __construct(?NotifyTemplateService $templates = null)
    {
        parent::__construct();
        $this->templates = $templates ?? new NotifyTemplateService();
    }

    /**
     * Which checklist a person gets: teachers get the New Teacher variant, everyone
     * else the Non-Instructional one. An explicit, valid override wins.
     */
    public static function documentFor(string $personType, string $override = ''): string
    {
        if (in_array($override, self::DOCS, true)) {
            return $override;
        }
        return $personType === 'faculty' ? 'new_teacher' : 'non_instructional';
    }

    /**
     * A person is ready for a checklist once OneSync has minted + locked a username
     * — otherwise there's no account to hand over.
     *
     * @param array<string,mixed> $person
     */
    public static function isReady(array $person): bool
    {
        return (int) ($person['username_locked'] ?? 0) === 1
            && trim((string) ($person['username'] ?? '')) !== '';
    }

    // ---- single-person: HTML preview + PDF ---------------------------------

    /** HTML preview with a Download-PDF button (not audited — this is viewing). */
    public function show(array $params): string
    {
        $id = (int) ($params['id'] ?? 0);
        $ctx = $this->context($id, (string) ($_GET['doc'] ?? ''));
        if ($ctx === null) {
            http_response_code(404);
            return $this->render('pages/not_found', ['message' => 'No person with that id.'], 'people', 'People  /  Not found', 'Not found');
        }
        if (!$ctx['ready']) {
            $this->flash('No username has been minted yet — OneSync assigns it once the record is activated. The orientation checklist needs the account first.');
            return $this->redirect(url('/people/' . $id));
        }

        $pdfQuery = $ctx['doc'] !== $ctx['autoDoc'] ? ['doc' => $ctx['doc']] : [];
        header('Content-Type: text/html; charset=utf-8');
        return View::partial('notify/document', [
            'title'  => $ctx['title'],
            'body'   => $this->renderBody($ctx),
            'pdfUrl' => url('/notify/' . $id . '/pdf', $pdfQuery),
        ]);
    }

    /** Server-side PDF for one person. Audited as a generation. */
    public function pdf(array $params): string
    {
        $id = (int) ($params['id'] ?? 0);
        $ctx = $this->context($id, (string) ($_GET['doc'] ?? ''));
        if ($ctx === null) {
            http_response_code(404);
            return $this->render('pages/not_found', ['message' => 'No person with that id.'], 'people', 'People  /  Not found', 'Not found');
        }
        if (!$ctx['ready']) {
            $this->flash('No username has been minted yet — the orientation checklist needs the account first.');
            return $this->redirect(url('/people/' . $id));
        }

        $pdf = $this->renderPdf($ctx);
        $this->auditGenerated($id, $ctx['doc'], 'single');

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $this->pdfFilename($ctx) . '"');
        return $pdf;
    }

    // ---- bulk: a ZIP of PDFs for a filtered batch --------------------------

    /**
     * Generate a PDF per ready person matching the current Logins filters and
     * stream them as a ZIP. POST (CSRF) so a batch generation is an explicit,
     * audited action rather than a link. Each person is audited individually.
     */
    public function bulk(): string
    {
        $back = url('/logins');
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect($back);
        }
        if (!class_exists(\ZipArchive::class)) {
            $this->flash('The ZIP extension is not available on this server — generate checklists individually.');
            return $this->redirect($back);
        }

        $filters = [
            'status' => (string) ($_POST['status'] ?? 'all'),
            'school' => (string) ($_POST['school'] ?? 'all'),
            'from'   => (string) ($_POST['from'] ?? ''),
            'to'     => (string) ($_POST['to'] ?? ''),
            'q'      => (string) ($_POST['q'] ?? ''),
        ];
        $rows = (new LoginsReportService())->rows($filters);
        $ready = array_values(array_filter($rows, static fn($r) => !empty($r['checklist_ready'])));

        if ($ready === []) {
            $this->flash('No people in the current filter have a minted username yet — nothing to generate.');
            return $this->redirect($back);
        }
        $truncated = count($ready) > self::BULK_MAX;
        if ($truncated) {
            $ready = array_slice($ready, 0, self::BULK_MAX);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'idm_notify_');
        if ($tmp === false) {
            $this->flash('Could not create a temporary file for the ZIP.');
            return $this->redirect($back);
        }
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $used = [];
        $count = 0;
        foreach ($ready as $r) {
            $ctx = $this->context((int) $r['person_id'], '');
            if ($ctx === null || !$ctx['ready']) {
                continue; // changed since the report query
            }
            $name = $this->pdfFilename($ctx);
            // Guard against duplicate filenames within the archive.
            if (isset($used[$name])) {
                $name = preg_replace('/\.pdf$/', '', $name) . '-' . $r['person_id'] . '.pdf';
            }
            $used[$name] = true;
            $zip->addFromString($name, $this->renderPdf($ctx));
            $this->auditGenerated((int) $r['person_id'], $ctx['doc'], 'bulk');
            $count++;
        }
        $zip->close();

        if ($count === 0) {
            @unlink($tmp);
            $this->flash('Nothing was generated — the matching records are no longer ready.');
            return $this->redirect($back);
        }

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="orientation-checklists-' . date('Y-m-d') . '.zip"');
        return $bytes;
    }

    // ---- editable content --------------------------------------------------

    /** The template editor (both variants). */
    public function templates(): string
    {
        return $this->render('notify/templates', [
            'docs' => $this->templates->all(),
            'csrf' => Csrf::token(),
        ], 'logins', 'Logins export  /  Checklist content', 'Checklist content — TCS Identity Master');
    }

    /** Save one variant's content, or reset it to the built-in default. */
    public function saveTemplate(): string
    {
        $back = url('/notify/templates');
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect($back);
        }
        $doc = (string) ($_POST['doc'] ?? '');
        if (!in_array($doc, self::DOCS, true)) {
            $this->flash('Unknown checklist.');
            return $this->redirect($back);
        }

        $actor = $this->currentUser()['name'];
        try {
            if (($_POST['action'] ?? '') === 'reset') {
                $this->templates->reset($doc, $actor);
                $this->flash('Checklist reset to the built-in default.');
                return $this->redirect($back);
            }
            $heading = trim((string) ($_POST['heading'] ?? ''));
            if ($heading === '') {
                $this->flash('A heading is required.');
                return $this->redirect($back);
            }
            $this->templates->save(
                $doc,
                $heading,
                trim((string) ($_POST['intro'] ?? '')),
                (string) ($_POST['body'] ?? ''),
                $actor
            );
            $this->flash('Checklist content saved.');
        } catch (\Throwable $e) {
            error_log('[idm] notify template save: ' . $e->getMessage());
            $this->flash('Could not save the checklist content.');
        }
        return $this->redirect($back);
    }

    // ---- internals ---------------------------------------------------------

    /**
     * Assemble everything needed to render a person's checklist, or null if the
     * person doesn't exist. Includes readiness so callers can gate.
     *
     * @return array{person:array,doc:string,autoDoc:string,tmpl:array,vars:array<string,string>,data:array,title:string,ready:bool}|null
     */
    private function context(int $id, string $override): ?array
    {
        $person = $id > 0 ? $this->people->find($id) : null;
        if ($person === null) {
            return null;
        }

        $autoDoc = self::documentFor((string) $person['person_type']);
        $doc = self::documentFor((string) $person['person_type'], $override);
        $tmpl = $this->templates->get($doc);

        $assignments = $this->people->assignments($id);
        $primary = $assignments[0] ?? null;
        $position = trim((string) ($primary['title'] ?? '')) ?: trim((string) ($person['position_number'] ?? ''));
        $displayName = trim((string) ($person['preferred_name'] ?? '')) ?: trim((string) $person['first_name']);
        $fullName = trim($person['first_name'] . ' ' . $person['last_name']);
        $school = trim((string) ($person['primary_school_name'] ?? ''));
        $startDate = trim((string) ($person['position_start_date'] ?? '')) ?: trim((string) ($person['hire_date'] ?? ''));

        return [
            'person'  => $person,
            'doc'     => $doc,
            'autoDoc' => $autoDoc,
            'tmpl'    => $tmpl,
            'ready'   => self::isReady($person),
            'data'    => [
                'person' => $person, 'fullName' => $fullName, 'school' => $school,
                'position' => $position, 'startDate' => $startDate,
            ],
            'vars' => [
                'name'       => $displayName,
                'username'   => trim((string) ($person['username'] ?? '')),
                'email'      => trim((string) ($person['email'] ?? '')),
                'employeeid' => trim((string) ($person['employee_id'] ?? '')),
                'school'     => $school,
                'position'   => $position,
                'start_date' => $startDate,
            ],
            'title' => $tmpl['heading'] . ' — ' . $fullName,
        ];
    }

    /** Render the inner checklist body (shared by preview + PDF). */
    private function renderBody(array $ctx): string
    {
        return View::partial('notify/checklist_body', $ctx['data'] + [
            'tmpl' => $ctx['tmpl'],
            'vars' => $ctx['vars'],
        ]);
    }

    /** Render a person's checklist to PDF bytes via Dompdf. */
    private function renderPdf(array $ctx): string
    {
        $html = View::partial('notify/pdf_document', [
            'title' => $ctx['title'],
            'body'  => $this->renderBody($ctx),
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', false);   // no external fetches
        $options->set('defaultFont', 'Helvetica');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        return (string) $dompdf->output();
    }

    /** A safe, descriptive PDF filename for a person's checklist. */
    private function pdfFilename(array $ctx): string
    {
        $person = $ctx['person'];
        $idPart = trim((string) ($person['employee_id'] ?? '')) ?: substr((string) $person['person_uuid'], 0, 8);
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $person['last_name']) ?? '');
        $slug = trim($slug, '-') ?: 'person';
        return 'orientation-' . $slug . '-' . preg_replace('/[^A-Za-z0-9]+/', '', $idPart) . '.pdf';
    }

    /** Record a checklist generation: an audit row + a person timeline entry. */
    private function auditGenerated(int $personId, string $doc, string $mode): void
    {
        try {
            $audit = new AuditService(Db::connect(Db::ROLE_APP));
            $actor = $this->currentUser()['name'];
            $audit->log('person', $personId, 'notify', null, ['doc' => $doc, 'mode' => $mode], $actor);
            $audit->lifecycle($personId, 'notify', ['summary' => 'Orientation checklist generated', 'doc' => $doc, 'mode' => $mode], $actor);
        } catch (\Throwable $e) {
            // Auditing must never break delivery of the document.
            error_log('[idm] notify audit: ' . $e->getMessage());
        }
    }
}
