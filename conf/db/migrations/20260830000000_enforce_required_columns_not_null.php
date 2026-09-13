<?php

declare(strict_types=1);

use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * @phpstan-type ColumnOptions array{
 *     identity?: bool,
 *     signed?: bool,
 *     limit?: int,
 *     default?: int|string,
 *     null: bool
 * }
 * @phpstan-type ColumnDefinition array{type: string, options: ColumnOptions}
 */
final class EnforceRequiredColumnsNotNull extends AbstractMigration
{
    public function up(): void
    {
        $columnDefinitions = $this->columnDefinitions();
        $this->assertRequiredColumnsContainNoNulls($columnDefinitions);

        try {
            $this->removeForeignKeys();
            foreach ($columnDefinitions as $tableName => $columns) {
                $table = $this->table($tableName);
                foreach ($columns as $columnName => $definition) {
                    $table->changeColumn($columnName, $definition['type'], $definition['options']);
                }
                $table->update();
            }
        } finally {
            $this->addForeignKeys();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException(
            'Required NOT NULL constraints cannot be safely reverted because prior nullability depends '
            . 'on the Phinx version.'
        );
    }

    /**
     * @return array<string, array<string, ColumnDefinition>>
     */
    private function columnDefinitions(): array
    {
        return [
            'roles' => [
                'id' => [
                    'type' => 'integer',
                    'options' => ['identity' => true, 'signed' => false, 'null' => false],
                ],
                'name' => [
                    'type' => 'string',
                    'options' => ['limit' => 255, 'null' => false],
                ],
                'created' => [
                    'type' => 'timestamp',
                    'options' => ['default' => 'CURRENT_TIMESTAMP', 'null' => false],
                ],
            ],
            'users' => [
                'id' => [
                    'type' => 'integer',
                    'options' => ['identity' => true, 'signed' => false, 'null' => false],
                ],
                'email' => [
                    'type' => 'string',
                    'options' => ['limit' => 255, 'null' => false],
                ],
                'created' => [
                    'type' => 'timestamp',
                    'options' => ['default' => 'CURRENT_TIMESTAMP', 'null' => false],
                ],
                'role_id' => [
                    'type' => 'integer',
                    'options' => ['signed' => false, 'default' => 3, 'null' => false],
                ],
            ],
            'email_token' => [
                'id' => [
                    'type' => 'integer',
                    'options' => ['identity' => true, 'signed' => false, 'null' => false],
                ],
                'email' => [
                    'type' => 'string',
                    'options' => ['limit' => 255, 'null' => false],
                ],
                'token' => [
                    'type' => 'string',
                    'options' => ['limit' => 255, 'null' => false],
                ],
                'created' => [
                    'type' => 'timestamp',
                    'options' => ['default' => 'CURRENT_TIMESTAMP', 'null' => false],
                ],
            ],
            'session_user' => [
                'id' => [
                    'type' => 'integer',
                    'options' => ['identity' => true, 'signed' => false, 'null' => false],
                ],
                'user_id' => [
                    'type' => 'integer',
                    'options' => ['signed' => false, 'null' => false],
                ],
                'token' => [
                    'type' => 'string',
                    'options' => ['limit' => 255, 'null' => false],
                ],
                'created' => [
                    'type' => 'timestamp',
                    'options' => ['default' => 'CURRENT_TIMESTAMP', 'null' => false],
                ],
                'updated' => [
                    'type' => 'timestamp',
                    'options' => ['default' => 'CURRENT_TIMESTAMP', 'null' => false],
                ],
            ],
            'group' => [
                'id' => [
                    'type' => 'integer',
                    'options' => ['identity' => true, 'signed' => false, 'null' => false],
                ],
                'created' => [
                    'type' => 'timestamp',
                    'options' => ['default' => 'CURRENT_TIMESTAMP', 'null' => false],
                ],
                'name_public' => [
                    'type' => 'string',
                    'options' => ['limit' => 255, 'null' => false],
                ],
            ],
            'user_group' => [
                'id' => [
                    'type' => 'integer',
                    'options' => ['identity' => true, 'signed' => false, 'null' => false],
                ],
                'created' => [
                    'type' => 'timestamp',
                    'options' => ['default' => 'CURRENT_TIMESTAMP', 'null' => false],
                ],
                'user_id' => [
                    'type' => 'integer',
                    'options' => ['signed' => false, 'null' => false],
                ],
                'group_id' => [
                    'type' => 'integer',
                    'options' => ['signed' => false, 'null' => false],
                ],
            ],
            'group_activation_tokens' => [
                'id' => [
                    'type' => 'integer',
                    'options' => ['identity' => true, 'signed' => false, 'null' => false],
                ],
                'created' => [
                    'type' => 'timestamp',
                    'options' => ['default' => 'CURRENT_TIMESTAMP', 'null' => false],
                ],
                'group_id' => [
                    'type' => 'integer',
                    'options' => ['signed' => false, 'null' => false],
                ],
                'valid_from' => [
                    'type' => 'datetime',
                    'options' => ['null' => false],
                ],
                'valid_to' => [
                    'type' => 'datetime',
                    'options' => ['null' => false],
                ],
                'token' => [
                    'type' => 'string',
                    'options' => ['limit' => 255, 'null' => false],
                ],
            ],
        ];
    }

    /**
     * @param array<string, array<string, ColumnDefinition>> $columnDefinitions
     */
    private function assertRequiredColumnsContainNoNulls(array $columnDefinitions): void
    {
        $adapter = $this->getInitializedAdapter();
        $nullableColumns = [];

        foreach ($columnDefinitions as $tableName => $columns) {
            $physicalTableName = $this->tableNameWithConfiguredAffixes($adapter, $tableName);
            $quotedTableName = $adapter->quoteTableName($physicalTableName);
            foreach (array_keys($columns) as $columnName) {
                $row = $adapter->fetchRow(
                    'SELECT 1 FROM ' . $quotedTableName
                    . ' WHERE ' . $adapter->quoteColumnName($columnName) . ' IS NULL LIMIT 1'
                );
                if ($row !== false) {
                    $nullableColumns[] = $tableName . '.' . $columnName;
                }
            }
        }

        if ($nullableColumns !== []) {
            throw new \RuntimeException(
                'Cannot enforce required NOT NULL columns because NULL values exist in: '
                . implode(', ', $nullableColumns) . '.'
            );
        }
    }

    private function removeForeignKeys(): void
    {
        $this->removeForeignKey('users', 'role_id');
        $this->removeForeignKey('email_token', 'email');
        $this->removeForeignKey('session_user', 'user_id');
        $this->removeForeignKey('user_group', 'user_id');
        $this->removeForeignKey('user_group', 'group_id');
        $this->removeForeignKey('group_activation_tokens', 'group_id');
    }

    private function removeForeignKey(string $tableName, string $columnName): void
    {
        $table = $this->table($tableName);
        if ($table->hasForeignKey($columnName)) {
            $table->dropForeignKey($columnName)->update();
        }
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey('users', 'role_id', 'roles', 'id');
        $this->addForeignKey('email_token', 'email', 'users', 'email');
        $this->addForeignKey('session_user', 'user_id', 'users', 'id');
        $this->addForeignKey('user_group', 'user_id', 'users', 'id');
        $this->addForeignKey('user_group', 'group_id', 'group', 'id');
        $this->addForeignKey('group_activation_tokens', 'group_id', 'group', 'id');
    }

    private function addForeignKey(
        string $tableName,
        string $columnName,
        string $referencedTableName,
        string $referencedColumnName
    ): void {
        $table = $this->table($tableName);
        if (!$table->hasForeignKey($columnName)) {
            $table->addForeignKey(
                $columnName,
                $referencedTableName,
                $referencedColumnName,
                ['delete' => 'CASCADE', 'update' => 'NO_ACTION']
            )->update();
        }
    }

    private function tableNameWithConfiguredAffixes(AdapterInterface $adapter, string $tableName): string
    {
        $options = $adapter->getOptions();
        $tablePrefix = $options['table_prefix'] ?? '';
        $tableSuffix = $options['table_suffix'] ?? '';
        if (!is_string($tablePrefix) || !is_string($tableSuffix)) {
            throw new \RuntimeException('Table prefix and suffix MUST be strings.');
        }

        return $tablePrefix . $tableName . $tableSuffix;
    }

    private function getInitializedAdapter(): AdapterInterface
    {
        return $this->requireInitializedAdapter($this->getAdapter());
    }

    /**
     * @param mixed $adapter
     */
    private function requireInitializedAdapter($adapter): AdapterInterface
    {
        if (!$adapter instanceof AdapterInterface) {
            throw new \RuntimeException('Migration adapter is not initialized.');
        }

        return $adapter;
    }
}
