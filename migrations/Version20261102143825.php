<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102143825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE orders (id INT AUTO_INCREMENT NOT NULL, order_number VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL, shipping_method VARCHAR(20) NOT NULL, subtotal NUMERIC(10, 2) NOT NULL, shipping_fee NUMERIC(10, 2) NOT NULL, assembly_fee NUMERIC(10, 2) NOT NULL, discount NUMERIC(10, 2) NOT NULL, total NUMERIC(10, 2) NOT NULL, assembly_requested TINYINT NOT NULL, estimated_delivery_date DATE DEFAULT NULL, notes LONGTEXT DEFAULT NULL, tracking_number VARCHAR(80) DEFAULT NULL, internal_note LONGTEXT DEFAULT NULL, cancel_reason VARCHAR(255) DEFAULT NULL, idempotency_key VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, customer_id INT NOT NULL, shipping_address_id INT NOT NULL, discount_code_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_E52FFDEE551F0F81 (order_number), UNIQUE INDEX UNIQ_E52FFDEE7FD1C147 (idempotency_key), INDEX IDX_E52FFDEE9395C3F3 (customer_id), INDEX IDX_E52FFDEE4D4CFF2B (shipping_address_id), INDEX IDX_E52FFDEE91D29306 (discount_code_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEE9395C3F3 FOREIGN KEY (customer_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEE4D4CFF2B FOREIGN KEY (shipping_address_id) REFERENCES address (id)');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEE91D29306 FOREIGN KEY (discount_code_id) REFERENCES discount_code (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE orders DROP FOREIGN KEY FK_E52FFDEE9395C3F3');
        $this->addSql('ALTER TABLE orders DROP FOREIGN KEY FK_E52FFDEE4D4CFF2B');
        $this->addSql('ALTER TABLE orders DROP FOREIGN KEY FK_E52FFDEE91D29306');
        $this->addSql('DROP TABLE orders');
    }
}
