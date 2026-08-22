<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A single, homogeneous tag type covers both functional/navigational tags
 * and whimsical easter-egg tags (spec.md -- Domain Model: Tag). Merge
 * (combining duplicate/synonymous tags) is explicitly deferred, not v1.
 */
final class CreateTagsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('tags', ['id' => 'id'])
            ->addColumn('name', 'string', ['limit' => 64])
            ->addIndex(['name'], ['unique' => true])
            ->create();
    }
}
