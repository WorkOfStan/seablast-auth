<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Migration\IrreversibleMigrationException;

final class HashAuthTokens extends AbstractMigration
{
    public function up(): void
    {
        $options = $this->getAdapterOptions();
        $tablePrefix = $options['table_prefix'] ?? '';
        if (!is_string($tablePrefix)) {
            throw new \RuntimeException('Table prefix MUST be a string.');
        }
        $this->execute("UPDATE `" . $tablePrefix . "email_token` SET token = SHA2(token, 256) WHERE token NOT REGEXP '^[0-9a-f]{64}$'");
        $this->execute("UPDATE `" . $tablePrefix . "session_user` SET token = SHA2(token, 256) WHERE token NOT REGEXP '^[0-9a-f]{64}$'");
        $this->addUniqueTokenIndex('email_token', 'idx_email_token_token_unique');
        $this->addUniqueTokenIndex('session_user', 'idx_session_user_token_unique');
    }

    public function down(): void
    {
        $this->removeUniqueTokenIndex('email_token', 'idx_email_token_token_unique');
        $this->removeUniqueTokenIndex('session_user', 'idx_session_user_token_unique');
        throw new IrreversibleMigrationException(
            'Token hashes cannot be reversed to plaintext bearer tokens.'
        );
    }

    private function addUniqueTokenIndex(string $tableName, string $indexName): void
    {
        $table = $this->table($tableName);
        if (!$table->hasIndex(['token']) && !$table->hasIndexByName($indexName)) {
            $table->addIndex(['token'], ['unique' => true, 'name' => $indexName])->update();
        }
    }

    private function removeUniqueTokenIndex(string $tableName, string $indexName): void
    {
        $table = $this->table($tableName);
        if ($table->hasIndexByName($indexName)) {
            $table->removeIndexByName($indexName)->update();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getAdapterOptions(): array
    {
        return $this->readAdapterOptions($this->getAdapter());
    }

    /**
     * @param mixed $adapter
     * @return array<string, mixed>
     */
    private function readAdapterOptions($adapter): array
    {
        if (!$adapter instanceof AdapterInterface) {
            throw new \RuntimeException('Migration adapter is not initialized.');
        }

        return $adapter->getOptions();
    }
}
