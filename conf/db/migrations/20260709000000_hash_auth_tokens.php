<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class HashAuthTokens extends AbstractMigration
{
    public function up(): void
    {
        $this->hashExistingTokens('email_token');
        $this->hashExistingTokens('session_user');
        $this->addUniqueTokenIndex('email_token', 'idx_email_token_token_unique');
        $this->addUniqueTokenIndex('session_user', 'idx_session_user_token_unique');
    }

    public function down(): void
    {
        $this->removeUniqueTokenIndex('email_token', 'idx_email_token_token_unique');
        $this->removeUniqueTokenIndex('session_user', 'idx_session_user_token_unique');
        // Token hashes cannot be reversed to plaintext bearer tokens.
    }

    private function addUniqueTokenIndex(string $tableName, string $indexName): void
    {
        $table = $this->table($tableName);
        if (!$table->hasIndex(['token']) && !$table->hasIndexByName($indexName)) {
            $table->addIndex(['token'], ['unique' => true, 'name' => $indexName])->update();
        }
    }

    private function hashExistingTokens(string $tableName): void
    {
        $this->execute(
            'UPDATE ' . $this->quoteAuthTableName($tableName)
            . " SET token = SHA2(token, 256) WHERE token NOT REGEXP '^[0-9a-f]{64}$'"
        );
    }

    private function removeUniqueTokenIndex(string $tableName, string $indexName): void
    {
        $table = $this->table($tableName);
        if ($table->hasIndexByName($indexName)) {
            $table->removeIndexByName($indexName)->update();
        }
    }

    private function quoteAuthTableName(string $tableName): string
    {
        return '`' . str_replace('`', '``', $this->tableNameWithConfiguredAffixes($tableName)) . '`';
    }

    private function tableNameWithConfiguredAffixes(string $tableName): string
    {
        $adapter = $this->getAdapter();
        if ($adapter === null) {
            throw new \LogicException('A database adapter is required to resolve table affixes.');
        }
        $prefix = $adapter->hasOption('table_prefix') ? $adapter->getOption('table_prefix') : '';
        $suffix = $adapter->hasOption('table_suffix') ? $adapter->getOption('table_suffix') : '';
        if (!is_string($prefix) || !is_string($suffix)) {
            throw new \UnexpectedValueException('Database table prefix and suffix must be strings.');
        }
        return $prefix . $tableName . $suffix;
    }
}
