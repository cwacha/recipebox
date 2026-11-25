<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Human readable descriptions of the permissions this application checks. Purely informational: permissions
 * exist because a role mentions them, and the role "all" lists every one of them.
 */
final class PermissionCatalog
{
    public const DESCRIPTIONS = [
        'ALL' => 'Everything, including permissions added later',
        'show' => 'Read recipes (always granted to every identified account)',
        '/' => 'Recipe list and search',
        '/recipes' => 'Open a recipe',
        '/recipes/new' => 'Open the form for a new recipe',
        '/recipes/edit' => 'Open the form to edit a recipe',
        '/import' => 'Open the JSON import page',
        '/account/password' => 'Change the own password',
        '/admin' => 'Administration overview',
        '/admin/accounts' => 'List of accounts',
        '/admin/account' => 'Details of an account',
        '/admin/account/edit' => 'Form to create or edit an account and its roles',
        '/admin/account/password' => 'Form to set the password of an account',
        '/admin/roles' => 'List of roles and their permissions',
        '/admin/role/edit' => 'Form to create or edit a role',
        'recipe_create' => 'Save a new recipe',
        'recipe_edit' => 'Save changes to a recipe',
        'recipe_delete' => 'Delete a recipe',
        'recipe_import' => 'Import recipes from JSON',
        'recipe_export' => 'Download a recipe as JSON',
        'recipe_export_all' => 'Download all recipes as JSON',
        'image_upload' => 'Upload a picture',
        'account_edit' => 'Save an account and its role assignment',
        'account_password' => 'Set the password of an account',
        'account_delete' => 'Delete an account',
        'role_edit' => 'Save a role and its permissions',
        'role_delete' => 'Delete a role',
    ];

    public static function describe(string $permission): string
    {
        return self::DESCRIPTIONS[$permission] ?? 'Custom permission';
    }

    /** Views (pages) start with a slash; everything else is an action. */
    public static function isView(string $permission): bool
    {
        return str_starts_with($permission, '/');
    }
}
