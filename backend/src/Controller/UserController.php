<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AuditRepository;
use App\Repository\UserRepository;
use App\Support\Json;
use App\Support\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UserController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditRepository $audit,
    ) {
    }

    public function collection(Request $request, Response $response): Response
    {
        if ($request->getMethod() === 'GET') {
            return Json::data($response, $this->users->list());
        }

        $b = (array) $request->getParsedBody();
        $username = Validator::str($b['username'] ?? '', 64);
        $password = is_string($b['password'] ?? null) ? $b['password'] : '';
        $role = Validator::enum($b['role'] ?? 'user', ['admin', 'user'], 'user');
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $username)) {
            return Json::error($response, 422, 'validation_failed', 'invalid username');
        }
        if (strlen($password) < 12) {
            return Json::error($response, 422, 'validation_failed', 'password must be at least 12 characters');
        }
        $user = $this->users->create($username,
            password_hash($password, PASSWORD_ARGON2ID), $role, Validator::email($b['email'] ?? null));
        if ($user === null) {
            return Json::error($response, 409, 'conflict', 'username already exists');
        }
        $this->audit->log($request, 'user.create', ['username' => $username, 'role' => $role]);
        return Json::data($response, $user, 201);
    }

    public function item(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $target = $this->users->findById($id);
        if ($target === null) {
            return Json::error($response, 404, 'not_found', 'unknown user');
        }
        $self = $request->getAttribute('user');

        if ($request->getMethod() === 'DELETE') {
            if ((int) $self->id === $id) {
                return Json::error($response, 409, 'conflict', 'you cannot delete your own account');
            }
            if ($target->role === 'admin' && $this->users->countAdmins() <= 1) {
                return Json::error($response, 409, 'conflict', 'cannot remove the last admin');
            }
            $this->users->delete($id);
            $this->audit->log($request, 'user.delete', ['username' => $target->username]);
            return Json::data($response, ['deleted' => true]);
        }

        $b = (array) $request->getParsedBody();
        $fields = [];
        if (isset($b['role'])) {
            $role = Validator::enum($b['role'], ['admin', 'user'], $target->role);
            if ($target->role === 'admin' && $role !== 'admin' && $this->users->countAdmins() <= 1) {
                return Json::error($response, 409, 'conflict', 'cannot demote the last admin');
            }
            $fields['role'] = $role;
        }
        if (isset($b['is_active'])) {
            if ((int) $self->id === $id && !$b['is_active']) {
                return Json::error($response, 409, 'conflict', 'you cannot deactivate yourself');
            }
            $fields['is_active'] = (bool) $b['is_active'] ? 'true' : 'false';
        }
        if (isset($b['email'])) {
            $fields['email'] = Validator::email($b['email']);
        }
        if (is_string($b['password'] ?? null) && $b['password'] !== '') {
            if (strlen($b['password']) < 12) {
                return Json::error($response, 422, 'validation_failed', 'password must be at least 12 characters');
            }
            $fields['password_hash'] = password_hash($b['password'], PASSWORD_ARGON2ID);
        }
        $this->users->update($id, $fields);
        $this->audit->log($request, 'user.update', ['username' => $target->username]);
        return Json::data($response, ['updated' => true]);
    }
}
