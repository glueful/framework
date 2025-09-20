<?php

return [
    // Decision strategy: 'affirmative' | 'consensus' | 'unanimous'
    'strategy' => 'affirmative',
    // How to treat provider if present: 'replace' | 'combine'
    'provider_mode' => 'replace',
    // If true, a later GRANT can override an earlier DENY
    'allow_deny_override' => false,
    // Optional super roles that grant all permissions (opt-in)
    'super_roles' => [],

    // Minimal config-first RBAC for RoleVoter (no DB needed)
    'roles' => [
        // 'admin'  => ['*'],
        // 'editor' => ['post.create', 'post.edit.own'],
        // 'user'   => ['post.create'],
    ],

    // Resource slug => Policy class (optional)
    'policies' => [
        // 'posts' => App\Auth\Policies\PostPolicy::class,
    ],
];
