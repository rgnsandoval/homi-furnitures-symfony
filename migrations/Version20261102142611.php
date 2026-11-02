<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102142611 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE product (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, sku VARCHAR(60) NOT NULL, slug VARCHAR(180) NOT NULL, description LONGTEXT NOT NULL, price NUMERIC(10, 2) NOT NULL, cost_price NUMERIC(10, 2) NOT NULL, sale_price NUMERIC(10, 2) DEFAULT NULL, status VARCHAR(20) NOT NULL, is_featured TINYINT NOT NULL, image VARCHAR(255) DEFAULT NULL, material VARCHAR(60) NOT NULL, default_color VARCHAR(40) DEFAULT NULL, width_cm NUMERIC(6, 1) DEFAULT NULL, depth_cm NUMERIC(6, 1) DEFAULT NULL, height_cm NUMERIC(6, 1) DEFAULT NULL, weight_kg NUMERIC(6, 2) DEFAULT NULL, assembly_required TINYINT NOT NULL, warranty_months INT DEFAULT NULL, lead_time_days INT NOT NULL, specifications JSON DEFAULT NULL, created_at DATETIME NOT NULL, category_id INT NOT NULL, brand_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_D34A04ADF9038C4 (sku), UNIQUE INDEX UNIQ_D34A04AD989D9B62 (slug), INDEX IDX_D34A04AD12469DE2 (category_id), INDEX IDX_D34A04AD44F5D008 (brand_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD12469DE2 FOREIGN KEY (category_id) REFERENCES category (id)');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD44F5D008 FOREIGN KEY (brand_id) REFERENCES brand (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04AD12469DE2');
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04AD44F5D008');
        $this->addSql('DROP TABLE product');
    }
}
