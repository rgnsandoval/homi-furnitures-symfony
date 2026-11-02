<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102144114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE order_status_history (id INT AUTO_INCREMENT NOT NULL, from_status VARCHAR(20) DEFAULT NULL, to_status VARCHAR(20) NOT NULL, note VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, changed_by_id INT DEFAULT NULL, parent_order_id INT NOT NULL, INDEX IDX_471AD77E828AD0A0 (changed_by_id), INDEX IDX_471AD77E1252C1E9 (parent_order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE order_status_history ADD CONSTRAINT FK_471AD77E828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE order_status_history ADD CONSTRAINT FK_471AD77E1252C1E9 FOREIGN KEY (parent_order_id) REFERENCES orders (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE order_status_history DROP FOREIGN KEY FK_471AD77E828AD0A0');
        $this->addSql('ALTER TABLE order_status_history DROP FOREIGN KEY FK_471AD77E1252C1E9');
        $this->addSql('DROP TABLE order_status_history');
    }
}
