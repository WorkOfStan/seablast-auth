<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * phinx migration for following tables:
 * - roles with fields name:string, created:timestamp
 * - users with fields email:string, created:timestamp, roleId:int (related to roles.id)
 * - email_token with fields email:string (related to users.email), token:string, created:timestamp
 * - session_user with fields userId:int (related to users.id), token:string, updated:timestamp
 */
final class User extends AbstractMigration
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
        $roles = $this->table('roles');
        $roles->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('created', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->create();

        $users = $this->table('users');
        $users->addColumn('email', 'string', ['limit' => 255])
            ->addColumn('created', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('last_login', 'timestamp', ['null' => true, 'default' => null])
            ->addColumn('role_id', 'integer', ['signed' => false])
            ->addForeignKey('role_id', 'roles', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addIndex(['email'], ['unique' => true]) // Add a unique index on the 'email' column
            ->create();

        $emailToken = $this->table('email_token');
        $emailToken->addColumn('email', 'string', ['limit' => 255])
            ->addColumn('token', 'string', ['limit' => 255])
            ->addColumn('created', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('email', 'users', 'email', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $sessionUser = $this->table('session_user');
        $sessionUser->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('token', 'string', ['limit' => 255])
            ->addColumn('created', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
