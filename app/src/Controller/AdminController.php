<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\AuthorizerDefault;
use App\Auth\Exception\LockoutPrevented;
use App\Auth\Exception\ValidationFailed;
use App\Auth\Identity;
use App\Auth\SecurityController;
use App\Auth\WebAuth;
use App\Config\ConfigForm;
use App\Config\ConfigSchema;
use App\Config\ConfigStore;
use App\Exception\NotFoundException;
use App\Http\Request;
use App\Http\Response;
use App\View\View;
use RuntimeException;

/** Management of accounts, roles, the mapping between them, and the application configuration. */
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly SecurityController $security,
        private readonly WebAuth $webAuth,
        private readonly string $templateDir,
        private readonly ConfigStore $configStore,
    ) {
    }

    /* ---------- overview and accounts ---------- */

    public function index(Request $request): Response
    {
        $accounts = $this->security->getAccounts();
        $roles = $this->security->getRoleDefinitions();

        return $this->page($request, 'admin/index', [
            'accountCount' => count($accounts),
            'signInCount' => count(array_filter($accounts, static fn (array $a): bool => $a['can_sign_in'])),
            'roleCount' => count($roles),
            'permissionCount' => count($this->security->knownPermissions()),
            // Without authentication a password has no effect, so do not nag about the shipped default.
            'defaultPassword' => $this->security->isEnabled() && $this->security->usesDefaultPassword('admin'),
        ], 'Administration');
    }

    public function accounts(Request $request): Response
    {
        return $this->page($request, 'admin/accounts', ['accounts' => $this->security->getAccounts()], 'Accounts');
    }

    public function account(Request $request): Response
    {
        $account = $this->findAccount($request);

        return $this->page($request, 'admin/account', [
            'account' => $account,
            'isSelf' => $account['id'] === $this->currentAccount($request),
        ], $account['id']);
    }

    public function accountEdit(Request $request): Response
    {
        $id = $this->idParam($request->query('id'));
        $account = $id === '' ? null : $this->findAccount($request);

        return $this->accountForm($request, [
            'isNew' => $account === null,
            'id' => $account['id'] ?? '',
            'fullname' => $account['fullname'] ?? '',
            'email' => $account['email'] ?? '',
            'roles' => $account['roles'] ?? [],
        ], []);
    }

    public function accountSave(Request $request): Response
    {
        $post = $request->post;
        $isNew = ($post['mode'] ?? '') === 'new';
        $id = $this->idParam((string) ($post['id'] ?? ''));
        $roles = $this->stringList($post['roles'] ?? []);
        $form = [
            'isNew' => $isNew,
            'id' => $id,
            'fullname' => trim((string) ($post['fullname'] ?? '')),
            'email' => trim((string) ($post['email'] ?? '')),
            'roles' => $roles,
        ];

        $exists = $this->security->getPublicAccount($id) !== null;
        $errors = [];
        if ($isNew && $exists) {
            $errors['id'] = 'An account with this ID already exists.';
        } elseif (!$isNew && !$exists) {
            throw new NotFoundException('This account does not exist (anymore).');
        }

        $data = ['fullname' => $form['fullname'], 'email' => $form['email']];
        if ($isNew) {
            if (empty($post['no_password'])) {
                $password = (string) ($post['password'] ?? '');
                if ($password !== (string) ($post['confirm_password'] ?? '')) {
                    $errors['confirm_password'] = 'The two passwords are not the same.';
                }
                $data['password'] = $password;
            } else {
                $data['password'] = '!';
            }
        }

        if ($errors === []) {
            try {
                $this->security->saveAccount($id, $data, $roles);
                $request->session()->flash('success', sprintf('Account "%s" has been saved.', $id));

                return $this->redirect($request->basePath . '/admin/account?id=' . rawurlencode($id), 303);
            } catch (ValidationFailed $e) {
                $errors = $e->errors;
            } catch (LockoutPrevented $e) {
                $errors['form'] = $e->getMessage();
            }
        }

        return $this->accountForm($request, $form, $errors, 422);
    }

    public function accountPassword(Request $request): Response
    {
        return $this->passwordForm($request, $this->findAccount($request), []);
    }

    public function accountPasswordSave(Request $request): Response
    {
        $account = $this->findAccount($request, (string) ($request->post['id'] ?? ''));
        $id = $account['id'];
        $self = $id === $this->currentAccount($request);
        $errors = [];

        if (!empty($request->post['disable'])) {
            if ($self) {
                $errors['form'] = 'You cannot disable your own sign-in.';
            }
            $password = '!';
        } else {
            $password = (string) ($request->post['new_password'] ?? '');
            if ($password !== (string) ($request->post['confirm_password'] ?? '')) {
                $errors['confirm_password'] = 'The two passwords are not the same.';
            }
        }

        if ($errors === []) {
            try {
                $this->security->changePassword($id, $password);
                if ($self) {
                    $this->webAuth->refreshSession($request->session(), $id);
                }
                $request->session()->flash('success', $password === '!'
                    ? sprintf('Sign-in for "%s" has been disabled.', $id)
                    : sprintf('The password of "%s" has been changed.', $id));

                return $this->redirect($request->basePath . '/admin/account?id=' . rawurlencode($id), 303);
            } catch (ValidationFailed $e) {
                $errors = ['new_password' => $e->errors['password'] ?? $e->getMessage()];
            } catch (LockoutPrevented $e) {
                $errors['form'] = $e->getMessage();
            }
        }

        return $this->passwordForm($request, $account, $errors, 422);
    }

    public function accountDelete(Request $request): Response
    {
        $account = $this->findAccount($request, (string) ($request->post['id'] ?? ''));
        $session = $request->session();

        if ($account['id'] === $this->currentAccount($request)) {
            $session->flash('danger', 'You cannot delete the account you are signed in with.');

            return $this->redirect($request->basePath . '/admin/account?id=' . rawurlencode($account['id']), 303);
        }

        try {
            $this->security->deleteAccount($account['id']);
            $session->flash('success', sprintf('Account "%s" has been deleted.', $account['id']));
        } catch (LockoutPrevented $e) {
            $session->flash('danger', $e->getMessage());
        }

        return $this->redirect($request->basePath . '/admin/accounts', 303);
    }

    /* ---------- roles ---------- */

    public function roles(Request $request): Response
    {
        $roles = $this->security->getRoleDefinitions();
        $members = [];
        foreach (array_keys($roles) as $roleId) {
            $members[$roleId] = $this->security->accountsWithRole((string) $roleId);
        }

        return $this->page($request, 'admin/roles', ['roles' => $roles, 'members' => $members], 'Roles');
    }

    public function roleEdit(Request $request): Response
    {
        $id = $this->idParam($request->query('id'));
        if ($id === '') {
            return $this->roleForm($request, ['isNew' => true, 'id' => '', 'permissions' => [], 'extra' => ''], []);
        }

        $permissions = $this->security->getRoleDefinitions()[$id] ?? throw new NotFoundException('This role does not exist (anymore).');

        return $this->roleForm($request, ['isNew' => false, 'id' => $id, 'permissions' => $permissions, 'extra' => ''], []);
    }

    public function roleSave(Request $request): Response
    {
        $post = $request->post;
        $isNew = ($post['mode'] ?? '') === 'new';
        $id = $this->idParam((string) ($post['id'] ?? ''));
        $selected = $this->stringList($post['permissions'] ?? []);
        $extra = (string) ($post['extra'] ?? '');
        $permissions = array_values(array_unique([...$selected, ...(preg_split('/[\s,]+/', $extra, -1, PREG_SPLIT_NO_EMPTY) ?: [])]));
        $form = ['isNew' => $isNew, 'id' => $id, 'permissions' => $permissions, 'extra' => $extra];

        $exists = isset($this->security->getRoleDefinitions()[$id]);
        $errors = [];
        if ($isNew && $exists) {
            $errors['id'] = 'A role with this ID already exists.';
        } elseif (!$isNew && !$exists) {
            throw new NotFoundException('This role does not exist (anymore).');
        }
        if ($permissions === []) {
            $errors['permissions'] = 'Select at least one permission. To remove the role, delete it instead.';
        }

        if ($errors === []) {
            try {
                $this->security->saveRole($id, $permissions);
                $request->session()->flash('success', sprintf('Role "%s" has been saved.', $id));

                return $this->redirect($request->basePath . '/admin/roles', 303);
            } catch (ValidationFailed $e) {
                $errors = $e->errors;
            } catch (LockoutPrevented $e) {
                $errors['form'] = $e->getMessage();
            }
        }

        return $this->roleForm($request, $form, $errors, 422);
    }

    public function roleDelete(Request $request): Response
    {
        $id = $this->idParam((string) ($request->post['id'] ?? ''));
        if (!isset($this->security->getRoleDefinitions()[$id])) {
            throw new NotFoundException('This role does not exist (anymore).');
        }

        $session = $request->session();
        try {
            $this->security->deleteRole($id);
            $session->flash('success', sprintf('Role "%s" has been deleted and removed from all accounts.', $id));
        } catch (ValidationFailed | LockoutPrevented $e) {
            $session->flash('danger', $e->getMessage());
        }

        return $this->redirect($request->basePath . '/admin/roles', 303);
    }

    /* ---------- configuration ---------- */

    public function config(Request $request): Response
    {
        $defaults = $this->configStore->defaults();

        return $this->configPage($request, $this->configStore->current(), $defaults, []);
    }

    public function configSave(Request $request): Response
    {
        $defaults = $this->configStore->defaults();
        $current = $this->configStore->current();
        $posted = $request->post['config'] ?? [];
        $result = (new ConfigForm())->parse($defaults, $current, is_array($posted) ? $posted : []);

        if ($result['errors'] === []) {
            try {
                $this->configStore->save($result['values']);
                $request->session()->flash('success', 'The configuration has been saved. Only the differences from the defaults were written.');

                return $this->redirect($request->basePath . '/admin/config', 303);
            } catch (RuntimeException $e) {
                $result['errors']['form'] = 'The configuration could not be saved: ' . $e->getMessage();
            }
        }

        return $this->configPage($request, $result['values'], $defaults, $result['errors'], 422);
    }

    /* ---------- helpers ---------- */

    /**
     * @param array<string, mixed>  $form
     * @param array<string, string> $errors
     */
    private function accountForm(Request $request, array $form, array $errors, int $status = 200): Response
    {
        return $this->page($request, 'admin/account_edit', [
            'form' => $form,
            'errors' => $errors,
            'allRoles' => array_keys($this->security->getRoleDefinitions()),
        ], $form['isNew'] ? 'New account' : 'Edit ' . $form['id'], $status);
    }

    /**
     * @param array<string, mixed>  $account
     * @param array<string, string> $errors
     */
    private function passwordForm(Request $request, array $account, array $errors, int $status = 200): Response
    {
        return $this->page($request, 'admin/account_password', [
            'account' => $account,
            'isSelf' => $account['id'] === $this->currentAccount($request),
            'errors' => $errors,
        ], 'Password of ' . $account['id'], $status);
    }

    /**
     * @param array<string, mixed>  $form
     * @param array<string, string> $errors
     */
    private function roleForm(Request $request, array $form, array $errors, int $status = 200): Response
    {
        $known = $this->security->knownPermissions();

        return $this->page($request, 'admin/role_edit', [
            'form' => $form,
            'errors' => $errors,
            'known' => $known,
            // permissions of the role that are not in the list of known ones yet (typed in by hand)
            'custom' => array_values(array_diff($form['permissions'], $known)),
            'members' => $form['isNew'] ? [] : $this->security->accountsWithRole($form['id']),
            'isProtected' => $form['id'] === AuthorizerDefault::ALL_ROLE,
        ], $form['isNew'] ? 'New role' : 'Role ' . $form['id'], $status);
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, mixed>  $defaults
     * @param array<string, string> $errors
     */
    private function configPage(Request $request, array $values, array $defaults, array $errors, int $status = 200): Response
    {
        return $this->page($request, 'admin/config', [
            'values' => $values,
            'defaults' => $defaults,
            'errors' => $errors,
            'groups' => ConfigSchema::groups(array_keys($defaults)),
        ], 'Configuration', $status);
    }

    /** @param array<string, mixed> $vars */
    private function page(Request $request, string $template, array $vars, string $title, int $status = 200): Response
    {
        $html = View::forRequest($this->templateDir, $request)
            ->page($template, $vars, $title . ' – Administration – ' . $this->appTitle($request), 'admin', ['admin.js']);

        return $this->html($html, $status);
    }

    /** @return array<string, mixed> */
    private function findAccount(Request $request, ?string $id = null): array
    {
        $account = $this->security->getPublicAccount($this->idParam($id ?? $request->query('id')));

        return $account ?? throw new NotFoundException('This account does not exist (anymore).');
    }

    private function currentAccount(Request $request): ?string
    {
        /** @var Identity $identity */
        $identity = $request->attribute('identity');

        return $identity->accountId;
    }

    private function idParam(string $value): string
    {
        return strtolower(trim($value));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_unique(array_filter(array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $value)))) : [];
    }
}
