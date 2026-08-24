<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddGroupMembershipValidity extends AbstractMigration
{
    public function change(): void
    {
        $this->table('user_group')
            ->addColumn('valid_to', 'timestamp', ['null' => true, 'default' => null, 'after' => 'group_id'])
            ->update();

        $this->table('group_activation_tokens')
            ->addColumn('valid_for_days', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'after' => 'valid_to',
            ])
            ->update();
    }
}
