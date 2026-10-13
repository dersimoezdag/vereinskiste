<?php
declare(strict_types=1);

namespace Vereinskiste\Web;

use Closure;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Vereinskiste\Foundation\Conflict;
use Vereinskiste\Foundation\Fields;
use Vereinskiste\Foundation\History;
use Vereinskiste\Ledger\ValidationError;
use Vereinskiste\Members\ApplicationRepository;
use Vereinskiste\Members\ClubRoleRepository;
use Vereinskiste\Members\MemberRepository;
use Vereinskiste\Members\MemberRoleAssignments;
use Vereinskiste\Members\MembershipPeriods;
use Vereinskiste\Members\MemberUserLink;

final class MemberController extends AdminController
{
    public function __construct(private Closure $pdo) {}

    public function index(Request $req, Response $res): Response
    {
        $query = $req->getQueryParams();
        $search = $this->str($query, 'q');
        if (!mb_check_encoding($search, 'UTF-8')) {
            $search = '';
        }
        $state = $this->str($query, 'state') ?: 'all';
        $page = max(1, min(100000, (int) $this->str($query, 'page')));
        $pdo = ($this->pdo)();
        $members = (new MemberRepository($pdo))->list($search, $state, $page);
        $pendingCount = (new ApplicationRepository($pdo))->countPending();

        return $req->getAttribute('view')->render($res, 'members/index.twig', [
            'members' => $members,
            'search' => $search,
            'state' => $state,
            'page' => $page,
            'pending_count' => $pendingCount,
        ]);
    }

    public function csv(Request $req, Response $res): Response
    {
        $query = $req->getQueryParams();
        $search = $this->str($query, 'q');
        if (!mb_check_encoding($search, 'UTF-8')) { $search = ''; }
        $stream = (new \Vereinskiste\Members\MemberExport(($this->pdo)()))->csv(
            $search, $this->str($query, 'state') ?: 'all'
        );
        return $res->withBody(new \Slim\Psr7\Stream($stream))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="mitglieder.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function create(Request $req, Response $res): Response
    {
        return $this->renderDetail($req, $res);
    }

    public function show(Request $req, Response $res, array $args): Response
    {
        $member = (new MemberRepository(($this->pdo)()))->find((int) $args['id']);
        return $member === null ? $this->notFound($req) : $this->renderDetail($req, $res, $member);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $repo = new MemberRepository(($this->pdo)());
        $member = $id === null ? null : $repo->find($id);
        if ($id !== null && $member === null) {
            return $this->notFound($req);
        }
        $body = $this->body($req);
        try {
            $saved = $repo->save(
                $id,
                $body,
                Fields::version($body['version'] ?? null),
                $this->userId($req),
                $this->str($body, 'reason')
            );
        } catch (ValidationError $e) {
            return $this->renderDetail($req, $res->withStatus(422), $member, $body, $e->errors);
        } catch (Conflict) {
            return $this->renderDetail($req, $res->withStatus(409), $member, $body, ['version' => 'foundation.conflict']);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/members/' . $saved);
    }

    public function join(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $member = (new MemberRepository(($this->pdo)()))->find($id);
        if ($member === null) {
            return $this->notFound($req);
        }
        $body = $this->body($req);
        $date = $this->str($body, 'date') ?: (new DateTimeImmutable())->format('Y-m-d');
        try {
            (new MembershipPeriods(($this->pdo)()))->join($id, $date, $this->userId($req));
        } catch (ValidationError $e) {
            return $this->renderDetail($req, $res->withStatus(422), $member, null, $e->errors);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/members/' . $id);
    }

    public function leave(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $member = (new MemberRepository(($this->pdo)()))->find($id);
        if ($member === null) {
            return $this->notFound($req);
        }
        $body = $this->body($req);
        $date = $this->str($body, 'date') ?: (new DateTimeImmutable())->format('Y-m-d');
        $reason = $this->str($body, 'end_reason');
        try {
            (new MembershipPeriods(($this->pdo)()))->leave($id, $date, $reason, $this->userId($req));
        } catch (ValidationError $e) {
            return $this->renderDetail($req, $res->withStatus(422), $member, null, $e->errors);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/members/' . $id);
    }

    public function linkUser(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $member = (new MemberRepository(($this->pdo)()))->find($id);
        if ($member === null) {
            return $this->notFound($req);
        }
        $body = $this->body($req);
        $userId = (int) ($body['user_id'] ?? 0);
        try {
            (new MemberUserLink(($this->pdo)()))->link($id, $userId, $this->userId($req));
        } catch (ValidationError $e) {
            return $this->renderDetail($req, $res->withStatus(422), $member, null, $e->errors);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/members/' . $id);
    }

    public function unlinkUser(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $member = (new MemberRepository(($this->pdo)()))->find($id);
        if ($member === null) {
            return $this->notFound($req);
        }
        (new MemberUserLink(($this->pdo)()))->unlink($id, $this->userId($req));
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/members/' . $id);
    }

    public function assignRole(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $member = (new MemberRepository(($this->pdo)()))->find($id);
        if ($member === null) {
            return $this->notFound($req);
        }
        $body = $this->body($req);
        $roleId = (int) ($body['role_id'] ?? 0);
        $scope = $this->str($body, 'scope');
        $start = $this->str($body, 'start_date') ?: null;
        try {
            (new MemberRoleAssignments(($this->pdo)()))->assign($id, $roleId, $scope, $start, $this->userId($req));
        } catch (ValidationError $e) {
            return $this->renderDetail($req, $res->withStatus(422), $member, null, $e->errors);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/members/' . $id);
    }

    public function endRole(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $assignmentId = (int) $args['assignment'];
        $member = (new MemberRepository(($this->pdo)()))->find($id);
        if ($member === null) {
            return $this->notFound($req);
        }
        $body = $this->body($req);
        $date = $this->str($body, 'date') ?: (new DateTimeImmutable())->format('Y-m-d');
        try {
            (new MemberRoleAssignments(($this->pdo)()))->end($assignmentId, $date, $this->userId($req));
        } catch (ValidationError $e) {
            return $this->renderDetail($req, $res->withStatus(422), $member, null, $e->errors);
        }
        $this->flash($req, 'foundation.saved');
        return $this->redirect('/members/' . $id);
    }

    public function applications(Request $req, Response $res): Response
    {
        $query = $req->getQueryParams();
        $filter = $this->str($query, 'filter') ?: 'all';
        $pdo = ($this->pdo)();
        $appRepo = new ApplicationRepository($pdo);
        $applications = $appRepo->listApplications($filter);
        $invites = $appRepo->listInvites();
        $pendingCount = $appRepo->countPending();

        return $req->getAttribute('view')->render($res, 'members/applications.twig', [
            'applications' => $applications,
            'invites' => $invites,
            'filter' => $filter,
            'pending_count' => $pendingCount,
            'outbox' => (new \Vereinskiste\Mail\Outbox($pdo))->list(),
        ]);
    }

    public function createInvite(Request $req, Response $res): Response
    {
        $body = $this->body($req);
        $title = $this->str($body, 'title');
        $days = (int) ($body['valid_days'] ?? 14);
        $password = $this->str($body, 'password');
        $requireSepa = !empty($body['require_sepa']);

        (new ApplicationRepository(($this->pdo)()))->createInvite(
            $this->userId($req),
            $title,
            $days,
            $password === '' ? null : $password,
            $requireSepa
        );

        $this->flash($req, 'applications.invites.created_success');
        return $this->redirect('/members/applications');
    }

    public function approveApplication(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $appRepo = new ApplicationRepository(($this->pdo)());
        try {
            $memberId = $appRepo->approve($id, $this->userId($req));
        } catch (ValidationError $e) {
            $this->flash($req, 'foundation.invalid');
            return $this->redirect('/members/applications');
        }
        $this->flash($req, 'applications.approved_success');
        return $this->redirect('/members/' . $memberId);
    }

    public function rejectApplication(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $reason = $this->str($this->body($req), 'reason');
        $appRepo = new ApplicationRepository(($this->pdo)());
        try {
            $appRepo->reject($id, $reason, $this->userId($req));
        } catch (ValidationError $e) {
            $this->flash($req, 'foundation.invalid');
            return $this->redirect('/members/applications');
        }
        $this->flash($req, 'applications.rejected_success');
        return $this->redirect('/members/applications');
    }

    private function renderDetail(Request $req, Response $res, ?array $member = null, ?array $input = null, array $errors = []): Response
    {
        $pdo = ($this->pdo)();
        $auth = $req->getAttribute('auth');
        $canManageUsers = $auth?->can('users.manage') ?? false;

        $defaults = $member === null
            ? ['version' => 0]
            : $member['data'] + ['name' => $member['name'], 'version' => $member['version']];

        $safe = [];
        foreach (($input ?? $defaults) as $key => $value) {
            $safe[$key] = (is_string($value) || is_int($value)) && mb_check_encoding((string) $value, 'UTF-8') ? (string) $value : '';
        }

        $clubRoles = (new ClubRoleRepository($pdo))->list(false);
        $assignments = $member === null ? [] : (new MemberRoleAssignments($pdo))->forMember($member['id']);
        $linkableUsers = $canManageUsers ? (new MemberUserLink($pdo))->linkableUsers() : [];
        $history = $member === null ? [] : (new History($pdo))->for('member', $member['id']);

        // Check if member has an open membership type
        $hasOpenMembershipType = false;
        foreach ($assignments as $a) {
            if ($a['end_date'] === null && $a['role_kind'] === 'membership_type') {
                $hasOpenMembershipType = true;
                break;
            }
        }

        return $req->getAttribute('view')->render($res, $member === null ? 'members/form.twig' : 'members/detail.twig', [
            'member' => $member,
            'input' => $safe,
            'fields' => MemberRepository::FIELDS,
            'errors' => $errors,
            'club_roles' => $clubRoles,
            'assignments' => $assignments,
            'has_open_membership_type' => $hasOpenMembershipType,
            'linkable_users' => $linkableUsers,
            'history' => $history,
            'today' => (new DateTimeImmutable())->format('Y-m-d'),
        ]);
    }
}
