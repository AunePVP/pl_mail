<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FORK (FORK.md): the search reranker's address, on the fork's own AI options.
 */
final class Version20261102210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fork: add fork_ai_options.rerank_url (the search reranker, set in Admin → AI)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fork_ai_options ADD rerank_url TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fork_ai_options DROP rerank_url');
    }
}
