<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102144817 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE return_request (id INT AUTO_INCREMENT NOT NULL, return_reason LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, photo VARCHAR(255) DEFAULT NULL, admin_note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, parent_order_id INT NOT NULL, customer_id INT NOT NULL, INDEX IDX_2DBF9D401252C1E9 (parent_order_id), INDEX IDX_2DBF9D409395C3F3 (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE return_request ADD CONSTRAINT FK_2DBF9D401252C1E9 FOREIGN KEY (parent_order_id) REFERENCES orders (id)');
        $this->addSql('ALTER TABLE return_request ADD CONSTRAINT FK_2DBF9D409395C3F3 FOREIGN KEY (customer_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE return_request DROP FOREIGN KEY FK_2DBF9D401252C1E9');
        $this->addSql('ALTER TABLE return_request DROP FOREIGN KEY FK_2DBF9D409395C3F3');
        $this->addSql('DROP TABLE return_request');
    }
}
