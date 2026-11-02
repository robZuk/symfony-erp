<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product search that forgives typos and missing Polish letters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
        // unaccent() depends on a dictionary, so PostgreSQL treats it as STABLE; an index needs IMMUTABLE.
        // The dictionary is named in full, which makes the result independent of search_path.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION search_normalize(text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE
            AS $$ SELECT lower(public.unaccent('public.unaccent'::regdictionary, $1)) $$
            SQL);
        // Serves the substring match; the expression must stay identical to the one in ProductRepository
        $this->addSql("CREATE INDEX catalog_product_search_idx ON catalog_product USING gin (search_normalize(name || ' ' || sku) gin_trgm_ops)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX catalog_product_search_idx');
        $this->addSql('DROP FUNCTION search_normalize(text)');
        $this->addSql('DROP EXTENSION unaccent');
        $this->addSql('DROP EXTENSION pg_trgm');
    }
}
