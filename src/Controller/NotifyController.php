<?php

declare(strict_types=1);

namespace App\Controller;

use App\View\View;

/**
 * Orientation-notification generation (Workflow B). Renders the New Teacher /
 * Non-Instructional Technology Orientation Checklist for one person as a
 * standalone, print-to-PDF HTML document populated from the golden record —
 * including the OneSync-minted account. Because the links are real HTML anchors
 * rendered by the browser's print engine, the embedded-hyperlink breakage of
 * Word's "Finish & Merge" doesn't apply.
 *
 * Read-only and gated at 'edit' (it surfaces account credentials as part of an
 * onboarding action). Only generates once a username is minted and locked — the
 * checklist's whole purpose is to hand someone their account.
 */
final class NotifyController extends Controller
{
    /** The checklist variants (person_type-driven). */
    public const DOCS = ['new_teacher', 'non_instructional'];

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

    public function show(array $params): string
    {
        $id = (int) ($params['id'] ?? 0);
        $person = $id > 0 ? $this->people->find($id) : null;
        if ($person === null) {
            http_response_code(404);
            return $this->render('pages/not_found', ['message' => 'No person with that id.'], 'people', 'People  /  Not found', 'Not found');
        }

        if (!self::isReady($person)) {
            $this->flash('No username has been minted yet — OneSync assigns it once the record is activated. The orientation checklist needs the account first.');
            return $this->redirect(url('/people/' . $id));
        }

        $doc = self::documentFor((string) $person['person_type'], (string) ($_GET['doc'] ?? ''));

        $assignments = $this->people->assignments($id);
        $primary = $assignments[0] ?? null;
        $position = trim((string) ($primary['title'] ?? '')) ?: trim((string) ($person['position_number'] ?? ''));
        $displayName = trim((string) ($person['preferred_name'] ?? '')) ?: trim((string) $person['first_name']);

        $data = [
            'person'      => $person,
            'fullName'    => trim($person['first_name'] . ' ' . $person['last_name']),
            'displayName' => $displayName,
            'school'      => trim((string) ($person['primary_school_name'] ?? '')),
            'position'    => $position,
            'startDate'   => trim((string) ($person['position_start_date'] ?? '')) ?: trim((string) ($person['hire_date'] ?? '')),
        ];

        // Render the body variant, then wrap it in the standalone print shell.
        $body = View::partial('notify/' . $doc, $data);
        $title = ($doc === 'new_teacher' ? 'New Teacher' : 'Non-Instructional Employee')
            . ' Technology Orientation — ' . $data['fullName'];

        header('Content-Type: text/html; charset=utf-8');
        return View::partial('notify/document', ['title' => $title, 'body' => $body]);
    }
}
