<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DefaultUserRoles extends AbstractMigration
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
    public function up(): void
    {
        $rows = [
            [
                'id' => 1,
                'name' => 'admin'
            ],
            [
                'id' => 2,
                'name' => 'editor'
            ],
            [
                'id' => 3,
                'name' => 'user'
            ]
        ];

        $this->table('roles')->insert($rows)->saveData();

        // Alter 'users' table to set default roleId to 3 ('user')
        $table = $this->table('users');
        $table->changeColumn('role_id', 'integer', ['signed' => false, 'default' => 3])
            ->update();
    }

    public function down(): void
    {
        // Remove the default value from 'role_id' column
        $table = $this->table('users');
        $table->changeColumn('role_id', 'integer', ['signed' => false, 'default' => null])
            ->update();

        $this->execute('DELETE FROM roles WHERE id IN (1, 2, 3)');
    }
}
