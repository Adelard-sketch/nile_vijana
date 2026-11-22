<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\EmailTemplates;
use App\Services\Mailer;

/**
 * Q&A: anyone can ask the Nile Vijana team a question; admins answer from the dashboard
 * and the answer is emailed to the person (and shown in their account if signed in).
 * Answers the team marks as public appear on the Q&A page as frequently asked questions.
 */
final class QuestionController extends Controller
{
    public const CATEGORIES = [
        'general'  => 'General',
        'account'  => 'My account and sign-in',
        'payments' => 'Payments and refunds',
        'music'    => 'Music and streaming',
        'beats'    => 'Beats and licences',
        'studios'  => 'Studio bookings',
        'creators' => 'Selling as a creator',
        'other'    => 'Something else',
    ];

    private const LABELS = [
        'name' => 'Your name', 'email' => 'Email address', 'phone' => 'Phone number',
        'category' => 'Topic', 'subject' => 'Subject', 'body' => 'Your question',
    ];

    /** POST /api/questions — works signed in or not. */
    public function store(Request $req): Response
    {
        $input = $req->all();
        if ($req->user) {
            $input['name'] = ($input['name'] ?? '') !== '' ? $input['name'] : $req->user['name'];
            $input['email'] = ($input['email'] ?? '') !== '' ? $input['email'] : $req->user['email'];
        }
        // Bots fill every field; people never see this one.
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return Response::created(['reference' => 'Q-' . strtoupper(bin2hex(random_bytes(3))), 'status' => 'open', 'message' => 'Thank you. Your question has been sent.']);
        }
        $d = Validator::validate($input, [
            'name'     => 'required|string|min:2|max:120',
            'email'    => 'required|email|max:190',
            'phone'    => 'nullable|phone',
            'category' => 'required|in:' . implode(',', array_keys(self::CATEGORIES)),
            'subject'  => 'required|string|min:5|max:160',
            'body'     => 'required|string|min:20|max:3000',
        ], self::LABELS);

        $ip = $req->ip();
        $recentIp = (int) DB::value('SELECT COUNT(*) FROM questions WHERE ip = ? AND created_at > ?', [$ip, now(-3600)]);
        $recentEmail = (int) DB::value('SELECT COUNT(*) FROM questions WHERE email = ? AND created_at > ?', [$d['email'], now(-3600)]);
        if ($recentIp >= 5 || $recentEmail >= 3) {
            throw new HttpException(429, 'You have sent several questions in the last hour. Please wait a little before sending another.');
        }
        $duplicate = DB::value('SELECT reference FROM questions WHERE email = ? AND subject = ? AND body = ? AND created_at > ?', [$d['email'], $d['subject'], $d['body'], now(-86400)]);
        if ($duplicate) {
            throw HttpException::conflict("You already sent this question (reference $duplicate). We will answer it by email.");
        }

        $reference = 'Q-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $id = DB::insert('questions', [
            'reference' => $reference,
            'user_id'   => $req->user ? (int) $req->user['id'] : null,
            'name'      => $d['name'],
            'email'     => $d['email'],
            'phone'     => $d['phone'] ?? null,
            'category'  => $d['category'],
            'subject'   => $d['subject'],
            'body'      => $d['body'],
            'ip'        => $ip,
        ]);
        $mail = EmailTemplates::questionReceived($d['name'], $d['subject'], $reference);
        Mailer::send($d['email'], $mail['subject'], $mail['html']);

        return Response::created(self::present(DB::one('SELECT * FROM questions WHERE id = ?', [$id])) + [
            'message' => "Thank you, your question has been sent. Your reference is $reference. We will email the answer to {$d['email']}.",
        ]);
    }

    /** GET /api/questions/mine — questions I asked (signed in, or sent with my email). */
    public function mine(Request $req): array
    {
        $rows = DB::all(
            'SELECT * FROM questions WHERE user_id = ? OR (user_id IS NULL AND email = ?) ORDER BY id DESC LIMIT 50',
            [$req->userId(), (string) ($req->user['email'] ?? '')]
        );
        return ['data' => array_map([self::class, 'present'], $rows)];
    }

    /** GET /api/questions/public — answered questions the team chose to publish (FAQ). */
    public function publicList(Request $req): array
    {
        $rows = DB::all("SELECT * FROM questions WHERE is_public = 1 AND status <> 'open' AND answer IS NOT NULL ORDER BY answered_at DESC LIMIT 30");
        return ['data' => array_map(static fn ($q) => [
            'id'       => (int) $q['id'],
            'category' => $q['category'],
            'category_label' => self::CATEGORIES[$q['category']] ?? $q['category'],
            'subject'  => $q['subject'],
            'body'     => $q['body'],
            'answer'   => $q['answer'],
            'answered_at' => $q['answered_at'],
        ], $rows), 'categories' => self::CATEGORIES];
    }

    /* ------------------------------------------------------------------ admin */

    /** GET /api/admin/questions?status=open|answered|closed|all&q= */
    public function adminIndex(Request $req): array
    {
        $status = (string) ($req->query['status'] ?? 'open');
        $where = [];
        $params = [];
        if (in_array($status, ['open', 'answered', 'closed'], true)) {
            $where[] = 'q.status = ?';
            $params[] = $status;
        }
        $search = trim((string) ($req->query['q'] ?? ''));
        if ($search !== '') {
            $where[] = '(q.subject LIKE ? OR q.body LIKE ? OR q.name LIKE ? OR q.email LIKE ? OR q.reference = ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, strtoupper($search));
        }
        [$page, $per, $offset] = pagination($req->query, 25, 100);
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int) DB::value("SELECT COUNT(*) FROM questions q $sqlWhere", $params);
        $rows = DB::all(
            "SELECT q.*, u.username, a.name AS answered_by_name FROM questions q
               LEFT JOIN users u ON u.id = q.user_id
               LEFT JOIN users a ON a.id = q.answered_by
               $sqlWhere ORDER BY (q.status = 'open') DESC, q.id DESC LIMIT $per OFFSET $offset",
            $params
        );
        $counts = ['open' => 0, 'answered' => 0, 'closed' => 0];
        foreach (DB::all('SELECT status, COUNT(*) n FROM questions GROUP BY status') as $c) {
            $counts[$c['status']] = (int) $c['n'];
        }
        return $this->paginated(array_map([self::class, 'present'], $rows), $page, $per, $total) + ['counts' => $counts, 'categories' => self::CATEGORIES];
    }

    /** GET /api/admin/questions/{id} */
    public function adminShow(Request $req): array
    {
        return self::present($this->find($req->id()));
    }

    /** POST /api/admin/questions/{id}/answer {"answer": "...", "is_public": false, "notify": true} */
    public function answer(Request $req): array
    {
        $q = $this->find($req->id());
        $d = Validator::validate($req->all(), [
            'answer'    => 'required|string|min:10|max:5000',
            'is_public' => 'nullable|bool',
            'notify'    => 'nullable|bool',
        ], ['answer' => 'Answer']);
        DB::update('questions', [
            'answer'      => $d['answer'],
            'status'      => 'answered',
            'answered_by' => $req->userId(),
            'answered_at' => now(),
            'is_public'   => !empty($d['is_public']) ? 1 : 0,
        ], 'id = ?', [$q['id']]);
        $emailed = false;
        if ($d['notify'] ?? true) {
            $mail = EmailTemplates::questionAnswered($q['name'], $q['subject'], $q['body'], $d['answer'], $q['reference']);
            $emailed = Mailer::send($q['email'], $mail['subject'], $mail['html']);
        }
        return self::present($this->find($q['id'])) + ['emailed' => $emailed];
    }

    /** PATCH /api/admin/questions/{id} {"status": "open|answered|closed", "is_public": true} */
    public function update(Request $req): array
    {
        $q = $this->find($req->id());
        $d = Validator::validate($req->all(), [
            'status'    => 'nullable|in:open,answered,closed',
            'is_public' => 'nullable|bool',
        ]);
        $changes = [];
        if (!empty($d['status'])) {
            if ($d['status'] === 'answered' && !$q['answer']) {
                throw HttpException::invalid('Write an answer first.', ['status' => 'A question without an answer cannot be marked answered.']);
            }
            $changes['status'] = $d['status'];
        }
        if (array_key_exists('is_public', $d) && $d['is_public'] !== null) {
            if ($d['is_public'] && !$q['answer']) {
                throw HttpException::invalid('Only answered questions can be shown on the Q&A page.', ['is_public' => 'Answer the question first.']);
            }
            $changes['is_public'] = $d['is_public'] ? 1 : 0;
        }
        if ($changes) {
            DB::update('questions', $changes, 'id = ?', [$q['id']]);
        }
        return self::present($this->find($q['id']));
    }

    private function find(int $id): array
    {
        return DB::one('SELECT q.*, u.username, a.name AS answered_by_name FROM questions q LEFT JOIN users u ON u.id = q.user_id LEFT JOIN users a ON a.id = q.answered_by WHERE q.id = ?', [$id])
            ?? throw HttpException::notFound('Question');
    }

    public static function present(array $q): array
    {
        return [
            'id'             => (int) $q['id'],
            'reference'      => $q['reference'],
            'status'         => $q['status'],
            'category'       => $q['category'],
            'category_label' => self::CATEGORIES[$q['category']] ?? $q['category'],
            'subject'        => $q['subject'],
            'body'           => $q['body'],
            'name'           => $q['name'],
            'email'          => $q['email'],
            'phone'          => $q['phone'],
            'user'           => $q['user_id'] ? ['id' => (int) $q['user_id'], 'username' => $q['username'] ?? null] : null,
            'answer'         => $q['answer'],
            'answered_at'    => $q['answered_at'],
            'answered_by'    => $q['answered_by_name'] ?? null,
            'is_public'      => (bool) $q['is_public'],
            'created_at'     => $q['created_at'],
        ];
    }
}
