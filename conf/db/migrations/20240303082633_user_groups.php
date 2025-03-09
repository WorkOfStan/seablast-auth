<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UserGroups extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        $this->table('group')
            ->addColumn('created', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('name_public', 'string', ['limit' => 255])
            ->addColumn('internal_notes', 'text', ['null' => true])
            ->create();

        $this->table('user_group')
            ->addColumn('created', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addColumn('group_id', 'integer', ['signed' => false])
            ->addForeignKey('group_id', 'group', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addIndex(['user_id'])
            ->create();

        $this->table('group_activation_tokens')
            ->addColumn('created', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('group_id', 'integer', ['signed' => false])
            ->addForeignKey('group_id', 'group', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addColumn('valid_from', 'datetime')
            ->addColumn('valid_to', 'datetime')
            ->addColumn('token', 'string', ['limit' => 255])
            ->addIndex(['token'], ['unique' => true]) // Add a unique index on the 'token' column
            ->create();
    }
}
