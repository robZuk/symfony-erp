<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261030122717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Last VIES (VAT-UE) check of each partner';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE partner_partner ADD vat_eu_valid BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE partner_partner ADD vat_eu_name VARCHAR(300) DEFAULT NULL');
        $this->addSql('ALTER TABLE partner_partner ADD vat_eu_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE partner_partner DROP vat_eu_valid');
        $this->addSql('ALTER TABLE partner_partner DROP vat_eu_name');
        $this->addSql('ALTER TABLE partner_partner DROP vat_eu_checked_at');
    }
}
