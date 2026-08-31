<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Redesigned: absorbs the old story_archive table instead of keeping it
 * separate. A recipe can have many stories rows over time (history/
 * reversion is still wanted even with StorySubmission gone -- an admin or
 * an AI regeneration overwriting a story should still be revertible), but
 * only one is "live" at a time: recipes.story_id
 * (20260816120300_create_recipes_table.php) points at it, and every other
 * row for that recipe_id has a non-null `archived_at`.
 *
 * StorySubmission (and its whole submit/moderate workflow) is removed
 * entirely, not just repointed -- see spec.md, which needs updating to
 * match (Roles & Permissions previously listed submitting a StorySubmission
 * as a logged-in user's only privilege beyond Guest).
 *
 * `review_status` from the old stories table is dropped -- with the
 * translation/review pipeline collapsed onto recipes.status
 * ('draft'|'published'), there's no separate drafted-vs-admin-reviewed
 * state to track on the story text itself; it's just editable content on a
 * draft recipe until publish.
 */
final class CreateStoriesTable extends AbstractMigration
{
    public function change(): void
    {
        // *_id columns are unsigned to match Phinx's default `id` PK type on
        // MySQL; a signed/unsigned mismatch fails addForeignKey with
        // errno 150 (SQLite ignores signedness, so the local suite passed).
        $this->table('stories', ['id' => 'id'])
            ->addColumn('recipe_id', 'integer', ['signed' => false])
            ->addColumn('narrator', 'string', ['limit' => 255])
            ->addColumn('body', 'text')
            ->addColumn('author_user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_at', 'datetime')
            ->addColumn('archived_at', 'datetime', ['null' => true])
            ->addIndex(['recipe_id'])
            ->addForeignKey('recipe_id', 'recipes', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('author_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->create();
    }
}
