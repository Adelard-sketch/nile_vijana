<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/** Promotion and distribution opportunities for artists. */
final class OpportunityController extends Controller
{
    /** GET /api/opportunities — published opportunities that are still accepting applications. */
    public function index(Request $req): array
    {
        $filters = Validator::validate($req->query, [
            'category' => 'nullable|in:promotion,distribution',
            'q'        => 'nullable|string|max:100',
            'location' => 'nullable|string|max:100',
        ]);
        [$page, $per, $offset] = pagination($req->query);
        [$where, $params] = $this->publicFilters($filters);
        $condition = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM opportunities o WHERE $condition", $params);
        $rows = DB::all(
            "SELECT o.id, o.category, o.partner_name, o.partner_url, o.title, o.description,
                    o.eligibility, o.location, o.deadline, o.created_at
               FROM opportunities o WHERE $condition
              ORDER BY o.deadline IS NULL, o.deadline, o.created_at DESC
              LIMIT $per OFFSET $offset",
            $params
        );
        return $this->paginated(array_map([self::class, 'presentOpportunity'], $rows), $page, $per, $total);
    }

    /** GET /api/opportunities/{id} — published opportunity details. */
    public function show(Request $req): array
    {
        $row = DB::one(
            "SELECT id, category, partner_name, partner_url, title, description, eligibility, location, deadline, created_at
               FROM opportunities
              WHERE id = ? AND status = 'open' AND (deadline IS NULL OR deadline >= CURRENT_DATE())",
            [$req->id()]
        ) ?? throw HttpException::notFound('Opportunity');
        return self::presentOpportunity($row);
    }

    /** POST /api/opportunities/{id}/applications — apply as an artist. */
    public function apply(Request $req): Response
    {
        $d = Validator::validate($req->all(), ['pitch' => 'required|string|min:20|max:3000']);
        $creatorId = $req->userId();
        if (!DB::value('SELECT 1 FROM creator_profiles WHERE user_id = ?', [$creatorId])) {
            throw HttpException::invalid('Create your artist profile before applying');
        }

        $applicationId = DB::tx(static function () use ($req, $creatorId, $d): int {
            $opportunity = DB::one(
                'SELECT id, status, deadline FROM opportunities WHERE id = ? FOR UPDATE',
                [$req->id()]
            ) ?? throw HttpException::notFound('Opportunity');
            if ($opportunity['status'] !== 'open' || ($opportunity['deadline'] !== null && $opportunity['deadline'] < date('Y-m-d'))) {
                throw HttpException::conflict('This opportunity is no longer accepting applications');
            }
            if (DB::value(
                'SELECT 1 FROM opportunity_applications WHERE opportunity_id = ? AND creator_id = ?',
                [$req->id(), $creatorId]
            )) {
                throw HttpException::conflict('You have already applied to this opportunity');
            }
            return DB::insert('opportunity_applications', [
                'opportunity_id' => $req->id(),
                'creator_id' => $creatorId,
                'pitch' => $d['pitch'],
            ]);
        });

        return Response::created([
            'id' => $applicationId,
            'opportunity_id' => $req->id(),
            'status' => 'pending',
        ]);
    }

    /** GET /api/opportunities/me/applications — the signed-in artist's applications. */
    public function myApplications(Request $req): array
    {
        [$page, $per, $offset] = pagination($req->query);
        $creatorId = $req->userId();
        $total = (int) DB::value('SELECT COUNT(*) FROM opportunity_applications WHERE creator_id = ?', [$creatorId]);
        $rows = DB::all(
            'SELECT a.id, a.opportunity_id, a.pitch, a.status, a.decision_note, a.decided_at, a.created_at,
                    o.category, o.partner_name, o.title, o.location, o.deadline
               FROM opportunity_applications a
               JOIN opportunities o ON o.id = a.opportunity_id
              WHERE a.creator_id = ? ORDER BY a.created_at DESC LIMIT ' . $per . ' OFFSET ' . $offset,
            [$creatorId]
        );
        return $this->paginated(array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['opportunity_id'] = (int) $row['opportunity_id'];
            return $row;
        }, $rows), $page, $per, $total);
    }

    /** GET /api/admin/opportunities?status=&category= — all listings and application counts. */
    public function adminIndex(Request $req): array
    {
        $filters = Validator::validate($req->query, [
            'status' => 'nullable|in:draft,open,closed',
            'category' => 'nullable|in:promotion,distribution',
        ]);
        [$page, $per, $offset] = pagination($req->query);
        $where = ['1 = 1'];
        $params = [];
        foreach (['status', 'category'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = "o.$field = ?";
                $params[] = $filters[$field];
            }
        }
        $condition = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM opportunities o WHERE $condition", $params);
        $rows = DB::all(
            "SELECT o.*, COUNT(a.id) AS application_count
               FROM opportunities o LEFT JOIN opportunity_applications a ON a.opportunity_id = o.id
              WHERE $condition GROUP BY o.id ORDER BY o.created_at DESC LIMIT $per OFFSET $offset",
            $params
        );
        return $this->paginated(array_map([self::class, 'presentOpportunity'], $rows), $page, $per, $total);
    }

    /** POST /api/admin/opportunities — create a draft or publish an opportunity. */
    public function adminCreate(Request $req): Response
    {
        $d = Validator::validate($req->all(), [
            'category' => 'required|in:promotion,distribution',
            'partner_name' => 'required|string|min:2|max:120',
            'partner_url' => 'nullable|string|max:500',
            'title' => 'required|string|min:2|max:160',
            'description' => 'required|string|min:10|max:5000',
            'eligibility' => 'nullable|string|max:3000',
            'location' => 'nullable|string|max:100',
            'deadline' => 'nullable|date',
            'status' => 'nullable|in:draft,open,closed',
        ]);
        $d['status'] = $d['status'] ?? 'draft';
        $this->validateOpportunity($d);
        $id = DB::insert('opportunities', $d + ['created_by' => $req->userId()]);
        return Response::created(self::presentOpportunity(
            DB::one('SELECT * FROM opportunities WHERE id = ?', [$id]) ?? throw new \LogicException('Created opportunity was not found')
        ));
    }

    /** PATCH /api/admin/opportunities/{id} — edit a listing or its publication status. */
    public function adminUpdate(Request $req): array
    {
        $d = Validator::validate($req->all(), [
            'category' => 'nullable|in:promotion,distribution',
            'partner_name' => 'nullable|string|min:2|max:120',
            'partner_url' => 'nullable|string|max:500',
            'title' => 'nullable|string|min:2|max:160',
            'description' => 'nullable|string|min:10|max:5000',
            'eligibility' => 'nullable|string|max:3000',
            'location' => 'nullable|string|max:100',
            'deadline' => 'nullable|date',
            'status' => 'nullable|in:draft,open,closed',
        ]);
        if (!$d) {
            throw HttpException::invalid('Provide at least one opportunity field to update');
        }
        foreach (['category', 'partner_name', 'title', 'description', 'status'] as $field) {
            if (array_key_exists($field, $d) && $d[$field] === null) {
                throw HttpException::invalid('Required opportunity fields cannot be cleared', [$field => 'This field cannot be cleared']);
            }
        }
        $existing = DB::one('SELECT * FROM opportunities WHERE id = ?', [$req->id()])
            ?? throw HttpException::notFound('Opportunity');
        $this->validateOpportunity(array_replace($existing, $d));
        DB::update('opportunities', $d, 'id = ?', [$req->id()]);
        return self::presentOpportunity(
            DB::one('SELECT * FROM opportunities WHERE id = ?', [$req->id()]) ?? throw HttpException::notFound('Opportunity')
        );
    }

    /** GET /api/admin/opportunities/{id}/applications?status=pending */
    public function applications(Request $req): array
    {
        $opportunity = DB::one('SELECT id FROM opportunities WHERE id = ?', [$req->id()])
            ?? throw HttpException::notFound('Opportunity');
        $filters = Validator::validate($req->query, ['status' => 'nullable|in:pending,accepted,rejected']);
        [$page, $per, $offset] = pagination($req->query);
        $where = ['a.opportunity_id = ?'];
        $params = [$opportunity['id']];
        if (!empty($filters['status'])) {
            $where[] = 'a.status = ?';
            $params[] = $filters['status'];
        }
        $condition = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM opportunity_applications a WHERE $condition", $params);
        $rows = DB::all(
            "SELECT a.id, a.creator_id, a.pitch, a.status, a.decision_note, a.decided_at, a.created_at,
                    cp.stage_name, cp.slug, cp.city, cp.verified
               FROM opportunity_applications a
               JOIN creator_profiles cp ON cp.user_id = a.creator_id
              WHERE $condition ORDER BY a.created_at DESC LIMIT $per OFFSET $offset",
            $params
        );
        return $this->paginated(array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['creator_id'] = (int) $row['creator_id'];
            $row['verified'] = (bool) $row['verified'];
            return $row;
        }, $rows), $page, $per, $total);
    }

    /** PATCH /api/admin/opportunities/{id}/applications/{applicationId}. */
    public function reviewApplication(Request $req): array
    {
        $d = Validator::validate($req->all(), [
            'status' => 'required|in:accepted,rejected',
            'decision_note' => 'nullable|string|max:1000',
        ]);
        $application = DB::one(
            'SELECT id, status FROM opportunity_applications WHERE id = ? AND opportunity_id = ?',
            [$req->id('applicationId'), $req->id()]
        ) ?? throw HttpException::notFound('Application');
        if ($application['status'] !== 'pending') {
            throw HttpException::conflict('This application has already been reviewed');
        }
        $updated = DB::update('opportunity_applications', [
            'status' => $d['status'],
            'decision_note' => $d['decision_note'] ?? null,
            'decided_by' => $req->userId(),
            'decided_at' => now(),
        ], "id = ? AND status = 'pending'", [$application['id']]);
        if (!$updated) {
            throw HttpException::conflict('This application has already been reviewed');
        }
        return [
            'id' => (int) $application['id'],
            'status' => $d['status'],
            'decision_note' => $d['decision_note'] ?? null,
        ];
    }

    private function publicFilters(array $filters): array
    {
        $where = ["o.status = 'open'", '(o.deadline IS NULL OR o.deadline >= CURRENT_DATE())'];
        $params = [];
        if (!empty($filters['category'])) {
            $where[] = 'o.category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(o.title LIKE ? OR o.partner_name LIKE ? OR o.description LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like);
        }
        if (!empty($filters['location'])) {
            $where[] = 'o.location LIKE ?';
            $params[] = '%' . $filters['location'] . '%';
        }
        return [$where, $params];
    }

    private function validateOpportunity(array $data): void
    {
        if (!empty($data['partner_url'])) {
            $url = filter_var($data['partner_url'], FILTER_VALIDATE_URL);
            if ($url === false || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                throw HttpException::invalid('Partner links must be valid HTTPS URLs', ['partner_url' => 'Use an https:// URL']);
            }
        }
        if (
            $data['status'] === 'open'
            && !empty($data['deadline'])
            && $data['deadline'] < date('Y-m-d')
        ) {
            throw HttpException::invalid('An opportunity with a past deadline cannot be opened', ['deadline' => 'Choose today or a future date']);
        }
    }

    private static function presentOpportunity(array $row): array
    {
        foreach (['id', 'created_by', 'application_count'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = (int) $row[$field];
            }
        }
        return $row;
    }
}
