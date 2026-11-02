<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102144211 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE payment_status_history (id INT AUTO_INCREMENT NOT NULL, from_status VARCHAR(20) DEFAULT NULL, to_status VARCHAR(20) NOT NULL, note VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, changed_by_id INT DEFAULT NULL, payment_id INT NOT NULL, INDEX IDX_CA42BDCA828AD0A0 (changed_by_id), INDEX IDX_CA42BDCA4C3A3BB (payment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE payment_status_history ADD CONSTRAINT FK_CA42BDCA828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE payment_status_history ADD CONSTRAINT FK_CA42BDCA4C3A3BB FOREIGN KEY (payment_id) REFERENCES payment (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE payment_status_history DROP FOREIGN KEY FK_CA42BDCA828AD0A0');
        $this->addSql('ALTER TABLE payment_status_history DROP FOREIGN KEY FK_CA42BDCA4C3A3BB');
        $this->addSql('DROP TABLE payment_status_history');
    }
}
