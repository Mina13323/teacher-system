<?php

return [

    'models' => [

        /*
         * When using the "HasPermissions" trait from this package, we need to know which
         * Eloquent model should be used to retrieve your permissions. Of course, it is
         * often just the "Permission" model but you may use whatever you like.
         */

        'permission' => Spatie\Permission\Models\Permission::class,

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * Eloquent model should be used to retrieve your roles. Of course, it is
         * often just the "Role" model but you may use whatever you like.
         */

        'role' => Spatie\Permission\Models\Role::class,

    ],

    'table_names' => [

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your roles. We have chosen a basic
         * default value but you may easily change it to any table you like.
         */

        'roles' => 'roles',

        'permissions' => 'permissions',

        'model_has_permissions' => 'model_has_permissions',

        'model_has_roles' => 'model_has_roles',

        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [

        /*
         * Change this if you want to name the related pivots other than the defaults.
         */

        'role_pivot_key' => 'role_id',
        'permission_pivot_key' => 'permission_id',
        'model_morph_key' => 'model_id',

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * pivot table should be used. You may change this value if you have a custom
         * pivot table. This value is only used when using teams; it is ignored when
         * teams is false.
         */

        'team_foreign_key' => 'team_id',
    ],

    /*
     * By default all permissions will be cached for 24 hours unless a permission or
     * role is updated. This cache will be invalidated when a permission or role is
     * updated by the package.
     */

    'cache' => [
        'store' => 'default',

        'key' => 'spatie.permission.cache',

        'expiration_time' => 24 * 60 * 60,

        'model_key' => 'id',

        /*
         * Setting this to true will cache the permission name (and guard) when
         * the permission is first loaded, avoiding the need to hit the database
         * each time. This is not set by default.
         */

        'cache_prefix' => 'spatie.permission.cache.',
    ],

    /*
     * When enabled, the package will register a "before" callback on the Gate which
     * gives a super-admin role the ability to perform everything.
     */

    'register_permission_check_method' => true,

    'register_octane_reset' => false,

    /*
     * Teams feature. When true, permissions are scoped by a team foreign key.
     */

    'teams' => false,

    /*
     * When true, the package will register a "before" callback on the Gate which
     * gives a super-admin role the ability to perform everything.
     */

    'display_permission_in_exception' => false,

    'enable_wildcard_permission' => false,

    'testing' => false,
];
