<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102144719 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE stock_movement (id INT AUTO_INCREMENT NOT NULL, quantity_change INT NOT NULL, movement_reason VARCHAR(20) NOT NULL, note VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, variant_id INT NOT NULL, admin_user_id INT DEFAULT NULL, INDEX IDX_BB1BC1B53B69A9AF (variant_id), INDEX IDX_BB1BC1B56352511C (admin_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B53B69A9AF FOREIGN KEY (variant_id) REFERENCES product_variant (id)');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B56352511C FOREIGN KEY (admin_user_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stock_movement DROP FOREIGN KEY FK_BB1BC1B53B69A9AF');
        $this->addSql('ALTER TABLE stock_movement DROP FOREIGN KEY FK_BB1BC1B56352511C');
        $this->addSql('DROP TABLE stock_movement');
    }
}
