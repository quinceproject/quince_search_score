<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\SearchScore;

use Db;

/**
 * Dostęp do dwóch tabel modułu: score produktów i dziennik fraz.
 *
 * `score` to jedna wspólna wartość, którą dolicza front (+1 za kliknięcie z kontekstu
 * wyszukiwania) i którą obsługa może nadpisać wprost w panelu.
 */
final class Repository
{
    private const TABLE_PRODUCTS = 'quince_search_score_products';
    private const TABLE_PHRASES = 'quince_search_score_phrases';

    public function installTables(): bool
    {
        $products = Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_PRODUCTS . '` (
                `id_score` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_product` INT UNSIGNED NOT NULL,
                `score` INT NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_score`),
                UNIQUE KEY `id_product` (`id_product`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );

        $phrases = Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_PHRASES . '` (
                `id_phrase` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `phrase` VARCHAR(255) NOT NULL,
                `count` INT UNSIGNED NOT NULL DEFAULT 1,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_phrase`),
                UNIQUE KEY `phrase` (`phrase`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );

        return (bool) $products && (bool) $phrases;
    }

    public function dropTables(): bool
    {
        $a = Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE_PRODUCTS . '`');
        $b = Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE_PHRASES . '`');

        return (bool) $a && (bool) $b;
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function products(
        int $idLang,
        int $idShop,
        string $search,
        int $category,
        ?int $scoreMin,
        ?int $scoreMax,
        string $orderBy,
        string $orderDir,
        int $page,
        int $perPage
    ): array {
        $sortMap = [
            'id_product' => 'p.id_product',
            'name' => 'pl.name',
            'category' => 'cl.name',
            'score' => 'COALESCE(ss.score, 0)',
        ];
        $sqlOrder = ($sortMap[$orderBy] ?? 'COALESCE(ss.score, 0)') . ' ' . ($orderDir === 'ASC' ? 'ASC' : 'DESC');
        if ($orderBy !== 'name') {
            $sqlOrder .= ', pl.name ASC';
        }

        $joins = '';
        $where = 'WHERE p.active = 1';

        if ($search !== '') {
            $where .= " AND pl.name LIKE '" . pSQL('%' . $search . '%') . "'";
        }

        if ($category > 0) {
            $joins .= ' INNER JOIN `' . _DB_PREFIX_ . 'category_product` cpf ON cpf.id_product = p.id_product';
            $where .= ' AND cpf.id_category = ' . $category;
        }

        if ($scoreMin !== null) {
            $where .= ' AND COALESCE(ss.score, 0) >= ' . $scoreMin;
        }
        if ($scoreMax !== null) {
            $where .= ' AND COALESCE(ss.score, 0) <= ' . $scoreMax;
        }

        $offset = ($page - 1) * $perPage;

        $sql = '
            SELECT p.id_product, pl.name, cl.name AS category, COALESCE(ss.score, 0) AS score
            FROM `' . _DB_PREFIX_ . 'product` p
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . $idShop . '
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON cl.id_category = p.id_category_default AND cl.id_lang = ' . $idLang . ' AND cl.id_shop = ' . $idShop . '
            LEFT JOIN `' . _DB_PREFIX_ . self::TABLE_PRODUCTS . '` ss ON ss.id_product = p.id_product
            ' . $joins . '
            ' . $where . '
            ORDER BY ' . $sqlOrder . '
            LIMIT ' . $offset . ', ' . $perPage;

        $countSql = '
            SELECT COUNT(DISTINCT p.id_product)
            FROM `' . _DB_PREFIX_ . 'product` p
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . $idShop . '
            LEFT JOIN `' . _DB_PREFIX_ . self::TABLE_PRODUCTS . '` ss ON ss.id_product = p.id_product
            ' . $joins . '
            ' . $where;

        return [
            'items' => Db::getInstance()->executeS($sql) ?: [],
            'total' => (int) Db::getInstance()->getValue($countSql),
        ];
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function phrases(string $search, string $orderBy, string $orderDir, int $page, int $perPage): array
    {
        $sortMap = [
            'id_phrase' => 'sp.id_phrase',
            'phrase' => 'sp.phrase',
            'count' => 'sp.count',
            'date_upd' => 'sp.date_upd',
        ];
        $sqlOrder = ($sortMap[$orderBy] ?? 'sp.count') . ' ' . ($orderDir === 'ASC' ? 'ASC' : 'DESC');

        $where = $search !== '' ? " WHERE sp.phrase LIKE '" . pSQL('%' . $search . '%') . "'" : '';

        $offset = ($page - 1) * $perPage;

        $sql = '
            SELECT sp.id_phrase, sp.phrase, sp.count, sp.date_upd
            FROM `' . _DB_PREFIX_ . self::TABLE_PHRASES . '` sp
            ' . $where . '
            ORDER BY ' . $sqlOrder . '
            LIMIT ' . $offset . ', ' . $perPage;

        $countSql = 'SELECT COUNT(sp.id_phrase) FROM `' . _DB_PREFIX_ . self::TABLE_PHRASES . '` sp ' . $where;

        return [
            'items' => Db::getInstance()->executeS($sql) ?: [],
            'total' => (int) Db::getInstance()->getValue($countSql),
        ];
    }

    public function setScore(int $idProduct, int $score): bool
    {
        $existing = (int) Db::getInstance()->getValue(
            'SELECT id_score FROM `' . _DB_PREFIX_ . self::TABLE_PRODUCTS . '` WHERE id_product = ' . $idProduct
        );

        $now = date('Y-m-d H:i:s');

        if ($existing) {
            return (bool) Db::getInstance()->update(
                self::TABLE_PRODUCTS,
                ['score' => $score, 'date_upd' => $now],
                'id_product = ' . $idProduct
            );
        }

        return (bool) Db::getInstance()->insert(self::TABLE_PRODUCTS, [
            'id_product' => $idProduct,
            'score' => $score,
            'date_add' => $now,
            'date_upd' => $now,
        ]);
    }

    /** @param int[] $ids */
    public function deleteProducts(array $ids): bool
    {
        if ($ids === []) {
            return true;
        }

        return (bool) Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE_PRODUCTS . '` WHERE id_product IN (' . implode(',', $ids) . ')'
        );
    }

    /** @param int[] $ids */
    public function deletePhrases(array $ids): bool
    {
        if ($ids === []) {
            return true;
        }

        return (bool) Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE_PHRASES . '` WHERE id_phrase IN (' . implode(',', $ids) . ')'
        );
    }

    /**
     * Drzewo kategorii spłaszczone do listy z wcięciami wg głębokości:
     * filtr w panelu jest zwykłym `qc-select`, nie JS-owym drzewkiem.
     *
     * @return array<int,array{id:int,name:string,depth:int}>
     */
    public function categoriesFlat(int $idLang, int $idShop): array
    {
        $rows = Db::getInstance()->executeS('
            SELECT c.id_category, c.id_parent, c.level_depth, cl.name
            FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON cl.id_category = c.id_category AND cl.id_lang = ' . $idLang . ' AND cl.id_shop = ' . $idShop . '
            INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                ON cs.id_category = c.id_category AND cs.id_shop = ' . $idShop . '
            WHERE c.level_depth >= 2 AND c.active = 1
            ORDER BY c.nleft
        ');

        if (!$rows) {
            return [];
        }

        $minDepth = min(array_map(static fn (array $r): int => (int) $r['level_depth'], $rows));

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id_category'],
                'name' => (string) $r['name'],
                'depth' => (int) $r['level_depth'] - $minDepth,
            ],
            $rows
        );
    }
}
