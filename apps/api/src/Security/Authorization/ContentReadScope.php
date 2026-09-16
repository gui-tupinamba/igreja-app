<?php

declare(strict_types=1);

namespace App\Security\Authorization;

/** SQL shared by detail policies and lists, applied before counting/pagination. */
final class ContentReadScope
{
    public static function predicate(string $table, string $alias = 'c'): string
    {
        if (!in_array($table, ['posts', 'events', 'ministry_schedules', 'comments', 'ministries'], true)
            || preg_match('/\A[a-z][a-z0-9_]*\z/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Unsupported content scope.');
        }

        if ($table === 'comments') {
            $post = self::predicate('posts', 'scope_post');

            return "$alias.status = 'VISIBLE' AND EXISTS (SELECT 1 FROM posts scope_post WHERE scope_post.id = $alias.post_id AND ($post))";
        }

        if ($table === 'ministries') {
            return "$alias.status = 'ACTIVE' AND EXISTS (SELECT 1 FROM users scope_actor WHERE scope_actor.id = :actor_id AND scope_actor.status = 'ACTIVE')";
        }

        $state = $table === 'posts'
            ? "$alias.status = 'PUBLISHED' AND $alias.published_at <= CURRENT_TIMESTAMP"
            : "$alias.status IN ('PUBLISHED', 'CANCELLED')";

        return <<<SQL
            ($state)
            AND ($alias.ministry_id IS NULL OR EXISTS (
                SELECT 1 FROM ministries scope_ministry
                WHERE scope_ministry.id = $alias.ministry_id AND scope_ministry.status = 'ACTIVE'
            ))
            AND EXISTS (
                SELECT 1 FROM users scope_actor
                WHERE scope_actor.id = :actor_id AND scope_actor.status = 'ACTIVE'
                AND (
                    $alias.visibility = 'PUBLIC'
                    OR ($alias.visibility = 'MINISTRY_MEMBERS' AND (
                        scope_actor.role IN ('ADMIN', 'PASTOR')
                        OR EXISTS (
                            SELECT 1 FROM user_ministries scope_membership
                            WHERE scope_membership.user_id = scope_actor.id
                            AND scope_membership.ministry_id = $alias.ministry_id
                            AND scope_membership.status = 'ACTIVE'
                        )
                    ))
                )
            )
            SQL;
    }
}
